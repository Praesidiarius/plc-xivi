<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Record;

use App\Tenant\Attachment\AttachmentRefused;
use App\Tenant\Attachment\AttachmentStore;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\AttachmentLimit;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\HoldsAFile;
use Xivi\Core\Field\StoredFile;
use Xivi\Core\Form\RecordFileType;

/**
 * Files off a record form, onto the filesystem, before anything is validated
 * (XIV-115).
 *
 * ## Why this is a step of its own rather than something the field type does
 *
 * A field type's `toStorage()` is called speculatively: by the validator, by the
 * query compiler when somebody types in a filter box, and by §7.2's dry run over
 * a whole column. A method that wrote bytes there would write them for questions
 * nobody answered yes to. So the bytes are written **once**, here, at the one
 * moment somebody has actually chosen a file, and what reaches the field type is
 * the value {@see AttachmentStore} handed back.
 *
 * It runs *before* the form is submitted, which is what makes a required file
 * field work: by the time `RecordValidator` looks at the record, the value is
 * there like any other.
 *
 * ## What it costs, said plainly: a refused save can leave a file behind
 *
 * The bytes are on disk before the record is known to be valid, and a save that
 * is then refused for an unrelated reason leaves a file no record claims. That
 * is deliberate rather than overlooked, and the alternatives are worse: holding
 * a 10 MB upload in memory across validation is what §5.30 refuses outright, and
 * writing it to a second staging area and moving it afterwards is two places for
 * a file to be instead of one, plus its own orphans.
 *
 * **This is the thing the drift check exists for** (§4.7). "Files no record
 * claims" is a normal, expected, small residue of people changing their minds,
 * which is exactly why that check reports rather than deletes and why it is not
 * a deploy gate: a release blocked by somebody's abandoned upload is a check
 * that gets ignored.
 *
 * ## Where an upload actually arrives from
 *
 * The record form is a Live Component and posts nothing (§8.3): its values travel
 * as JSON on an action. Files are the exception the library makes, and they ride
 * in the same `FormData` as ordinary uploads, named after the input they came
 * from. So this reads `$request->files` rather than the component's values, and
 * writes what it stored back into those values, in the hidden control
 * {@see RecordFileType} keeps them in.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordUploads
{
    /**
     * How much of a file is looked at to decide what it is.
     *
     * Four kilobytes, which is what every magic-number table in the world fits
     * in several times over: `%PDF-`, `PK\x03\x04`, `\x89PNG` and the rest are all
     * in the first line. The number is here rather than borrowed from
     * {@see AttachmentLimit::CHUNK_BYTES} because the two answer different
     * questions, and tying them together would make a change to the copy buffer
     * silently change what a file is recognised as.
     */
    private const int SAMPLE_BYTES = 4096;

    public function __construct(
        private AttachmentStore $store,
        private FieldTypeRegistry $fieldTypes,
    ) {
    }

    /**
     * Every file on this request, stored, and the form's values with the results
     * written into them.
     *
     * Only the shape's own fields: a collection row cannot hold a file, which is
     * refused in the editor and in the installer rather than half-supported here
     * ({@see HoldsAFile}).
     *
     * @param array<string, mixed> $values the component's `module_record` values
     *
     * @return array{0: array<string, mixed>, 1: array<string, StoredFile>} the values, and what was stored, by field key
     *
     * @throws AttachmentRefused
     */
    public function take(ShapeDefinition $shape, Request $request, string $formName, array $values): array
    {
        $uploads = $this->uploadsOn($request, $formName);

        if ($uploads === []) {
            return [$values, []];
        }

        /** @var array<string, mixed> $fields */
        $fields = \is_array($values['fields'] ?? null) ? $values['fields'] : [];
        $stored = [];

        foreach ($shape->getFields() as $field) {
            $key = $field->getKey();
            $upload = $uploads[$key] ?? null;

            if (!$upload instanceof UploadedFile) {
                continue;
            }

            if (!$this->fieldTypes->get($field->getType()) instanceof HoldsAFile) {
                // A file arriving under the name of a field that does not hold
                // one. Nothing draws such an input, so this is a hand-built
                // request, and the answer is to ignore it rather than to store
                // bytes no record will ever name.
                continue;
            }

            $file = $this->storeOne($upload);
            $stored[$key] = $file;

            /** @var array<string, mixed> $control */
            $control = \is_array($fields[$key] ?? null) ? $fields[$key] : [];
            $control[RecordFileType::STORED] = $file->stored();
            // **A new upload beats a tick**, settled here so the form's data
            // mapper has one rule rather than two. Somebody who ticked "remove"
            // and then chose a file has changed their mind in the direction of
            // the file.
            $control[RecordFileType::REMOVE] = false;

            $fields[$key] = $control;
        }

        $values['fields'] = $fields;

        return [$values, $stored];
    }

    /**
     * One upload, checked twice and streamed.
     *
     * **Twice, because the two answers are different claims.** `getSize()` is
     * what the request said, and checking it first is what stops 200 MB being
     * copied onto a disk before being refused; what actually arrived is counted
     * by the store after it is written, and that is the check that cannot be
     * lied to. Both name the same limit, which is the ticket's requirement that
     * a rejected upload says why with the real number in it.
     *
     * **The media type is read from the bytes and never from the browser's
     * claim.** `getClientMimeType()` is whatever a client chose to send and it
     * becomes a `Content-Type` header on the way back out; §8.6 took the same
     * decision about a logo, deciding the format by decoding the bytes.
     *
     * It is read from a **sample** rather than through
     * {@see UploadedFile::getMimeType()}, and that is not a micro-optimisation:
     * `finfo` given a path reads as much of the file as it likes, which measured
     * at 14.6 MB of PHP memory on a 10 MB upload and is precisely the thing §5.30
     * says never happens. `finfo` given the first few kilobytes answers the same
     * question from what a media type is actually decided by, which is a magic
     * number at the front. A file whose type cannot be told from its first bytes
     * is offered back as {@see StoredFile::FALLBACK_TYPE}, which is the honest
     * answer and the safe one.
     *
     * @throws AttachmentRefused
     */
    private function storeOne(UploadedFile $upload): StoredFile
    {
        if (!$upload->isValid()) {
            // PHP's own upload errors: a truncated body, or ini limits that have
            // fallen below this application's. Not the same failure as a file
            // over the limit, and deliberately not reported as one.
            throw AttachmentRefused::didNotArrive($upload->getClientOriginalName());
        }

        $size = $upload->getSize();

        if (\is_int($size) && $size > AttachmentLimit::MAX_BYTES) {
            throw AttachmentRefused::tooLarge($upload->getClientOriginalName(), $size);
        }

        $stream = fopen($upload->getPathname(), 'rb');

        if ($stream === false) {
            throw AttachmentRefused::didNotArrive($upload->getClientOriginalName());
        }

        try {
            return $this->store->store($stream, self::nameOf($upload), self::typeOf($stream));
        } finally {
            // Flysystem closes what it is given, and closing a closed stream is
            // not an error worth a branch. What matters is that nothing here
            // holds a handle open into the next request under FrankenPHP.
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * What this file is, decided by looking at it.
     *
     * The stream is left where it was found: the store reads it from the
     * beginning, and a sample taken without rewinding would silently truncate
     * every upload by the length of the sample. See {@see self::storeOne()} for
     * why the sample exists at all.
     *
     * @param resource $stream
     */
    private static function typeOf($stream): string
    {
        $sample = (string) fread($stream, self::SAMPLE_BYTES);
        rewind($stream);

        $detected = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($sample);

        return \is_string($detected) && $detected !== '' ? $detected : StoredFile::FALLBACK_TYPE;
    }

    /**
     * What the file will be called, which is not what it is stored as.
     *
     * The name travels beside the token and is only ever printed or offered as a
     * download name, so the path separators are the whole of what has to go: a
     * file called `../../passwd` is a perfectly legal thing to upload and a
     * terrible thing to hand to a `Content-Disposition` header.
     * {@see UploadedFile::getClientOriginalName()}
     * already strips the directory part, and this is the second pair of hands on
     * the same job rather than a substitute for it.
     */
    private static function nameOf(UploadedFile $upload): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], ' ', $upload->getClientOriginalName()));

        return $name === '' ? StoredFile::FALLBACK_NAME : mb_substr($name, 0, 200);
    }

    /**
     * The files this request carries for the record's own fields.
     *
     * Shaped by the input names {@see RecordFileType} draws:
     * `module_record[fields][<key>][upload]`. Anything else on the request is
     * somebody else's business.
     *
     * @return array<string, mixed>
     */
    private function uploadsOn(Request $request, string $formName): array
    {
        $all = $request->files->all();
        $fields = $all[$formName]['fields'] ?? null;

        if (!\is_array($fields)) {
            return [];
        }

        $uploads = [];

        foreach ($fields as $key => $control) {
            if (\is_array($control) && isset($control[RecordFileType::UPLOAD])) {
                $uploads[(string) $key] = $control[RecordFileType::UPLOAD];
            }
        }

        return $uploads;
    }
}

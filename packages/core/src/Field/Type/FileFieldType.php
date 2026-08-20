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

namespace Xivi\Core\Field\Type;

use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\HoldsAFile;
use Xivi\Core\Field\StoredFile;
use Xivi\Core\Form\RecordFileType;
use Xivi\Core\Query\Operator;
use Xivi\Core\Validation\ValidStoredFile;

/**
 * A file on a record: the contract, the signed delivery note, the datasheet
 * (XIV-115).
 *
 * The metadata is in the tenant's database and the bytes are on a filesystem,
 * which is §5.30's decision and is argued there rather than here. What this
 * class is responsible for is the half the engine sees: one string in the JSONB
 * document, read and written like any other value, with no idea that a
 * filesystem exists.
 *
 * **This type touches no bytes.** It never opens a file, never writes one and
 * never deletes one, and that is the whole reason it can afford to be an
 * ordinary field type. Everything to do with bytes goes through
 * {@see \App\Tenant\Attachment\AttachmentStore}, the one seam that resolves the
 * tenant and therefore the directory. The rule that matters for whoever changes
 * this file next: `toStorage()` is called speculatively, by the validator, by
 * the query compiler on a filter box, by §7.2's dry run over a whole column. A
 * method here that wrote bytes would write them for questions nobody answered
 * yes to.
 *
 * ## What it stores, and why it is one string
 *
 * {@see StoredFile} carries the argument. Briefly: a scalar is what the export,
 * the history diff, the importer and `data ->> 'key'` already understand, and
 * §5.27 made the same choice for a period for the same reasons.
 *
 * ## One file, not several
 *
 * XIV-113's shape, applied: several is a *type* rather than an option here, and
 * {@see HoldsAFile} says why in more detail. Nothing in this class would survive
 * a `multiple` option unchanged, which is the test §5.21 proposes for whether
 * something is an option at all.
 *
 * The three questions XIV-113 refused arrive here differently and are answered
 * the same way. **`unique`** is not refused, because it is not offered: it is a
 * checkbox in the editor for a value somebody types, and two records holding the
 * same file is two uploads with two tokens, so the flag would compare tokens and
 * always pass. **Sorting** is refused by the compiler along with filtering, on
 * §5.3's rule that the stored form is not the value: sorting by this column
 * would sort by a random hex token. **"Any of these"** does not arise, since
 * there is nothing here to have several of.
 *
 * ## Not in, and each is a ticket rather than an omission
 *
 * Virus scanning, image resizing and thumbnails, preview rendering, versioning
 * a file and de-duplicating identical uploads. The last two are the ones that
 * look free and are not: keeping the previous bytes when a file is replaced
 * needs somewhere to keep the history of them, and storing one copy of two
 * identical uploads makes deleting a record a question about who else is
 * pointing at the bytes.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FileFieldType implements HoldsAFile
{
    /** What a generated file is called, before the record number. */
    private const string SAMPLED = 'Document';

    public function key(): string
    {
        return 'file';
    }

    public function label(): string
    {
        return 'File';
    }

    /**
     * Text, and text this installation wrote.
     *
     * The size ceiling is deliberately **not** here. A constraint runs on a value
     * that has already been stored, so refusing 12 MB at this point would refuse
     * it after the bytes were on disk; the limit belongs where the upload is
     * taken and is enforced twice there, once against what the browser declared
     * and once against what actually arrived (see
     * {@see \App\Record\RecordUploads}).
     */
    public function constraints(FieldDefinition $field): array
    {
        return [new Assert\Type('string'), new ValidStoredFile()];
    }

    /**
     * Whatever was submitted, imported or handed over, as the stored string.
     *
     * A {@see StoredFile} comes from the upload intake, which is the only thing
     * in this application that makes one. A string comes from a record read back
     * out of storage, from a spreadsheet cell and from the form's own hidden
     * control, which carries the value across a re-render.
     *
     * **A string that is not a stored file is kept rather than dropped**, which
     * is the load-bearing line and is {@see MultiReferenceFieldType}'s decision
     * inherited. Dropping it would turn a spreadsheet column full of filenames
     * into records that quietly hold nothing, and §5.6's whole promise is that
     * nothing goes in silently. Kept, it fails {@see ValidStoredFile} with the
     * row and the column named. That is also why the return type here is `mixed`
     * rather than `?string`: what comes back for a value this type cannot read is
     * the value itself, unchanged, so that the constraint can quote it.
     */
    public function toStorage(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof StoredFile) {
            return $value->stored();
        }

        if (!\is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function formType(): string
    {
        return RecordFileType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        return [];
    }

    /**
     * The name the person who uploaded it gave it.
     *
     * Not the token, which names nothing to anybody, and not the size, which
     * would put "1.2 MB" into a list cell, a document marker and a record's own
     * title. `display()` has all three of those callers (§5.29), and a document
     * saying "Contract 2026.pdf (1.2 MB)" is a document with a file browser
     * printed on it. The size is on the record page, where the link is, because
     * that is where somebody decides whether to download it.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        $file = $this->fileOf($value, $field);

        return $file === null ? '' : $file->name;
    }

    /**
     * Filled or empty, and nothing else.
     *
     * "Has an attachment" and "has none" are real questions a list gets asked.
     * **`contains` is deliberately not offered**, and the reason is what the
     * column actually holds: `data ->> 'contract'` is a token, a size, a media
     * type and a name in one string, so a filter for "pdf" would match a file
     * called `notes.txt` whose media type happens to be `application/pdf`, and a
     * filter for a run of digits would match somebody's token. §7.3 has no way
     * to say "the fourth part of this value", and a comparison that answers a
     * question nobody asked is worse than one that is not there.
     */
    public function operators(): array
    {
        return [Operator::IsEmpty, Operator::IsNotEmpty];
    }

    /** Already text in the payload, and only ever compared against nothing. */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }

    /**
     * A file that reads plausibly and has no bytes behind it (§5.17).
     *
     * **The uncomfortable one, and it is a trade rather than an oversight.** A
     * sample here can be one of two things and cannot be both: metadata naming
     * bytes nobody wrote, or nothing at all. Nothing at all was the first
     * answer and it is wrong for a reason bigger than this type,
     * {@see \App\Tests\Functional\Engine\FieldTypeRoundTripTest}: that test
     * walks the *registry* rather than a list, on the argument that a type
     * missing from it is a type whose round trip nobody checks, and a type
     * declining to sample is a type it cannot walk. Buying an exemption there
     * would cost more than what is paid here.
     *
     * So what is paid here, said plainly: a generated record's file **cannot be
     * downloaded**, and `tenant:files:check` reports one finding per generated
     * record (§4.7). Both are confined to development, because the demo commands
     * are registered only in `dev` and `test` (§4.1) for the neighbouring reason
     * that generating fiction into a customer's database is dangerous. Nothing an
     * operator runs against a real installation produces one of these.
     *
     * Writing real bytes instead was the other candidate and is worse in the
     * place that counts: a type that wrote files would need the storage seam, and
     * §5.30's whole isolation argument rests on that seam having exactly one
     * caller shape. `tenant:reset --records=200` would also put two hundred files
     * on disk per file field, for pages that would then be demonstrating a PDF
     * viewer.
     */
    public function sample(FieldDefinition $field, int $sequence): ?string
    {
        // A fifth of optional fields left empty. Real records have holes in them,
        // and a list where every row carries an attachment hides what the page
        // looks like when most of them do not.
        if (!$field->isRequired() && mt_rand(1, 5) === 1) {
            return null;
        }

        return (new StoredFile(
            bin2hex(random_bytes(StoredFile::TOKEN_LENGTH / 2)),
            // Something a scanned document could weigh, varied by the sequence so
            // a list of them does not read as a copied row.
            180_000 + ($sequence * 6_197) % 900_000,
            'application/pdf',
            sprintf('%s %d.pdf', self::SAMPLED, $sequence),
        ))->stored();
    }

    /**
     * Half a row. A file is a name and a size beside a button, which is the same
     * amount of room a link to a record takes, and two attachments side by side
     * read better than one stretched across a form.
     */
    public function defaultWidth(): int
    {
        return 6;
    }

    public function fileOf(mixed $value, FieldDefinition $field): ?StoredFile
    {
        return StoredFile::parse($value);
    }
}

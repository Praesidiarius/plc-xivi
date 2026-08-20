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

namespace App\Tenant\Attachment;

use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenancy\TenantContext;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Xivi\Core\Field\AttachmentLimit;
use Xivi\Core\Field\StoredFile;

/**
 * The one place in this application that touches a record's bytes (XIV-115).
 *
 * ## Why there is exactly one, and why it resolves the tenant itself
 *
 * §4's isolation between customers is **structural**: a forgotten
 * `WHERE tenant_id = ?` cannot leak anybody's records, because the connection
 * the query runs on cannot reach another customer's database. A directory is not
 * that boundary. Prefixing a path with the tenant is the application being
 * careful, and being careful is precisely what §4 refuses to rely on.
 *
 * The mitigation has to be structural in the one way that is available here, so
 * it is this: **there is one class, it resolves the tenant itself, and no method
 * on it takes a path or a tenant.** A caller cannot ask for another customer's
 * file because there is no parameter in which to name one. What a caller may
 * hand over is a {@see StoredFile}, whose token is 32 hex characters and is
 * turned into a path *here*; a token that names a file another tenant uploaded
 * resolves, under this tenant's directory, to a path that does not exist.
 *
 * That is the same move as `TENANT_DSN_TEMPLATE` deriving a database name and
 * `bin/lib/stack-env.sh` deriving a checkout's identity. Nothing is written down
 * twice, so nothing can disagree. `deptrac` is what keeps it true: the
 * `Attachments` layer is the only one that may depend on `League\Flysystem`, so
 * a class elsewhere that injected a filesystem would fail the build rather than
 * a review.
 *
 * ## The directory is the tenant's database name
 *
 * Not the slug. §4.1 already argues this for `tenant:deprovision`, which resolves
 * the database and the role out of the stored DSN and never from the slug,
 * because the DSN is where a tenant's identity actually lives; deriving the
 * directory from the same string is what makes "the files went with the database"
 * true by construction rather than by both being remembered.
 *
 * A database name that is not a plain identifier is **refused** rather than
 * cleaned up: sanitising two different names into one directory is how two
 * customers come to share a folder, which is the one failure this whole class
 * exists to make impossible.
 *
 * ## Streams, never strings
 *
 * Nothing here reads a file into memory, on the way in or on the way out, and
 * that is a property of the code rather than a hope about how big customers'
 * files are. {@see AttachmentLimit::CHUNK_BYTES} is the buffer everything copies
 * through, so a 10 MB PDF costs the same memory as a 4 KB one; the measurement
 * is in `tests/Measurement/AttachmentMemoryTest.php`.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class AttachmentStore
{
    /**
     * How a tenant's directory has to be spelled for this to use it.
     *
     * Postgres will happily hold a database name with a slash or a dot in it if
     * somebody quotes it, and this application never creates one: provisioning
     * builds the name out of a slug, and a slug is letters, digits and
     * underscores. So this is not a sanitiser, it is an assertion about what
     * provisioning produces, and a name that fails it is a tenant nothing may
     * store files for until somebody looks at why.
     */
    private const string DIRECTORY = '/^[A-Za-z0-9_]{1,63}$/';

    public function __construct(
        private FilesystemOperator $attachmentsStorage,
        private TenantContext $context,
        private TenantDsnParser $dsnParser,
    ) {
    }

    /**
     * Bytes in, and the value a record can hold in return.
     *
     * The stream is read once and copied through in chunks, so what this costs in
     * memory does not depend on the size of the file. The size is counted while
     * copying rather than taken from the caller: what a browser declares in a
     * multipart header is a claim, and the ceiling has to be enforced against
     * what actually arrived. A stream that turns out to be too long is deleted
     * before this returns, because the alternative is a refusal that leaves the
     * file it refused on disk.
     *
     * @param resource $stream
     *
     * @throws AttachmentRefused when the bytes exceed {@see AttachmentLimit::MAX_BYTES},
     *                           or the filesystem will not take them
     */
    public function store($stream, string $name, string $contentType): StoredFile
    {
        $token = bin2hex(random_bytes(StoredFile::TOKEN_LENGTH / 2));
        $path = $this->pathOf($token);

        try {
            $this->attachmentsStorage->writeStream($path, $stream);
        } catch (FilesystemException $e) {
            throw AttachmentRefused::couldNotBeWritten($name, $e);
        }

        try {
            $size = $this->attachmentsStorage->fileSize($path);
        } catch (FilesystemException $e) {
            throw AttachmentRefused::couldNotBeWritten($name, $e);
        }

        if ($size > AttachmentLimit::MAX_BYTES) {
            // **Asked of the filesystem rather than counted on the way past.**
            // Flysystem's own copy loop is what moves the bytes, and wrapping it
            // to count them would mean reimplementing the copy; asking afterwards
            // is one stat call and cannot be wrong about what was written.
            $this->deleteAt($path);

            throw AttachmentRefused::tooLarge($name, $size);
        }

        return new StoredFile($token, $size, $contentType, $name);
    }

    /**
     * The bytes, as something to copy to a client.
     *
     * @return resource
     *
     * @throws AttachmentRefused when this tenant has no such file
     */
    public function readStream(StoredFile $file)
    {
        try {
            return $this->attachmentsStorage->readStream($this->pathOf($file->token));
        } catch (FilesystemException $e) {
            throw AttachmentRefused::missing($file, $e);
        }
    }

    /**
     * Whether the bytes this value names are actually there.
     *
     * The drift check's whole question (§4.7), and the download route's guard: a
     * record can name a file that a restore, a half-finished migration or
     * somebody with a shell has removed, and the honest answer to a download of
     * one is a 404 rather than a stack trace out of a stream copy.
     */
    public function has(StoredFile $file): bool
    {
        try {
            return $this->attachmentsStorage->fileExists($this->pathOf($file->token));
        } catch (FilesystemException) {
            // An unreadable directory is not the same as an absent file, and a
            // page drawing a link is in no position to tell anybody either way.
            // The check command reports the difference; this answers the question
            // that was asked.
            return false;
        }
    }

    /**
     * Take a file off the installation.
     *
     * Called when a record's file is replaced or removed, and by nothing else:
     * deleting a *record* leaves its file alone, because a record is
     * soft-deleted (§5) and undeleting one that had lost its bytes would be
     * worse than keeping them.
     */
    public function delete(StoredFile $file): void
    {
        $this->deleteAt($this->pathOf($file->token));
    }

    /**
     * Every token this tenant has bytes for, and how many bytes each is.
     *
     * The other half of the drift check: the records say what they claim, and
     * this says what is there. A generator rather than a list, because the answer
     * is proportional to how many files a customer has and the check only ever
     * looks at one at a time.
     *
     * @return \Generator<string, int>
     */
    public function tokens(): \Generator
    {
        $home = $this->home();

        try {
            $listing = $this->attachmentsStorage->listContents($home, true);

            foreach ($listing as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                $token = basename($item->path());

                // **A file this application did not write comes back under its
                // path rather than being skipped**, which is what lets the check
                // command report it as a finding: something in a customer's
                // directory that is not a token is either a restore gone
                // sideways or somebody's shell, and both are worth a sentence.
                // Its size is still counted, because deprovision's confirmation
                // is about what will be deleted and this will be.
                yield (StoredFile::isToken($token) ? $token : $item->path()) => $this->attachmentsStorage->fileSize($item->path());
            }
        } catch (FilesystemException $e) {
            throw AttachmentRefused::couldNotBeRead($home, $e);
        }
    }

    /**
     * How much this tenant is holding, as one pass over the directory.
     *
     * What `tenant:deprovision` names in its confirmation beside the record count
     * (§4.1). Counted rather than remembered: a number kept in a column would be
     * a second thing that can disagree with the directory, which is the shape of
     * bug the check command further along exists to find.
     */
    public function usage(): AttachmentUsage
    {
        $files = 0;
        $bytes = 0;

        foreach ($this->tokens() as $size) {
            ++$files;
            $bytes += $size;
        }

        return new AttachmentUsage($files, $bytes);
    }

    /**
     * Everything this tenant has, gone, and what went (§4.1).
     *
     * The half of `tenant:deprovision` that is not SQL. It answers with what it
     * removed rather than with nothing, because the command's promise is that it
     * says what happened, and "and the files" is not a sentence anybody can check.
     *
     * Counted before the directory is removed rather than after, for the obvious
     * reason and one less obvious one: `deleteDirectory()` on a directory that is
     * not there is a success in Flysystem, so a count taken afterwards would
     * always be zero and would look exactly like a deprovision that worked.
     */
    public function removeEverything(): AttachmentUsage
    {
        $usage = $this->usage();

        try {
            $this->attachmentsStorage->deleteDirectory($this->home());
        } catch (FilesystemException $e) {
            throw AttachmentRefused::couldNotBeRemoved($this->home(), $e);
        }

        return $usage;
    }

    /**
     * Where this tenant's files are, derived and never passed in.
     *
     * The class docblock is the argument. What is worth noticing at the call
     * sites is that this method takes no parameters at all: the tenant comes from
     * {@see TenantContext}, which is the same object that decides which database
     * a query runs against, so a file and a record are reached through one
     * resolution rather than two that could disagree.
     */
    private function home(): string
    {
        $tenant = $this->context->getTenant();
        $database = $this->dsnParser->databaseName($tenant->getDatabaseDsn());

        if (preg_match(self::DIRECTORY, $database) !== 1) {
            throw AttachmentRefused::unusableDirectory($tenant->getSlug(), $database);
        }

        return $database;
    }

    /**
     * One token, as a path inside this tenant's directory.
     *
     * **Sharded on the first two characters**, so a customer with fifty thousand
     * attachments has two hundred and fifty six directories of two hundred rather
     * than one directory of fifty thousand. `ext4` copes with the flat version and
     * every tool a person would reach for at 2am does not: `ls` sorts, and `tar`
     * and `rsync` both slow down on a single enormous directory.
     *
     * The token is checked here rather than trusted, even though the only thing
     * that makes one is {@see self::store()}. It arrives out of a JSONB document,
     * which means it arrives from an import, a hand-edited record or a restore,
     * and `../` in a path segment is the oldest bug there is. A refusal rather
     * than an escape: this application wrote a hex token or it did not.
     */
    private function pathOf(string $token): string
    {
        if (!StoredFile::isToken($token)) {
            throw AttachmentRefused::notAToken($token);
        }

        return sprintf('%s/%s/%s', $this->home(), substr($token, 0, 2), $token);
    }

    private function deleteAt(string $path): void
    {
        try {
            $this->attachmentsStorage->delete($path);
        } catch (FilesystemException $e) {
            throw AttachmentRefused::couldNotBeRemoved($path, $e);
        }
    }
}

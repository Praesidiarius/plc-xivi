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

use Symfony\Component\Translation\TranslatableMessage;
use Xivi\Core\Field\AttachmentLimit;
use Xivi\Core\Field\StoredFile;

/**
 * A file could not be stored, read or removed (XIV-115).
 *
 * Carries a translatable message beside the developer sentence, like
 * {@see \Xivi\Core\Document\DocumentFailed}, and for the same reason: a file
 * that is too large is something a person reads on the form they are filling in,
 * in their own language, and it is not a 500.
 *
 * **The size in the message is the real limit and comes from the constant that
 * enforces it.** A ticket requirement, and the one thing this class is really
 * for: an upload refused with "the file is too big" and no number is a person
 * guessing, and an upload refused with a number that came from a second copy of
 * the limit is worse, because the number will eventually be wrong.
 *
 * The cases that are *not* about a person get a translatable message anyway, and
 * a deliberately vague one: a filesystem that will not write is somebody's disk
 * being full or a volume that is not mounted (§5.30), and neither is fixable by
 * whoever was uploading a contract. The words say "not saved, tell whoever runs
 * this" rather than naming a path a customer has no business knowing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class AttachmentRefused extends \RuntimeException
{
    private function __construct(
        private readonly TranslatableMessage $translatable,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Larger than an attachment may be, with the limit named.
     *
     * The bytes are already gone by the time this is thrown: the store deletes
     * what it refused rather than leaving it for the drift check to find.
     */
    public static function tooLarge(string $name, int $size): self
    {
        return new self(
            new TranslatableMessage(
                'file.problem.too_large',
                [
                    '%file%' => $name,
                    // Both spelled the way the form spells them, through the
                    // constant's own formatter: "10 MB" beside the input and
                    // "10 MB" in the refusal, or the sentence is arguing with the
                    // page it appeared on.
                    '%max%' => AttachmentLimit::shown(AttachmentLimit::MAX_BYTES),
                    '%size%' => AttachmentLimit::shown($size),
                ],
                'xivi',
            ),
            sprintf('"%s" is %d bytes, over the %d byte limit.', $name, $size, AttachmentLimit::MAX_BYTES),
        );
    }

    /**
     * The upload never arrived whole.
     *
     * PHP's own upload errors, which are not the application's limit and must not
     * be reported as it: a partial upload is a connection that dropped, and
     * `UPLOAD_ERR_INI_SIZE` means the ini values have fallen *below*
     * {@see AttachmentLimit::MAX_BYTES}, which is a misconfiguration of this
     * installation rather than a mistake by the person uploading. Both are said
     * as "it did not arrive", because neither is something they can fix by
     * choosing a different file.
     */
    public static function didNotArrive(string $name): self
    {
        return new self(
            new TranslatableMessage('file.problem.did_not_arrive', ['%file%' => $name], 'xivi'),
            sprintf('"%s" did not arrive intact.', $name),
        );
    }

    public static function couldNotBeWritten(string $name, \Throwable $previous): self
    {
        return new self(
            new TranslatableMessage('file.problem.not_saved', ['%file%' => $name], 'xivi'),
            sprintf('"%s" could not be written: %s', $name, $previous->getMessage()),
            $previous,
        );
    }

    public static function couldNotBeRead(string $where, \Throwable $previous): self
    {
        return new self(
            new TranslatableMessage('file.problem.not_saved', ['%file%' => $where], 'xivi'),
            sprintf('The attachments in "%s" could not be listed: %s', $where, $previous->getMessage()),
            $previous,
        );
    }

    public static function couldNotBeRemoved(string $path, \Throwable $previous): self
    {
        return new self(
            new TranslatableMessage('file.problem.not_saved', ['%file%' => $path], 'xivi'),
            sprintf('"%s" could not be removed: %s', $path, $previous->getMessage()),
            $previous,
        );
    }

    /**
     * A record names a file this tenant does not have.
     *
     * Thrown by the store and turned into a **404** by the download route, which
     * is §8.4's rule applied one noun along: a record somebody may not see and a
     * file that is not there both answer "there is nothing at this address", and
     * the second one has the additional property that the drift check is what
     * finds out why (§4.7).
     */
    public static function missing(StoredFile $file, \Throwable $previous): self
    {
        return new self(
            new TranslatableMessage('file.problem.missing', ['%file%' => $file->name], 'xivi'),
            sprintf('"%s" (%s) is not in this tenant\'s storage: %s', $file->name, $file->token, $previous->getMessage()),
            $previous,
        );
    }

    /** A token that is not one, out of a record. See `AttachmentStore::pathOf()`. */
    public static function notAToken(string $token): self
    {
        return new self(
            new TranslatableMessage('file.problem.missing', ['%file%' => ''], 'xivi'),
            sprintf('"%s" is not a file token this installation could have written.', mb_substr($token, 0, 60)),
        );
    }

    /** A tenant whose database name cannot be a directory. See `AttachmentStore::home()`. */
    public static function unusableDirectory(string $slug, string $database): self
    {
        return new self(
            new TranslatableMessage('file.problem.not_saved', ['%file%' => ''], 'xivi'),
            sprintf(
                'Tenant "%s" has database "%s", which is not a name this can make a directory of.',
                $slug,
                $database,
            ),
        );
    }

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }
}

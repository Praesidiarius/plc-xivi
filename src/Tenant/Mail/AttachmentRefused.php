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

namespace App\Tenant\Mail;

use Symfony\Component\Translation\TranslatableMessage;
use Xivi\Core\Document\DocumentFailed;

/**
 * The document that was to go with a mail did not (XIV-40).
 *
 * **A different exception from {@see MailSendFailed}, and the difference is the
 * ticket's second failure side.** This one is thrown before anything is handed
 * to a transport: no message was built, nothing left the building, and there is
 * nothing to put on the record's timeline — the same silence a record with no
 * recipient or an unchosen template already gets, because all three are
 * preconditions that failed rather than sends that went wrong. MailSendFailed is
 * the other side: a complete message was handed over and the transport refused
 * it, which *is* an attempt and is recorded as `email_failed`.
 *
 * Reading the timeline afterwards, the two are told apart by whether there is an
 * entry at all — and where there is one, by whether it names an attachment. An
 * `email_failed` naming a document is a document that was made and a send that
 * failed; there is no entry that means "the document could not be made", because
 * writing one would say a mail was attempted when none was, and §5.14 spent its
 * argument on the verb being true.
 *
 * Two causes, one type, for the reason MailSendFailed gives: the caller does the
 * same thing either way — say so, send nothing — and `getPrevious()` still
 * carries which it was.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class AttachmentRefused extends \RuntimeException
{
    private TranslatableMessage $reason;

    /**
     * Why, in the language of whoever pressed the button (XIV-8).
     *
     * Only the *reason*: the caller wraps it in the sentence that also says
     * nothing was sent, because that half is the same however the document
     * failed and repeating it inside every cause would have it read twice.
     */
    public function reason(): TranslatableMessage
    {
        return $this->reason;
    }

    /**
     * The document could not be made at all — a template that will not fill, or
     * a converter that is down.
     *
     * The document layer's own sentence is passed through rather than replaced.
     * "The PDF converter could not be reached" is both true and actionable, and
     * it is visibly about a document rather than about mail, which is exactly
     * what somebody needs to know to tell this from a send that failed.
     */
    public static function couldNotGenerate(DocumentFailed $failed): self
    {
        $refusal = new self('The document could not be generated: ' . $failed->getMessage(), previous: $failed);
        $refusal->reason = $failed->translatable();

        return $refusal;
    }

    /**
     * It was made, and it is too big to send.
     *
     * Refused here rather than discovered as a bounce hours later by a mail
     * server nobody in this building administers — see {@see DocumentAttachments}
     * for where the number comes from.
     */
    public static function tooLarge(string $filename, int $bytes, int $limit): self
    {
        $refusal = new self(sprintf(
            'The attachment "%s" is %d bytes and the limit is %d.',
            $filename,
            $bytes,
            $limit,
        ));
        // ICU, so the two numbers are grouped and pointed the way the reader's
        // own country writes them (XIV-47, XIV-50) — a refusal reading "7.0 MB"
        // at a German desk is the small wrongness that whole ticket was about.
        // Megabytes rather than a unit table: everything this refuses is between
        // one and a hundred of them.
        $refusal->reason = new TranslatableMessage('mail.attachment_too_large', [
            'file' => $filename,
            'size' => $bytes / (1024 * 1024),
            'limit' => $limit / (1024 * 1024),
        ], 'messages');

        return $refusal;
    }
}

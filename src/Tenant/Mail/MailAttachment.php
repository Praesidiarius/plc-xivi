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

use Xivi\Core\Document\DocumentFormat;

/**
 * A document that is going out with a mail (XIV-40).
 *
 * Two audiences in one small object, which is why it carries five things rather
 * than three. The **message** wants a filename, some bytes and a MIME type, and
 * nothing else; the **timeline** wants which template the document came from and
 * which format came out of it, because "an invoice was attached" is the half of
 * the entry that the send's own template name cannot supply — the mail was
 * written from *Order confirmation* and what went with it was *Invoice*, and a
 * year later the difference is the whole question.
 *
 * Keeping both here is what lets {@see RecordMailer::send()} take one parameter
 * and answer both. Splitting them would mean handing the mailer a file and its
 * provenance separately, which is two arguments that have to agree and one place
 * they can stop agreeing.
 *
 * **The bytes are held in memory, deliberately.** A generated document is at most
 * a few megabytes by the time {@see DocumentAttachments} has agreed to it, it
 * exists for the length of one request, and the alternative — a temporary file —
 * is a path somebody has to delete on the failure branch too. The generator
 * already pays that cost once, inside itself, where the library it uses forces it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class MailAttachment
{
    public function __construct(
        /** What it is called in the recipient's mail client. */
        public string $filename,
        public string $contents,
        public string $contentType,
        /** The document template's name, for the record's timeline. */
        public string $template,
        public DocumentFormat $format,
    ) {
    }

    /**
     * How big it is, before base64 makes it a third bigger on the wire.
     *
     * Read by the send screen so somebody can see what they are about to put in
     * a customer's inbox, and by nothing that decides anything — the ceiling is
     * {@see DocumentAttachments}' and is applied before this object exists.
     */
    public function bytes(): int
    {
        return \strlen($this->contents);
    }
}

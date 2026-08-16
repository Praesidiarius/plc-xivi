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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Xivi\Core\Document\DocumentFailed;
use Xivi\Core\Document\DocumentFormat;
use Xivi\Core\Document\DocumentGenerator;
use Xivi\Core\Entity\DocumentTemplate;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Record;

/**
 * The invoice that goes with the email (XIV-40).
 *
 * Thin on purpose: {@see DocumentGenerator} already turns a template, a record
 * and a format into bytes, which is the part the ticket calls small. What is
 * here is the two things generating *for a send* has that generating for a
 * download does not.
 *
 * **It generates without announcing.** `DocumentGenerator::contents()` rather
 * than `pdf()` or `docx()`, so no `document_generated` entry appears. One button
 * press is one fact, and the fact is the send; the attachment is recorded as a
 * key on that entry instead (§5.15).
 *
 * **It refuses what is too big to send**, which is the other half.
 *
 * ### The ceiling, and why this number
 *
 * Seven mebibytes of document, by default. The number is chosen against what
 * *receiving* servers accept rather than against what this one can produce,
 * because the failure being prevented happens at the far end: attachments travel
 * base64-encoded, four bytes on the wire for every three, so seven MiB of PDF
 * arrives as a message of roughly nine and a half — comfortably inside the 10 MB
 * that is the most common conservative limit, and the one Postfix ships as its
 * own default. Gmail and Exchange Online will take twenty-five, and choosing
 * against *their* number would mean a document that this installation is happy
 * with and a quarter of the internet bounces.
 *
 * A bounce is the outcome worth paying to avoid. It arrives hours later, at an
 * address that is often nobody's inbox, about a message the person who sent it
 * has stopped thinking about — so the invoice simply does not arrive and nobody
 * finds out. A refusal on the screen, while somebody is still looking at it, is
 * a worse minute and a far better afternoon.
 *
 * **And it is configurable, because the honest answer is that we cannot know.**
 * The authority on what will get through is the relay this deployment actually
 * sends via, and an operator who runs their own knows a number we do not.
 * `XIVI_MAX_ATTACHMENT_BYTES` is that number; the default is for a deployment
 * that has not thought about it, which is most of them.
 *
 * The check is on the document rather than on the assembled message. It is the
 * part that varies by three orders of magnitude — the words of an email are
 * kilobytes, the same shape every time — and a limit somebody can compare
 * against a file size they can see is one they can act on.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DocumentAttachments
{
    public function __construct(
        private DocumentGenerator $generator,
        /**
         * See the class docblock for the number. Bytes rather than megabytes so
         * that nothing has to agree about which megabyte is meant.
         */
        #[Autowire('%env(int:XIVI_MAX_ATTACHMENT_BYTES)%')]
        private int $maxBytes,
    ) {
    }

    /**
     * The document, ready to hang on a message — or nothing sent at all.
     *
     * Both refusals happen here, before a `Mime\Email` exists, which is what
     * makes "a failed generation sends nothing" true by construction rather than
     * by a caller remembering to check in the right order.
     *
     * @throws AttachmentRefused when it cannot be made, or is too big to send
     */
    public function for(
        DocumentTemplate $template,
        ModuleDefinition $module,
        Record $record,
        DocumentFormat $format,
    ): MailAttachment {
        try {
            $contents = $this->generator->contents($template, $module, $record, $format);
        } catch (DocumentFailed $failed) {
            throw AttachmentRefused::couldNotGenerate($failed);
        }

        $filename = DocumentGenerator::filename($template, $record, $format);

        if (\strlen($contents) > $this->maxBytes) {
            throw AttachmentRefused::tooLarge($filename, \strlen($contents), $this->maxBytes);
        }

        return new MailAttachment(
            $filename,
            $contents,
            $format->contentType(),
            $template->getName(),
            $format,
        );
    }
}

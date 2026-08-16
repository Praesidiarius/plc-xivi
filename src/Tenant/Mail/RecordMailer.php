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

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Xivi\Core\Entity\EmailTemplate;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Event\RecordChanged;
use Xivi\Core\Mail\RenderedEmail;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordChanges;

/**
 * One record, one template, one address: the send itself (XIV-39).
 *
 * The join between the two halves the earlier tickets deliberately left apart.
 * Core renders a subject, an HTML document and a text alternative and stops
 * there — building a `Mime\Email` would mean core deciding who a message is from
 * and who it goes to, and it knows neither (§5.13). `TenantMailer` knows the
 * first and refuses to let anybody else answer it (§8.7). What was missing was
 * the second, and this is the one place in the application where the three meet.
 *
 * ### The timeline is written here, and it is written both ways
 *
 * Not in the controller, and that is the load-bearing part of where this class
 * sits. A send has exactly two outcomes and both are facts about the record, so
 * the object that performs the send is the only one that cannot forget to record
 * one of them. Put the history write in the caller and the happy path gets an
 * entry, the `catch` block gets a flash message, and a year later nobody can
 * tell a failed invoice from one that was never sent — which is precisely what
 * §8.7 said must not happen.
 *
 * So the failure is recorded *before* the exception is thrown on, as
 * {@see RecordAction::EmailFailed} rather than as a flag inside an `email_sent`
 * entry: a timeline is read by scanning its verbs, so the difference has to be
 * in the verb.
 *
 * ### The attachment came in through the seam that was left for it
 *
 * XIV-40 needed two things and both were where XIV-39 said they would be:
 * {@see self::messageFor()}, the single place a `Mime\Email` is built, and
 * {@see RecordChanges::forEmail()}, the single named constructor for the entry.
 * So attaching a document is one more argument here and one more key there,
 * rather than a second path through this class or a second event beside the one
 * it writes — which is the whole of §5.15's "one act, not two".
 *
 * What is emphatically *not* here is generating the thing. The document is made
 * and measured by {@see DocumentAttachments} before this method is called, so a
 * generation that fails cannot reach the point where a message exists — and this
 * class keeps having exactly two outcomes to record.
 *
 * ### What is deliberately not here
 *
 * **Retrying.** A send that failed is a failure somebody reads and acts on. §8.7
 * chose synchronous sending because this runtime has nothing running between
 * requests, and a retry with nowhere to queue it is a second slow request nobody
 * asked for.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordMailer
{
    public function __construct(
        private TenantMailer $mailer,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * Who this will appear to come from, without sending anything.
     *
     * The preview's other half: a preview that shows the message and not the
     * sender is not a preview of what will arrive (§8.7).
     *
     * @throws MailSendFailed when this installation has no address to send as
     */
    public function sender(): SenderIdentity
    {
        return $this->mailer->senderIdentity();
    }

    /**
     * Sends it, and puts it on the record's timeline either way.
     *
     * @param string              $recipient  where it goes — the resolved address, or whatever
     *                                        the person pressing the button corrected it to for
     *                                        this one send. It is never written back to the
     *                                        record: sending a mail somewhere once is not a
     *                                        correction to the contact.
     * @param MailAttachment|null $attachment the document going with it (XIV-40), already
     *                                        generated and already inside the size this
     *                                        installation will send. Null is the ordinary
     *                                        case and stays the default, so XIV-39's
     *                                        callers read exactly as they did.
     *
     * @throws MailSendFailed
     */
    public function send(
        ModuleDefinition $module,
        Record $record,
        EmailTemplate $template,
        string $recipient,
        RenderedEmail $rendered,
        ?MailAttachment $attachment = null,
    ): void {
        $changes = RecordChanges::forEmail(
            $template->getName(),
            $recipient,
            $rendered->subject,
            // Named before the send rather than after it, so the entry says the
            // same thing whichever of the two outcomes it ends up describing: an
            // `email_failed` that names its attachment is a document that was
            // made and a transport that refused, which is a different afternoon
            // from a document that could not be made at all.
            $attachment === null
                ? null
                : ['template' => $attachment->template, 'format' => $attachment->format->value],
        );

        try {
            $this->mailer->send(self::messageFor($recipient, $rendered, $attachment));
        } catch (MailSendFailed $failed) {
            $this->record($module, $record, RecordAction::EmailFailed, $changes);

            // Thrown on rather than reported: the person who pressed the button
            // is owed the news, and the controller is what can tell them.
            throw $failed;
        } catch (\InvalidArgumentException $malformed) {
            // An address the resolver approved and something else rejected —
            // hand-edited in the form, most likely. Still an attempt, so still
            // an entry, and still the caller's one exception type to catch.
            $failure = MailSendFailed::because($malformed);
            $this->record($module, $record, RecordAction::EmailFailed, $changes);

            throw $failure;
        }

        $this->record($module, $record, RecordAction::EmailSent, $changes);
    }

    /**
     * The message, as the only place in the application one is built.
     *
     * No `From` and no `Reply-To`: TenantMailer overwrites both on the way out,
     * on purpose, because whose mail this is, is not a call site's decision
     * (§8.7). Setting them here would be writing something that is about to be
     * thrown away and reading, to the next person, as though it mattered.
     *
     * Both bodies, always. The Markdown source *is* the text alternative
     * (§5.13), so there is one here without anything having stripped tags out of
     * the HTML to invent it.
     *
     * And the document, where there is one (XIV-40) — the seam this method was
     * named as. `attach()` rather than `embed()`: an invoice is a file somebody
     * saves, not an image the message is laid out around, and inline parts are
     * what a mail client shows in the body.
     */
    private static function messageFor(
        string $recipient,
        RenderedEmail $rendered,
        ?MailAttachment $attachment,
    ): Email {
        $email = new Email()
            ->to(new Address($recipient))
            ->subject($rendered->subject)
            ->text($rendered->text)
            ->html($rendered->html);

        return $attachment === null
            ? $email
            : $email->attach($attachment->contents, $attachment->filename, $attachment->contentType);
    }

    /**
     * The same `RecordChanged` a change dispatches, for the same reason the
     * document generator uses it (§5.2): one answer to "who did this, and when",
     * and one listener that knows how to write it down.
     */
    private function record(
        ModuleDefinition $module,
        Record $record,
        RecordAction $action,
        RecordChanges $changes,
    ): void {
        $this->events->dispatch(new RecordChanged(
            $module,
            $record,
            $action,
            $changes,
            new \DateTimeImmutable(),
        ));
    }
}

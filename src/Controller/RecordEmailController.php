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

namespace App\Controller;

use App\Tenant\Mail\AttachmentRefused;
use App\Tenant\Mail\DocumentAttachments;
use App\Tenant\Mail\MailAttachment;
use App\Tenant\Mail\MailSendFailed;
use App\Tenant\Mail\RecordMailer;
use App\Tenant\Security\ModuleRecord;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Document\Decoration;
use Xivi\Core\Document\DocumentFormat;
use Xivi\Core\Document\DocumentGenerator;
use Xivi\Core\Document\DocumentTemplateRepository;
use Xivi\Core\Entity\DocumentTemplate;
use Xivi\Core\Entity\EmailTemplate;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Mail\EmailRenderer;
use Xivi\Core\Mail\EmailTemplateRepository;
use Xivi\Core\Mail\Recipient;
use Xivi\Core\Mail\RecipientResolver;
use Xivi\Core\Mail\RenderedEmail;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * Sending one of a module's emails, from one record (XIV-39).
 *
 * The third controller in the row {@see DocumentController} started and
 * {@see EmailTemplateController} continued, and deliberately the same shape as
 * the first: **one button on the record and a chooser behind it**, never a
 * button per template. A contact with fifty templates would otherwise carry a
 * column of fifty buttons, which is the layout the document chooser already
 * replaced once.
 *
 * ### Two ways out of the chooser, and the fast one is the one that needed care
 *
 * "Send" without a preview is what somebody wants on the tenth invoice of the
 * morning and it is right to offer it. It is also irreversible with no undo — so
 * what makes it safe is not a confirmation dialog nobody reads, it is that the
 * modal shows the **resolved recipient and the subject before it is pressed**.
 * "Preview and send" is the same form posting somewhere else, and it renders the
 * message with this record's markers filled in, which is the only honest way to
 * find out that `[contacŧ]` was typed with the wrong letter before a customer
 * does.
 *
 * ### The address is editable, and only for this send
 *
 * A wrong address is not recoverable and the person pressing the button is the
 * last check there is, so the field is a field rather than a label. What it is
 * emphatically not is an edit of the record: sending one mail somewhere is not a
 * correction to the contact, and a screen whose "send" quietly rewrote a
 * customer's email address would be the worst kind of surprise. Nothing here
 * writes.
 *
 * It is a *correction* rather than a *substitute*, which is why a record whose
 * address cannot be resolved is refused here as well as unoffered on the record
 * page. Allowing a free-typed address on a record that names nobody would make
 * the declaration optional and turn the send screen into a way to mail anybody
 * at all from inside somebody else's ERP.
 *
 * ### Attaching the document, and the two grants that have to hold (XIV-40)
 *
 * "Send the invoice" is what anybody actually wants an ERP to do with an email,
 * and mechanically it is small: the chooser gains a document template and a
 * format, and {@see DocumentAttachments} makes the file. Two things about it are
 * not small and both live in this class.
 *
 * **Attaching means generating, so it needs the generate grant as well as the
 * send grant.** The class-level `send_email` is not enough: somebody who may
 * write to a customer but may not produce that customer's invoice must not be
 * able to obtain one out of the back of a send, and "the picker was not on their
 * screen" is not a check — the form is a POST anybody can retype. So the second
 * grant is asked for on the record, at the moment an attachment is actually
 * requested, and refused with a 403 rather than a message: a hand-built request
 * for something never offered is not a mistake to explain kindly.
 *
 * It is asked for on the *record* rather than on the module, because `document`
 * is scopable (§8.4) — "may generate for their own customers" is a real grant,
 * and a check against the module alone would quietly widen it to everybody's.
 *
 * **The preview generates the document too**, which costs a second conversion on
 * the preview-then-send path and is worth it. The preview exists so that what
 * arrives holds no surprises, and "the converter is down" and "this PDF is too
 * big to send" are the two surprises that would otherwise wait until the
 * irreversible button. It is also why the generator has a way to produce a
 * document without writing history: a preview must not put anything on a
 * record's timeline.
 *
 * ### Hand-rolled POSTs rather than a FormType
 *
 * Like DocumentController, FieldController, UserController and the email
 * template screens beside this one. The Symfony way would be the form component
 * and this is the exception the house rule allows for the administrative
 * screens: a select, a text input and an email input, next to five siblings that
 * do the same by hand. The record forms — where a customer's own definitions
 * decide the fields — are where the component earns its keep, and it is used
 * there.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[Route('/m/{module}', requirements: ['module' => '[a-z][a-z0-9_]*'])]
#[IsGranted(ModuleAction::SendEmail->value, subject: 'module')]
final class RecordEmailController extends AbstractController
{
    private const string CSRF = 'send-record-email';

    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
        private readonly EmailTemplateRepository $templates,
        private readonly RecipientResolver $recipients,
        private readonly EmailRenderer $renderer,
        private readonly RecordMailer $mailer,
        private readonly DocumentTemplateRepository $documents,
        private readonly DocumentAttachments $attachments,
        // What the attached document could carry beyond what the template says
        // (XIV-164). The generator rather than the decorator seam itself: this
        // controller has no more business knowing that a Swiss invoice wants a
        // payment slip than the document chooser has, and the generator is
        // already the one object here that turns records into files.
        private readonly DocumentGenerator $generator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Choosing what to send, as a page of its own.
     *
     * The record page opens this in a modal, and the modal and the page are one
     * form — the same arrangement the document chooser uses, for the same reason:
     * one description of what a send asks for rather than two that agree today.
     */
    #[Route('/{id}/email', name: 'module_email_choose', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['GET'])]
    public function choose(string $module, int $id): Response
    {
        $definition = $this->definition($module);
        $record = $this->recordFor($definition, $id);
        $recipient = $this->recipients->for($definition, $record);

        $attachments = $this->attachableFor($definition, $record);
        $decorations = $this->decorationsFor($definition, $record, $attachments);

        return $this->render('mail/choose.html.twig', [
            'module' => $definition,
            'record' => $record,
            'templates' => $this->templatesFor($definition, $record),
            'recipient' => $recipient,
            'draft' => self::blank($recipient, $decorations),
            'attachments' => $attachments,
            'formats' => DocumentFormat::cases(),
            'decorations' => $decorations,
        ]);
    }

    /**
     * The message as it will arrive, before it does.
     *
     * A POST rather than a GET because it carries what was typed — a subject and
     * a corrected address — and because it is a step in the middle of a form
     * rather than a page anybody would want to bookmark.
     */
    #[Route('/{id}/email/preview', name: 'module_email_preview', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function preview(string $module, int $id, Request $request): Response
    {
        $definition = $this->definition($module);
        $record = $this->recordFor($definition, $id);

        if (!$this->submitted($request)) {
            return $this->redirectToRoute('module_show', ['module' => $module, 'id' => $id]);
        }

        $recipient = $this->recipients->for($definition, $record);
        $draft = self::draftOf($request);
        $refusal = $this->problemWith($definition, $record, $recipient, $draft);

        if ($refusal !== null) {
            return $this->backToChooser($definition, $record, $recipient, $draft, $refusal);
        }

        $template = $this->template($definition, $record, $draft['template']);

        try {
            // Who it will look as though it came from, which is as much a part of
            // the preview as the words are (§8.7). It can fail on an instance
            // that has no address to send as at all, and finding that out here
            // beats finding it out one button later.
            $sender = $this->mailer->sender();
            // And the document itself, actually made rather than named (XIV-40).
            // A preview whose attachment would have failed to generate, or would
            // have been refused for its size, is a preview of a send that cannot
            // happen — which is the same rule problemWith() applies to
            // everything else on this screen.
            $attachment = $this->attachmentFor($definition, $record, $draft);
        } catch (MailSendFailed $failed) {
            return $this->backToChooser(
                $definition,
                $record,
                $recipient,
                $draft,
                $failed->translatable()->trans($this->translator),
            );
        } catch (AttachmentRefused $refused) {
            return $this->backToChooser($definition, $record, $recipient, $draft, $this->refusalOf($refused));
        }

        return $this->render('mail/preview.html.twig', [
            'module' => $definition,
            'record' => $record,
            'template' => $template,
            'draft' => $draft,
            'sender' => $sender,
            'attachment' => $attachment,
            'rendered' => $this->messageFor($template, $definition, $record, $draft['subject']),
        ]);
    }

    /**
     * It goes.
     *
     * Everything that could still be wrong is checked before anything leaves,
     * because after this there is no undo — and what happens either way is on
     * the record's timeline, written by {@see RecordMailer} rather than here, so
     * that the failure path cannot be the one somebody forgets to record.
     */
    #[Route('/{id}/email/send', name: 'module_email_send', requirements: ['id' => Requirement::POSITIVE_INT], methods: ['POST'])]
    public function send(string $module, int $id, Request $request): Response
    {
        $definition = $this->definition($module);
        $record = $this->recordFor($definition, $id);

        if (!$this->submitted($request)) {
            return $this->redirectToRoute('module_show', ['module' => $module, 'id' => $id]);
        }

        $recipient = $this->recipients->for($definition, $record);
        $draft = self::draftOf($request);
        $refusal = $this->problemWith($definition, $record, $recipient, $draft);

        if ($refusal !== null) {
            return $this->backToChooser($definition, $record, $recipient, $draft, $refusal);
        }

        $template = $this->template($definition, $record, $draft['template']);

        try {
            // **Before anything is handed to a transport**, which is what makes
            // "a failed generation sends nothing at all" true rather than
            // careful (XIV-40). Nothing is recorded on the timeline either: no
            // message was built, so there was no send to have failed, and an
            // `email_failed` here would say one was attempted when none was.
            $attachment = $this->attachmentFor($definition, $record, $draft);
        } catch (AttachmentRefused $refused) {
            return $this->backToChooser($definition, $record, $recipient, $draft, $this->refusalOf($refused));
        }

        try {
            $this->mailer->send(
                $definition,
                $record,
                $template,
                $draft['recipient'],
                $this->messageFor($template, $definition, $record, $draft['subject']),
                $attachment,
            );
        } catch (MailSendFailed $failed) {
            // The whole reason MailSendFailed is thrown rather than swallowed
            // (§8.7): somebody is standing there believing their invoice went
            // out, and this is the sentence that tells them it did not.
            $this->addFlash('warning', $failed->translatable()->trans($this->translator));

            return $this->redirectToRoute('module_show', ['module' => $module, 'id' => $id]);
        }

        $this->addFlash('success', $this->translator->trans('flash.email_sent', [
            '%recipient%' => $draft['recipient'],
        ]));

        return $this->redirectToRoute('module_show', ['module' => $module, 'id' => $id]);
    }

    /**
     * What is wrong with this send, or null.
     *
     * One method for both routes, because "preview" and "send" have to agree
     * about what is sendable down to the last case — a preview of something that
     * would then be refused is a preview of nothing.
     *
     * @param array{template: int, subject: string, recipient: string, document: int, format: string, decorations: list<string>} $draft
     */
    private function problemWith(
        ModuleDefinition $definition,
        Record $record,
        Recipient $recipient,
        array $draft,
    ): ?string {
        if (!$recipient->isResolved()) {
            // Unoffered on the record page, and refused here too. The typed
            // address corrects a resolved one; it does not stand in for a record
            // that names nobody. See the class docblock.
            $reason = $recipient->reason();

            return $reason === null
                ? $this->translator->trans('mail.not_sendable')
                : $reason->trans($this->translator);
        }

        if (filter_var($draft['recipient'], \FILTER_VALIDATE_EMAIL) === false) {
            // The input is `type="email"`, so this is a hand-edited request or a
            // browser that let something odd through. Either way it is the last
            // check before an address becomes a message.
            return $this->translator->trans('mail.recipient_invalid', ['%address%' => $draft['recipient']]);
        }

        if ($this->templateOrNull($definition, $record, $draft['template']) === null) {
            return $this->translator->trans('mail.no_template_chosen');
        }

        return null;
    }

    /**
     * Back to the chooser with what was typed still in it.
     *
     * The same call the email template form makes when it refuses: a subject
     * somebody worded carefully is not something to lose to a typo in the
     * address beside it.
     *
     * @param array{template: int, subject: string, recipient: string, document: int, format: string, decorations: list<string>} $draft
     */
    private function backToChooser(
        ModuleDefinition $definition,
        Record $record,
        Recipient $recipient,
        array $draft,
        string $refusal,
    ): Response {
        $this->addFlash('warning', $refusal);

        $attachments = $this->attachableFor($definition, $record);

        return $this->render('mail/choose.html.twig', [
            'module' => $definition,
            'record' => $record,
            'templates' => $this->templatesFor($definition, $record),
            'recipient' => $recipient,
            'draft' => $draft,
            'attachments' => $attachments,
            'formats' => DocumentFormat::cases(),
            'decorations' => $this->decorationsFor($definition, $record, $attachments),
        ]);
    }

    /**
     * The document going with this send, or null for a send without one.
     *
     * **Where the two grants meet** (XIV-40). The class already holds `send_email`
     * for every route; this is where `document` is asked for as well, and only
     * when an attachment is actually wanted — a plain send by somebody who may
     * not generate documents is an ordinary send and stays one.
     *
     * A 403 rather than a refusal on the page, because the picker is not drawn
     * for anybody who lacks the grant: a request naming a document is either
     * hand-built or a stale form, and neither wants a sentence explaining what
     * they might have done instead. A 404 for a template that does not apply to
     * this record, which is the call the document route already makes (§5.5).
     *
     * @param array{template: int, subject: string, recipient: string, document: int, format: string, decorations: list<string>} $draft
     *
     * @throws AttachmentRefused when it cannot be made, or is too big to send
     */
    private function attachmentFor(
        ModuleDefinition $definition,
        Record $record,
        array $draft,
    ): ?MailAttachment {
        if ($draft['document'] === 0) {
            return null;
        }

        if (!$this->mayGenerate($definition, $record)) {
            throw $this->createAccessDeniedException();
        }

        $template = $this->documents->find($definition->getKey(), $draft['document']);

        if ($template === null || !$template->appliesTo($definition->variantOf($record->data))) {
            throw $this->createNotFoundException();
        }

        return $this->attachments->for(
            $template,
            $definition,
            $record,
            // Hand-editable, and an unknown format is the one everybody means —
            // the same fallback the download route takes.
            DocumentFormat::tryFrom($draft['format']) ?? DocumentFormat::Pdf,
            // And what was ticked for it (XIV-164). Passed on rather than
            // checked here: what was actually on offer for this record is the
            // module's answer, and DocumentAttachments is where the two are
            // crossed, so that the document and the timeline entry cannot come
            // to disagree about what went out.
            $draft['decorations'],
        );
    }

    /**
     * The ticks to draw beside the attachment picker (XIV-164).
     *
     * Nothing at all where there is no picker, which is a shortcut with a
     * reason: a decoration is something added to an attached document, so a
     * tick offered on a send that can attach nothing would be a control with no
     * object. Beyond that the answer is the module's, through the generator,
     * exactly as the document chooser asks it.
     *
     * The format is `Pdf` here rather than whatever the draft holds because
     * this is the list of what *could* be offered, and the picker's own format
     * select can still change under it: the tick hides itself when somebody
     * chooses the .docx, and what is actually applied is settled again when the
     * document is made.
     *
     * @param list<DocumentTemplate> $attachments
     *
     * @return list<Decoration>
     */
    private function decorationsFor(ModuleDefinition $definition, Record $record, array $attachments): array
    {
        return $attachments === []
            ? []
            : $this->generator->decorations($definition, $record, DocumentFormat::Pdf);
    }

    /**
     * The documents this person could attach here, which is often none.
     *
     * Both grants again, in the form the screen needs rather than the form the
     * POST needs: without `document` the picker is not drawn at all, so nobody
     * is offered a control that would answer 403. Asked for before the templates
     * are read, so the query is not run to fill something nobody sees — the same
     * care the record page takes over its own two lists.
     *
     * @return list<DocumentTemplate>
     */
    private function attachableFor(ModuleDefinition $definition, Record $record): array
    {
        return $this->mayGenerate($definition, $record)
            ? $this->documents->forRecord($definition->getKey(), $definition->variantOf($record->data))
            : [];
    }

    /**
     * Whether this person may produce a document *from this record*.
     *
     * Record-scoped rather than module-scoped because `document` is scopable
     * (§8.4): "only my own customers" is a grant somebody can hold, and asking
     * the module would answer yes for records that grant does not cover.
     */
    private function mayGenerate(ModuleDefinition $definition, Record $record): bool
    {
        return $this->isGranted(ModuleAction::Document->value, new ModuleRecord($definition, $record));
    }

    /**
     * What to tell somebody whose attachment did not happen.
     *
     * Two sentences from two places: the document layer's own reason, which is
     * visibly about a document rather than about mail, wrapped in the half that
     * is the same however it failed — that nothing was sent. Composed here
     * rather than inside the exception because interpolating one translated
     * sentence into another needs a translator, and this is where there is one.
     */
    private function refusalOf(AttachmentRefused $refused): string
    {
        return $this->translator->trans('mail.attachment_failed', [
            '%reason%' => $refused->reason()->trans($this->translator),
        ]);
    }

    /**
     * The rendered message.
     *
     * An empty subject means the template's own, which is what the field being
     * empty is for: without JavaScript nothing can fill the box when the select
     * changes, and a box pre-filled from the *first* template would be a subject
     * silently attached to a different email. Blank and honest beats filled and
     * wrong — and the preview is where the resolved line is actually read.
     */
    private function messageFor(
        EmailTemplate $template,
        ModuleDefinition $definition,
        Record $record,
        string $subject,
    ): RenderedEmail {
        return $this->renderer->render($template, $definition, $record, $subject === '' ? null : $subject);
    }

    /** @return list<EmailTemplate> */
    private function templatesFor(ModuleDefinition $definition, Record $record): array
    {
        return $this->templates->forRecord($definition->getKey(), $definition->variantOf($record->data));
    }

    /**
     * One template of this module that applies to this record.
     *
     * A template for companies asked for on a person is not an error anybody
     * made on purpose and not an email that would mean anything either — the
     * same call the document route makes (§5.5).
     */
    private function templateOrNull(ModuleDefinition $definition, Record $record, int $id): ?EmailTemplate
    {
        $template = $this->templates->find($definition->getKey(), $id);

        return $template?->appliesTo($definition->variantOf($record->data)) === true ? $template : null;
    }

    private function template(ModuleDefinition $definition, Record $record, int $id): EmailTemplate
    {
        return $this->templateOrNull($definition, $record, $id) ?? throw $this->createNotFoundException();
    }

    /** @return array{template: int, subject: string, recipient: string, document: int, format: string, decorations: list<string>} */
    private static function draftOf(Request $request): array
    {
        return [
            'template' => $request->request->getInt('template'),
            'subject' => trim((string) $request->request->get('subject')),
            'recipient' => trim((string) $request->request->get('recipient')),
            // Zero is "nothing attached", which is what the picker's first
            // option holds and what a form that has no picker sends by omission
            // (XIV-40). No attachment is the ordinary send and stays the
            // default: a mail nobody asked to put a document on should not
            // acquire one from whichever template happened to be listed first.
            'document' => $request->request->getInt('document'),
            'format' => (string) $request->request->get('format', DocumentFormat::Pdf->value),
            // Ticked boxes only: an unticked one submits nothing, so a form
            // that came back without this key asked for a plain document
            // (XIV-164). The default that puts a payment part on a Swiss
            // invoice is drawn in the form, where somebody can see it.
            'decorations' => array_values(array_filter($request->request->all('decorations'), 'is_string')),
        ];
    }

    /**
     * A draft nobody has filled in yet.
     *
     * **Every offer ticked** (XIV-164), because the payment slip is the normal
     * case for a Swiss invoice and leaving it out is the exception. The default
     * lives here rather than as a `checked` in the template, so that the form
     * reads the same on the way in and on the way back from a refusal: one
     * expression decides the box, and what it reads is the draft.
     *
     * @param list<Decoration> $decorations
     *
     * @return array{template: int, subject: string, recipient: string, document: int, format: string, decorations: list<string>}
     */
    private static function blank(Recipient $recipient, array $decorations): array
    {
        return [
            'template' => 0,
            'subject' => '',
            'recipient' => $recipient->address ?? '',
            'document' => 0,
            'format' => DocumentFormat::Pdf->value,
            'decorations' => array_map(static fn (Decoration $decoration): string => $decoration->key, $decorations),
        ];
    }

    /**
     * The record, if this person may send from it.
     *
     * The same shape DocumentController uses and for the same reason (§8.4): a
     * record kept out of a list that can still be reached by typing its id is
     * not protected. Somebody who may view it but not send answers 403, because
     * that is true; somebody who may not view it at all answers 404, because
     * guessing ids should reveal nothing.
     */
    private function recordFor(ModuleDefinition $definition, int $id): Record
    {
        $record = $this->records->find($definition, $id) ?? throw $this->createNotFoundException();

        if ($this->isGranted(ModuleAction::SendEmail->value, new ModuleRecord($definition, $record))) {
            return $record;
        }

        throw $this->isGranted(ModuleAction::View->value, new ModuleRecord($definition, $record))
            ? $this->createAccessDeniedException()
            : $this->createNotFoundException();
    }

    private function definition(string $module): ModuleDefinition
    {
        try {
            return $this->metadata->get($module);
        } catch (ModuleNotInstalled $e) {
            throw $this->createNotFoundException($e->getMessage(), $e);
        }
    }

    private function submitted(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'));
    }
}

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

        return $this->render('mail/choose.html.twig', [
            'module' => $definition,
            'record' => $record,
            'templates' => $this->templatesFor($definition, $record),
            'recipient' => $recipient,
            'draft' => self::blank($recipient),
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
        } catch (MailSendFailed $failed) {
            return $this->backToChooser(
                $definition,
                $record,
                $recipient,
                $draft,
                $failed->translatable()->trans($this->translator),
            );
        }

        return $this->render('mail/preview.html.twig', [
            'module' => $definition,
            'record' => $record,
            'template' => $template,
            'draft' => $draft,
            'sender' => $sender,
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
            $this->mailer->send(
                $definition,
                $record,
                $template,
                $draft['recipient'],
                $this->messageFor($template, $definition, $record, $draft['subject']),
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
     * @param array{template: int, subject: string, recipient: string} $draft
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
     * @param array{template: int, subject: string, recipient: string} $draft
     */
    private function backToChooser(
        ModuleDefinition $definition,
        Record $record,
        Recipient $recipient,
        array $draft,
        string $refusal,
    ): Response {
        $this->addFlash('warning', $refusal);

        return $this->render('mail/choose.html.twig', [
            'module' => $definition,
            'record' => $record,
            'templates' => $this->templatesFor($definition, $record),
            'recipient' => $recipient,
            'draft' => $draft,
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

    /** @return array{template: int, subject: string, recipient: string} */
    private static function draftOf(Request $request): array
    {
        return [
            'template' => $request->request->getInt('template'),
            'subject' => trim((string) $request->request->get('subject')),
            'recipient' => trim((string) $request->request->get('recipient')),
        ];
    }

    /** @return array{template: int, subject: string, recipient: string} */
    private static function blank(Recipient $recipient): array
    {
        return ['template' => 0, 'subject' => '', 'recipient' => $recipient->address ?? ''];
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

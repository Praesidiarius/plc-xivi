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

namespace App\Tests\Functional\Engine;

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Xivi\Contact\ContactModule;
use Xivi\Core\Document\DocumentTemplateRepository;
use Xivi\Core\Entity\DocumentTemplate;
use Xivi\Core\History\HistoryEntry;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Mail\EmailTemplateRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Record\RecordAction;

/**
 * Sending the invoice, which is XIV-39 and XIV-4 meeting (XIV-40).
 *
 * The attaching itself is small — `DocumentGenerator` already makes exactly this
 * file — so almost nothing here is about the file arriving. What is proven is the
 * four things the ticket said were not small:
 *
 * **Two permissions have to hold at once.** The one most likely to be quietly
 * wrong, because the picker not being on the screen looks like a check and is
 * not: the form is a POST anybody can retype. So it is proven by retyping it.
 *
 * **The timeline gets one fact.** One entry, naming its attachment — and, just as
 * important, *no* `document_generated` entry beside it. Two rows for one button
 * press would be indistinguishable from two acts that really happened, which is
 * the reading §5.15 refuses to lose.
 *
 * **Failure is two-sided.** A generation that fails sends nothing and writes
 * nothing; a send that fails after a good generation is `email_failed` naming the
 * document. Both are provoked for real rather than mocked — a stored template
 * that is not a .docx, and XIV-37's guard refusing a transport, which is the
 * closest this suite is allowed to get to a send that goes wrong.
 *
 * **Size.** An 8 MiB document, made large the only way a zip cannot argue with:
 * incompressible bytes inside it.
 *
 * The document templates are stored through the repository rather than uploaded
 * through the form, because two of these tests need files the upload screen
 * would rightly refuse — one that is not a .docx at all, and one over its 5 MB
 * ceiling. Both are states a customer's database can genuinely be in, from a
 * blob that rotted or a template stored before a rule existed.
 *
 * Nothing here puts mail on the wire; assertions go through Symfony's message
 * logger, as {@see SendEmailTest} does.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class EmailAttachmentTest extends WebTestCase
{
    use MailerAssertionsTrait;
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_email_attach';
    private const string HOST = 'attaching.localhost';
    private const string ADMIN = 'admin@attaching.test';
    /** Whose session a record is saved under unless a test says otherwise (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string SENDER = 'sender@attaching.test';
    private const string PASSWORD = 'attaching-password';

    /** A server that exists, so nothing here is safe by virtue of not resolving. */
    private const string REAL_SMTP = 'smtp.gmail.com';

    /** Comfortably over the seven mebibytes §5.15 settled on. */
    private const int OVERSIZED = 8 * 1024 * 1024;

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)
            ->install(self::service(ModuleRegistry::class)->get(ContactModule::KEY)));

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        // Somebody who may send and — until a test says otherwise — may not
        // generate. The whole permission half of the ticket is about them.
        $users->create($this->tenant, self::SENDER, 'Sender', self::PASSWORD, []);

        $this->signIn(self::ADMIN);
    }

    // -- it goes out attached -----------------------------------------------

    /** The feature, in one assertion: the document is on the message. */
    public function testTheChosenDocumentGoesOutAttachedToTheMail(): void
    {
        $email = $this->emailTemplate('Order confirmation', 'Your order', 'Thank you.');
        $document = $this->documentTemplate('Invoice', 'Dear [first_name] [last_name].');
        $contact = $this->aContact();

        $this->sendFrom($contact, ['template' => $email, 'document' => $document, 'format' => 'docx']);

        $attachment = $this->attachmentOf(self::getMailerMessage());

        self::assertStringStartsWith('invoice-', self::filenameOf($attachment), 'named after the template and the record');
        self::assertStringEndsWith('.docx', self::filenameOf($attachment));
        // The markers are resolved, so this is the document this record makes
        // rather than the template with the brackets still in it.
        self::assertStringContainsString('Dear Ada Lovelace.', $this->wordsIn($attachment->getBody()));
    }

    /**
     * And as a PDF, which is the format nine sends in ten want.
     *
     * Against the real converter or not at all, for the reason
     * {@see DocumentTemplateTest} gives: a fake would prove the seam is called
     * and say nothing about whether LibreOffice can read what we produce.
     */
    public function testTheAttachmentIsAPdfWhenThatIsWhatWasChosen(): void
    {
        $email = $this->emailTemplate('Order confirmation', 'Your order', 'Thank you.');
        $document = $this->documentTemplate('Invoice', 'Dear [first_name].');
        $contact = $this->aContact();

        $this->sendFrom($contact, ['template' => $email, 'document' => $document, 'format' => 'pdf']);

        if (self::getMailerMessage() === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        $attachment = $this->attachmentOf(self::getMailerMessage());

        self::assertStringEndsWith('.pdf', self::filenameOf($attachment));
        self::assertSame('application/pdf', $attachment->getMediaType() . '/' . $attachment->getMediaSubtype());
        self::assertStringStartsWith('%PDF-', $attachment->getBody());
    }

    /**
     * Nothing attached is the ordinary send and stays untouched.
     *
     * The picker's first option, and the one it opens on: a mail nobody asked to
     * put a document on must not acquire one from whichever template happened to
     * be listed first.
     */
    public function testASendWithNothingChosenIsStillAnOrdinarySend(): void
    {
        $email = $this->emailTemplate('Hello', 'Hello', 'Hi.');
        $this->documentTemplate('Invoice', 'Dear [first_name].');
        $contact = $this->aContact();

        $this->sendFrom($contact, ['template' => $email]);

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame([], $message->getAttachments());

        $entry = $this->latestHistory($contact);
        self::assertSame(RecordAction::EmailSent, $entry->action);
        self::assertSame(
            ['template' => 'Hello', 'recipient' => 'ada@example.test', 'subject' => 'Hello'],
            $entry->email(),
            'no attachment key at all, rather than an empty one',
        );
    }

    // -- one fact on the timeline, not two ----------------------------------

    /**
     * The decision this ticket had to make, as data.
     *
     * One entry, naming what went with the mail — and no `document_generated`
     * row beside it. Two rows would describe a single button press twice, and
     * worse, would be indistinguishable from somebody downloading a PDF and then
     * separately writing to the customer, which is a different thing to have
     * happened.
     */
    public function testTheSendAndItsAttachmentAreOneEntryOnTheTimeline(): void
    {
        $email = $this->emailTemplate('Order confirmation', 'Hello [first_name]', 'Hi.');
        $document = $this->documentTemplate('Invoice', 'Dear [first_name].');
        $contact = $this->aContact();

        $this->sendFrom($contact, ['template' => $email, 'document' => $document, 'format' => 'docx']);

        $entries = $this->mailAndDocumentEntries($contact);

        self::assertCount(1, $entries, 'one button press, one line');
        self::assertSame(
            RecordAction::EmailSent,
            $entries[0]->action,
            'a document made in order to be attached is not a second event',
        );
        self::assertSame([
            'template' => 'Order confirmation',
            'recipient' => 'ada@example.test',
            'subject' => 'Hello Ada',
            'attachment' => ['template' => 'Invoice', 'format' => 'docx'],
        ], $entries[0]->email());
    }

    /** And the record's timeline says so where somebody reads it. */
    public function testTheRecordPageShowsWhatWentWithTheMail(): void
    {
        $email = $this->emailTemplate('Order confirmation', 'Hello', 'Hi.');
        $document = $this->documentTemplate('Invoice', 'Dear [first_name].');
        $contact = $this->aContact();

        $this->sendFrom($contact, ['template' => $email, 'document' => $document, 'format' => 'docx']);

        $page = $this->client->request('GET', $this->url('/m/contact/' . $contact))->filter('main')->text();

        self::assertStringContainsString('Email sent', $page);
        self::assertStringContainsString('Invoice · DOCX', $page);
    }

    // -- two permissions, both of them --------------------------------------

    /**
     * The acceptance criterion most likely to be quietly wrong.
     *
     * Somebody who may send mail and may not generate documents is offered no
     * picker — and, because a missing control is not a check, is refused when
     * they post the id anyway. Granting `document` as well turns the same
     * request into a send.
     */
    public function testAttachingTakesTheGenerateGrantAsWellAsTheSendGrant(): void
    {
        $email = $this->emailTemplate('Order confirmation', 'Hello', 'Hi.');
        $document = $this->documentTemplate('Invoice', 'Dear [first_name].');
        $contact = $this->aContact();

        $this->grant(self::SENDER, ModuleAction::View);
        $this->grant(self::SENDER, ModuleAction::SendEmail);
        $this->signIn(self::SENDER);

        $chooser = $this->client->request('GET', $this->url('/m/contact/' . $contact . '/email'));
        self::assertResponseIsSuccessful('sending is what they were granted');
        self::assertCount(0, $chooser->filter('select[name="document"]'), 'and attaching is not');

        $this->postSend($contact, [
            '_token' => (string) $chooser->filter('input[name="_token"]')->attr('value'),
            'template' => (string) $email,
            'recipient' => 'ada@example.test',
            'document' => (string) $document,
            'format' => 'docx',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'a document is not obtainable out of the back of a send');
        self::assertEmailCount(0, message: 'and nothing went out either');

        // The same request, once the second grant is there.
        $this->grant(self::SENDER, ModuleAction::Document);
        $chooser = $this->client->request('GET', $this->url('/m/contact/' . $contact . '/email'));
        self::assertCount(1, $chooser->filter('select[name="document"]'), 'now it is offered');

        $this->postSend($contact, [
            '_token' => (string) $chooser->filter('input[name="_token"]')->attr('value'),
            'template' => (string) $email,
            'recipient' => 'ada@example.test',
            'document' => (string) $document,
            'format' => 'docx',
        ]);

        self::assertEmailCount(1);
        self::assertStringEndsWith('.docx', self::filenameOf($this->attachmentOf(self::getMailerMessage())));
    }

    /** A send with nothing attached is untouched by the second grant. */
    public function testSomebodyWhoMayNotGenerateCanStillSendAnOrdinaryMail(): void
    {
        $email = $this->emailTemplate('Hello', 'Hello', 'Hi.');
        $this->documentTemplate('Invoice', 'Dear [first_name].');
        $contact = $this->aContact();

        $this->grant(self::SENDER, ModuleAction::View);
        $this->grant(self::SENDER, ModuleAction::SendEmail);
        $this->signIn(self::SENDER);

        $this->sendFrom($contact, ['template' => $email]);

        self::assertEmailCount(1);
    }

    // -- failure, on both of its sides --------------------------------------

    /**
     * A generation that fails sends nothing at all — not a mail with no document.
     *
     * And writes nothing to the timeline: no message was built, so there was no
     * send to have failed, and an `email_failed` row would assert an attempt that
     * did not happen (§5.15).
     */
    public function testAFailedGenerationSendsNothingAndRecordsNothing(): void
    {
        $email = $this->emailTemplate('Order confirmation', 'Hello', 'Hi.');
        $document = $this->brokenDocumentTemplate('Invoice');
        $contact = $this->aContact();

        $this->sendFrom($contact, ['template' => $email, 'document' => $document, 'format' => 'docx']);

        self::assertEmailCount(0, message: 'nothing left the building');
        self::assertCount(
            0,
            $this->mailAndDocumentEntries($contact),
            'no message was ever built, so there is no send to have failed',
        );

        $page = $this->client->getCrawler()->filter('main')->text();
        self::assertStringContainsString('Nothing was sent', $page);
        // The document layer's own sentence, which is visibly about a document
        // rather than about mail — that is how somebody tells this from a send
        // that failed.
        self::assertStringContainsString('could not be filled in', $page);
    }

    /**
     * And a send that fails *after* a good generation does not look like one.
     *
     * `email_failed`, as §5.14 wrote it, and it names the attachment — which is
     * the sentence "the invoice was made and the mail server refused it",
     * readable a year later from the entry alone.
     */
    public function testASendThatFailsAfterAGoodGenerationNamesItsAttachment(): void
    {
        $email = $this->emailTemplate('Order confirmation', 'Hello', 'Hi.');
        $document = $this->documentTemplate('Invoice', 'Dear [first_name].');
        $contact = $this->aContact();

        // Real credentials on a real tenant: XIV-37's guard refuses to build a
        // transport that could deliver, which is a send that fails without this
        // suite ever opening a socket.
        $this->useRealSmtp();

        $this->sendFrom($contact, ['template' => $email, 'document' => $document, 'format' => 'docx']);

        self::assertEmailCount(0);

        $entry = $this->latestHistory($contact);

        self::assertSame(RecordAction::EmailFailed, $entry->action);
        self::assertSame([
            'template' => 'Order confirmation',
            'recipient' => 'ada@example.test',
            'subject' => 'Hello',
            // The document was made; the transport is what refused. That is what
            // tells this apart from a generation that failed, which leaves no
            // entry at all.
            'attachment' => ['template' => 'Invoice', 'format' => 'docx'],
        ], $entry->email());

        self::assertStringContainsString(
            'The message could not be sent',
            $this->client->followRedirect()->filter('main')->text(),
            'and it reads as a send that failed, not as a document that could not be made',
        );
    }

    // -- the ceiling ---------------------------------------------------------

    /**
     * Too big is refused on the screen, with the size and the ceiling in it.
     *
     * A bounce two hours later arrives at an address that is frequently nobody's
     * inbox, about a message the sender has stopped thinking about (§5.15). This
     * is the same news, while somebody is still looking at the screen.
     */
    public function testAnOversizedDocumentIsRefusedWithAMessageRatherThanSent(): void
    {
        $email = $this->emailTemplate('Order confirmation', 'Hello', 'Hi.');
        $document = $this->hugeDocumentTemplate('Catalogue');
        $contact = $this->aContact();

        $this->sendFrom($contact, ['template' => $email, 'document' => $document, 'format' => 'docx']);

        self::assertEmailCount(0, message: 'refused here rather than bounced there');
        self::assertCount(0, $this->mailAndDocumentEntries($contact), 'and no send to record');

        $page = $this->client->getCrawler()->filter('main')->text();
        self::assertStringContainsString('Nothing was sent', $page);
        self::assertStringContainsString('this installation sends at most 7.0 MB', $page);
    }

    // -- the preview ---------------------------------------------------------

    /**
     * The preview shows the document it will attach, having actually made it.
     *
     * Which is what makes the two refusals above land on the *preview* rather
     * than one irreversible button later — the whole reason that screen exists.
     */
    public function testThePreviewNamesTheDocumentItWillAttach(): void
    {
        $email = $this->emailTemplate('Order confirmation', 'Hello', 'Hi.');
        $document = $this->documentTemplate('Invoice', 'Dear [first_name].');
        $contact = $this->aContact();

        $preview = $this->previewFrom($contact, [
            'template' => $email,
            'document' => $document,
            'format' => 'docx',
        ]);

        $envelope = $preview->filter('main')->text();
        self::assertStringContainsString('invoice-' . $contact, $envelope);
        self::assertStringContainsString('MB)', $envelope, 'and how big it is');

        self::assertEmailCount(0, message: 'a preview sends nothing');
        self::assertCount(
            0,
            $this->mailAndDocumentEntries($contact),
            'and a preview that builds a document is still not a document generated',
        );
    }

    /** And the send after it carries the same choice through. */
    public function testThePreviewLeadsStraightToTheSendWithTheDocumentStillOnIt(): void
    {
        $email = $this->emailTemplate('Order confirmation', 'Hello', 'Hi.');
        $document = $this->documentTemplate('Invoice', 'Dear [first_name].');
        $contact = $this->aContact();

        $preview = $this->previewFrom($contact, [
            'template' => $email,
            'document' => $document,
            'format' => 'docx',
        ]);
        $this->client->submit($preview->selectButton('Send')->form());

        self::assertEmailCount(1);
        self::assertStringEndsWith('.docx', self::filenameOf($this->attachmentOf(self::getMailerMessage())));
    }

    // -- helpers ------------------------------------------------------------

    /** The one attachment on the message, asserted to be exactly one. */
    private function attachmentOf(?object $message): DataPart
    {
        self::assertInstanceOf(Email::class, $message);

        $attachments = $message->getAttachments();
        self::assertCount(1, $attachments);

        return $attachments[0];
    }

    /** A `DataPart` may in principle have no name; one this application built has. */
    private static function filenameOf(DataPart $attachment): string
    {
        $filename = $attachment->getFilename();
        self::assertNotNull($filename, 'an attachment arrives with a name on it');

        return $filename;
    }

    /**
     * Fills in the chooser and presses Send.
     *
     * Through the form the record page actually renders, so the test presses
     * what a person presses. The redirect is deliberately not followed: the
     * message logger is reset between requests.
     *
     * @param array{template: int, document?: int, format?: string} $values
     */
    private function sendFrom(int $id, array $values): void
    {
        $this->submitChooser($id, $values, 'Send');
    }

    /** @param array{template: int, document?: int, format?: string} $values */
    private function previewFrom(int $id, array $values): Crawler
    {
        return $this->submitChooser($id, $values, 'Preview and send');
    }

    /** @param array{template: int, document?: int, format?: string} $values */
    private function submitChooser(int $id, array $values, string $button): Crawler
    {
        $page = $this->client->request('GET', $this->url('/m/contact/' . $id));
        $form = $page->selectButton($button)->form();

        $form['template'] = (string) $values['template'];

        if (isset($values['document'])) {
            $form['document'] = (string) $values['document'];
            $form['format'] = $values['format'] ?? 'pdf';
        }

        return $this->client->submit($form);
    }

    /**
     * The send, posted by hand.
     *
     * What somebody retyping the form does, which is the only honest way to test
     * a control that is not drawn for them.
     *
     * @param array<string, string> $values
     */
    private function postSend(int $id, array $values): void
    {
        $this->client->request('POST', $this->url('/m/contact/' . $id . '/email/send'), $values);
    }

    /** Writes an email template through the browser and gives back its id. */
    private function emailTemplate(string $name, string $subject, string $body): int
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/email-templates/new'));
        $this->client->submit($crawler->selectButton('Save')->form([
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
        ]));
        $this->client->followRedirect();

        $written = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(EmailTemplateRepository::class)->forModule(ContactModule::KEY),
        );

        self::assertNotEmpty($written, 'the template was accepted');

        return (int) end($written)->getId();
    }

    /** An ordinary .docx template, stored the way the upload screen stores one. */
    private function documentTemplate(string $name, string $body): int
    {
        return $this->storeDocument($name, self::aDocx($body));
    }

    /**
     * A stored template that is not a .docx at all.
     *
     * The upload screen refuses this, and a customer's database can hold one
     * anyway: a blob that rotted, or a file stored before the check existed. It
     * is the cheapest honest way to make a generation fail.
     */
    private function brokenDocumentTemplate(string $name): int
    {
        return $this->storeDocument($name, 'this is not a zip, let alone a Word document');
    }

    /**
     * A template that produces a document too big to send.
     *
     * Made large with **incompressible** bytes rather than a lot of text, because
     * a zip full of repeated words is a small zip and the ceiling is about what
     * comes out. Filed under `word/media/`, where a letterhead keeps its logo, so
     * it survives the round trip the way a real image does.
     */
    private function hugeDocumentTemplate(string $name): int
    {
        return $this->storeDocument($name, self::aDocx('Dear [first_name].', random_bytes(self::OVERSIZED)));
    }

    private function storeDocument(string $name, string $contents): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($name, $contents): int {
            $template = new DocumentTemplate(ContactModule::KEY, $name, $name . '.docx', $contents, null, 'Admin');
            self::service(DocumentTemplateRepository::class)->save($template);

            return (int) $template->getId();
        });
    }

    /**
     * A minimal but real .docx, as {@see DocumentTemplateTest} builds one.
     *
     * Three parts is all Word needs to open a file: the content types, the
     * relationship naming the main document, and the document itself.
     */
    private static function aDocx(string $text, ?string $media = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-test-attachment-') . '.docx';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($text, \ENT_XML1) . '</w:t></w:r></w:p>'
            . '</w:body></w:document>');

        if ($media !== null) {
            $zip->addFromString('word/media/image1.png', $media);
        }

        $zip->close();

        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /** The words inside a .docx, with the XML taken off. */
    private function wordsIn(string $docx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-test-read-') . '.docx';
        file_put_contents($path, $docx);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true, 'the attachment is a readable zip');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        @unlink($path);

        return strip_tags($xml);
    }

    /** The customer's own SMTP server, which is what XIV-37's guard refuses. */
    private function useRealSmtp(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(TenantProfileManager::class)
            ->applyMail('billing@attaching.test', self::REAL_SMTP, 587, 'attaching', 'hunter2'));
    }

    /**
     * Everything this ticket could have written to the timeline, and nothing else.
     *
     * Saving a record through the form leaves a `created` entry behind (XIV-33),
     * so "nothing was recorded" has to mean nothing *about mail or documents*
     * rather than an empty table — and filtering by the three verbs is also what
     * makes "one fact, not two" a count rather than a hopeful glance.
     *
     * @return list<HistoryEntry>
     */
    private function mailAndDocumentEntries(int $id): array
    {
        return array_values(array_filter(
            $this->historyFor($id),
            static fn (HistoryEntry $entry): bool => \in_array($entry->action, [
                RecordAction::EmailSent,
                RecordAction::EmailFailed,
                RecordAction::DocumentGenerated,
            ], true),
        ));
    }

    /** @return list<HistoryEntry> */
    private function historyFor(int $id): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): array {
            $definition = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            return self::service(HistoryRepository::class)->findFor($definition, $id, 50);
        });
    }

    private function latestHistory(int $id): HistoryEntry
    {
        $entries = $this->historyFor($id);
        self::assertNotEmpty($entries, 'something was recorded');

        return $entries[0];
    }

    /** A contact with an address, so every test here has somewhere to write to. */
    private function aContact(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test'],
            variant: 'person',
        ));
    }

    private function grant(string $email, ModuleAction $action): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email, $action): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            $manager->persist(PermissionGrant::forUser($user, ContactModule::KEY, $action, PermissionScope::All));
            $manager->flush();
        });
    }

    private function signIn(string $email): void
    {
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}

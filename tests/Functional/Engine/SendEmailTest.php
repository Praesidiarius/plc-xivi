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

use App\ControlPlane\Entity\Tenant;
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
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\History\HistoryEntry;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Mail\EmailTemplateRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordRepository;
use Xivi\Order\OrderModule;

/**
 * Sending one of a module's emails from a record (XIV-39).
 *
 * XIV-37 built a way out of the building and XIV-38 built something to send.
 * This is the ticket where the two meet, and most of what is worth proving here
 * is not the sending — that has its own suite — but the three decisions around
 * it.
 *
 * **Where the recipient comes from**, which is the design weight of the ticket:
 * a module declares it, on a field of its own or one hop through a reference,
 * and the two cases are checked against two real modules rather than a fixture —
 * a contact carries its own address and an order carries none and points at one.
 *
 * **That a record which cannot be sent to offers no send and says why**, which
 * is checked from the record page rather than from the resolver, because the
 * sentence and its absence are the feature.
 *
 * **That the timeline tells a failure from a success.** §8.7 wrote down that
 * "nothing happened" and "it went out" must not look the same, and this is where
 * that stops being a sentence in a document. The failure is provoked through the
 * guard XIV-37 built rather than through a mock: a tenant with real SMTP
 * credentials is refused a transport, which is the closest this suite is allowed
 * to get to a send that goes wrong — and, not incidentally, proves the guard
 * again from a caller that now exists.
 *
 * Nothing here puts mail on the wire; assertions go through Symfony's message
 * logger, which collects in this process before any transport (§9.2).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SendEmailTest extends WebTestCase
{
    use MailerAssertionsTrait;
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_send_email';
    private const string HOST = 'sending.localhost';
    private const string ADMIN = 'admin@sending.test';
    /** Whose session a record is saved under unless a test says otherwise (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string SENDER = 'sender@sending.test';
    private const string READER = 'reader@sending.test';
    private const string PASSWORD = 'sending-password';

    /** A server that exists, so nothing here is safe by virtue of not resolving. */
    private const string REAL_SMTP = 'smtp.gmail.com';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            // Three on purpose: one that carries its own address, one that has
            // none and points at the first — the two halves of the declaration —
            // and one that declares nothing, because "shows nothing at all" is
            // a case of its own.
            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::SENDER, 'Sender', self::PASSWORD, []);
        $users->create($this->tenant, self::READER, 'Reader', self::PASSWORD, []);

        $this->signIn(self::ADMIN);
    }

    // -- where the recipient comes from -------------------------------------

    /**
     * One button on the record, whatever the templates number.
     *
     * The document chooser's shape, deliberately: fifty templates on a contact
     * would be fifty buttons, which is the layout that pattern replaced once.
     */
    public function testTheRecordCarriesOneSendButtonAndAChooserBehindIt(): void
    {
        $this->write('Order confirmation', 'Hello [first_name]', 'Dear [first_name],');
        $this->write('Reminder', 'Overdue', 'Please pay.');

        $contact = $this->aContact(['email' => 'ada@example.test']);
        $page = $this->client->request('GET', $this->url('/m/contact/' . $contact));

        self::assertCount(1, $page->filter('a[href$="/m/contact/' . $contact . '/email"]'));
        self::assertCount(2, $page->filter('#email-modal select[name="template"] option'));
    }

    /** The simple half of the declaration: the address is a field of this record. */
    public function testTheAddressMayBeAFieldOnThisRecord(): void
    {
        $this->write('Hello', 'Hello', 'Hi.');
        $contact = $this->aContact(['email' => 'ada@example.test']);

        $chooser = $this->client->request('GET', $this->url('/m/contact/' . $contact . '/email'));

        self::assertSame('ada@example.test', $chooser->filter('input[name="recipient"]')->attr('value'));
    }

    /**
     * The half the ticket is about: one hop through a reference (§7.6).
     *
     * An order has no email address and never will, because the address belongs
     * to whoever ordered. The engine cannot know that; the module declares it,
     * and the page says which record the address was taken from rather than
     * presenting it as something the order has.
     */
    public function testTheAddressMayBeOneHopAwayThroughAReference(): void
    {
        $this->write('Confirmation', 'Your order', 'Thank you.', OrderModule::KEY);

        $order = $this->anOrder($this->aContact(['email' => 'ada@example.test']));
        $chooser = $this->client->request('GET', $this->url('/m/order/' . $order . '/email'));

        self::assertSame('ada@example.test', $chooser->filter('input[name="recipient"]')->attr('value'));
        // "Customer", not "Contact": the module calls its reference field that,
        // and the sentence is built from the customer's own labels rather than
        // from anything this package could have written down.
        self::assertStringContainsString(
            'Taken from the Email of the Customer this record names',
            $chooser->filter('main')->text(),
        );
    }

    /**
     * A second hop is not a thing anybody can declare.
     *
     * Not a test of a refusal — there is nothing to refuse, which is the point.
     * The declaration is a field and an optional reference, so `order.contact.email`
     * from an invoice cannot be written down at all, and this records that the
     * shape is what enforces it rather than a validation somewhere.
     */
    public function testTheDeclarationCanOnlyEverBeOneHop(): void
    {
        $declared = self::service(ModuleRegistry::class)->get(OrderModule::KEY)->mailRecipient;

        self::assertNotNull($declared);
        self::assertSame('email', $declared->field);
        self::assertSame('contact', $declared->through);
    }

    // -- the address is shown, editable, and never written back --------------

    /**
     * The last check before a mail leaves is a person, so the field is a field.
     *
     * And editing it is emphatically not an edit of the record: sending one mail
     * somewhere is not a correction to the contact.
     */
    public function testACorrectedAddressAppliesToThisSendAndNotToTheRecord(): void
    {
        $template = $this->write('Hello', 'Hello [first_name]', 'Hi.');
        $contact = $this->aContact(['email' => 'ada@example.test']);

        $this->sendFrom('contact', $contact, ['template' => $template, 'recipient' => 'else@example.test']);

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('else@example.test', $message->getTo()[0]->getAddress());

        self::assertSame(
            'ada@example.test',
            $this->recordOf(ContactModule::KEY, $contact)->get('email'),
            'the contact still has the address it had before',
        );
    }

    /** Whose mail this is, is still not the caller's to decide (§8.7). */
    public function testTheMailGoesOutUnderTheTenantsOwnIdentity(): void
    {
        $template = $this->write('Hello', 'Hello', 'Hi.');
        $contact = $this->aContact(['email' => 'ada@example.test']);

        $this->sendFrom('contact', $contact, ['template' => $template]);

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('no-reply@' . self::HOST, $message->getFrom()[0]->getAddress());
    }

    // -- a record with no address offers no send, and says why ---------------

    /** A contact who is a name and a phone number is an ordinary contact. */
    public function testARecordWithNoAddressOffersNoSendAndSaysWhy(): void
    {
        $this->write('Hello', 'Hello', 'Hi.');
        $contact = $this->aContact();

        $page = $this->client->request('GET', $this->url('/m/contact/' . $contact));

        self::assertCount(0, $page->filter('a[href$="/m/contact/' . $contact . '/email"]'));
        self::assertStringContainsString('This record has no Email', $page->filter('main')->text());
    }

    /**
     * And one hop away it says *which* record is missing it.
     *
     * "No recipient" would send somebody looking at the order; the address is on
     * the contact, and the sentence has to say so or it is worse than useless.
     */
    public function testAnAddressMissingOneHopAwaySaysWhichRecordIsMissingIt(): void
    {
        $this->write('Confirmation', 'Your order', 'Thank you.', OrderModule::KEY);

        $order = $this->anOrder($this->aContact());
        $page = $this->client->request('GET', $this->url('/m/order/' . $order));

        self::assertCount(0, $page->filter('a[href$="/m/order/' . $order . '/email"]'));
        self::assertStringContainsString(
            'The Customer this record names has no Email',
            $page->filter('main')->text(),
        );
    }

    /** Something stored that is not an address is not a send waiting to bounce. */
    public function testAValueThatIsNotAnAddressIsRefusedBeforeAnythingIsOffered(): void
    {
        $this->write('Hello', 'Hello', 'Hi.');
        $contact = $this->aContact();
        $this->storeRawEmail(ContactModule::KEY, $contact, 'ring him');

        $page = $this->client->request('GET', $this->url('/m/contact/' . $contact));

        self::assertCount(0, $page->filter('a[href$="/m/contact/' . $contact . '/email"]'));
        self::assertStringContainsString('is not an email address', $page->filter('main')->text());
    }

    /**
     * A module that never said where a recipient comes from shows nothing at all.
     *
     * Not a problem to explain: an article has nobody to write to, and a page
     * apologising for the absence of a feature it does not have would be noise
     * on every record of it. The template exists, so this is the declaration
     * being missing rather than there being nothing to send.
     */
    public function testAModuleThatDeclaresNoRecipientSaysNothingAtAll(): void
    {
        $this->write('Hello', 'Hello', 'Hi.', ArticleModule::KEY);
        $article = $this->anArticle();

        $page = $this->client->request('GET', $this->url('/m/article/' . $article));

        self::assertCount(0, $page->filter('a[href$="/m/article/' . $article . '/email"]'));
        self::assertStringNotContainsString('send', strtolower($page->filter('main')->text()));
    }

    /** And a hand-typed URL is refused rather than sent from anyway. */
    public function testASendIsRefusedOnARecordWithNoAddressEvenWhenPostedByHand(): void
    {
        $template = $this->write('Hello', 'Hello', 'Hi.');
        $contact = $this->aContact();

        $this->client->request('POST', $this->url('/m/contact/' . $contact . '/email/send'), [
            '_token' => $this->tokenFrom($this->aContact(['email' => 'ada@example.test'])),
            'template' => (string) $template,
            'subject' => '',
            'recipient' => 'anybody@example.test',
        ]);

        self::assertEmailCount(0, message: 'the address is a correction, not a substitute');
        self::assertStringContainsString(
            'This record has no Email',
            $this->client->getCrawler()->filter('main')->text(),
        );
    }

    // -- the preview ---------------------------------------------------------

    /**
     * The only honest way to catch `[contacŧ]` before a customer reads it.
     *
     * The base template around it as well as the content, because the wrapper is
     * part of what arrives (§5.13) — and who it will appear to be from, because a
     * preview that leaves that out is not a preview of the message being sent.
     */
    public function testThePreviewRendersTheBaseTemplateWithThisRecordsMarkersResolved(): void
    {
        $template = $this->write('Hello', 'Hello [first_name]', 'Dear **[first_name] [last_name]**,');
        $contact = $this->aContact(['email' => 'ada@example.test']);

        $preview = $this->previewFrom('contact', $contact, ['template' => $template]);
        $body = (string) $preview->filter('iframe.mail-preview')->attr('srcdoc');

        self::assertStringStartsWith('<!doctype html>', $body, 'a whole document, not a fragment');
        self::assertStringContainsString('<strong>Ada Lovelace</strong>', $body);

        $envelope = $preview->filter('main')->text();
        self::assertStringContainsString('Hello Ada', $envelope, 'the subject, with its markers resolved');
        self::assertStringContainsString('no-reply@' . self::HOST, $envelope, 'and who it comes from');
        self::assertStringContainsString('ada@example.test', $envelope);

        self::assertEmailCount(0, message: 'a preview sends nothing');
    }

    /** And the send is the same form again, so what was previewed is what goes. */
    public function testThePreviewLeadsStraightToTheSend(): void
    {
        $template = $this->write('Hello', 'Hello', 'Hi.');
        $contact = $this->aContact(['email' => 'ada@example.test']);

        $preview = $this->previewFrom('contact', $contact, ['template' => $template]);
        $this->client->submit($preview->selectButton('Send')->form());

        self::assertEmailCount(1);
    }

    /** A subject typed for one send is not a subject that stopped taking markers. */
    public function testASubjectTypedForOneSendStillTakesMarkers(): void
    {
        $template = $this->write('Hello', 'The default subject', 'Hi.');
        $contact = $this->aContact(['email' => 'ada@example.test']);

        $this->sendFrom('contact', $contact, ['template' => $template, 'subject' => 'Urgent: [first_name]']);

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('Urgent: Ada', $message->getSubject());
    }

    // -- the timeline --------------------------------------------------------

    /** Who, when, which template, to what address, what subject. */
    public function testASendIsOnTheTimelineWithEverythingItWas(): void
    {
        $template = $this->write('Order confirmation', 'Hello [first_name]', 'Hi.');
        $contact = $this->aContact(['email' => 'ada@example.test']);

        $this->sendFrom('contact', $contact, ['template' => $template]);

        $entry = $this->latestHistory(ContactModule::KEY, $contact);

        self::assertSame(RecordAction::EmailSent, $entry->action);
        self::assertSame('Admin', $entry->userLabel);
        self::assertSame(
            ['template' => 'Order confirmation', 'recipient' => 'ada@example.test', 'subject' => 'Hello Ada'],
            $entry->email(),
        );

        $page = $this->client->request('GET', $this->url('/m/contact/' . $contact))->filter('main')->text();
        self::assertStringContainsString('Email sent', $page);
        self::assertStringContainsString('ada@example.test', $page);
    }

    /**
     * A failure is recorded as a failure, and this is the assertion the ticket
     * exists for.
     *
     * "Nothing in the timeline" and "it went out" must not look the same — and
     * they still would if a failed send were an `email_sent` row with a flag
     * inside it, because a timeline is read by scanning its verbs.
     */
    public function testAFailedSendIsOnTheTimelineAsAFailure(): void
    {
        $template = $this->write('Order confirmation', 'Hello', 'Hi.');
        $contact = $this->aContact(['email' => 'ada@example.test']);

        // Real credentials on a real tenant: XIV-37's guard refuses to build a
        // transport that could deliver, which is a send that fails without this
        // suite ever opening a socket.
        $this->useRealSmtp();

        $this->sendFrom('contact', $contact, ['template' => $template]);

        self::assertEmailCount(0, message: 'nothing left the building');

        $entry = $this->latestHistory(ContactModule::KEY, $contact);

        self::assertSame(RecordAction::EmailFailed, $entry->action);
        self::assertSame(
            ['template' => 'Order confirmation', 'recipient' => 'ada@example.test', 'subject' => 'Hello'],
            $entry->email(),
            'the attempt is recorded in the same detail a success is',
        );

        $page = $this->client->followRedirect()->filter('main')->text();
        self::assertStringContainsString('Email not sent', $page, 'and it reads as a failure on the record');
    }

    /** And the person who pressed the button is told, rather than left to guess. */
    public function testAFailedSendTellsThePersonWhoPressedTheButton(): void
    {
        $template = $this->write('Order confirmation', 'Hello', 'Hi.');
        $contact = $this->aContact(['email' => 'ada@example.test']);

        $this->useRealSmtp();
        $this->sendFrom('contact', $contact, ['template' => $template]);

        self::assertStringContainsString(
            'The message could not be sent',
            $this->client->followRedirect()->filter('main')->text(),
        );
    }

    // -- the permission ------------------------------------------------------

    /**
     * Sending is a grant of its own, and a sharper one than reading.
     *
     * Whoever may open an invoice is not whoever may send it to the customer it
     * is addressed to — which is the same split `templates` and `document`
     * already make, one step further along.
     */
    public function testSendingIsAPermissionOfItsOwn(): void
    {
        $template = $this->write('Hello', 'Hello', 'Hi.');
        $contact = $this->aContact(['email' => 'ada@example.test']);

        $this->grant(self::READER, ModuleAction::View);
        $this->grant(self::SENDER, ModuleAction::View);
        $this->grant(self::SENDER, ModuleAction::SendEmail);

        $this->signIn(self::READER);
        $page = $this->client->request('GET', $this->url('/m/contact/' . $contact));
        self::assertResponseIsSuccessful('reading the record is what they were granted');
        self::assertCount(0, $page->filter('a[href$="/m/contact/' . $contact . '/email"]'));

        $this->client->request('GET', $this->url('/m/contact/' . $contact . '/email'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'and sending is not');

        $this->signIn(self::SENDER);
        $this->client->request('GET', $this->url('/m/contact/' . $contact . '/email'));
        self::assertResponseIsSuccessful('whoever holds the grant may send');

        $this->sendFrom('contact', $contact, ['template' => $template]);
        self::assertEmailCount(1);
    }

    // -- helpers ------------------------------------------------------------

    /** Writes an email template through the browser and gives back its id. */
    private function write(string $name, string $subject, string $body, string $module = ContactModule::KEY): int
    {
        $crawler = $this->client->request('GET', $this->url('/m/' . $module . '/email-templates/new'));
        $this->client->submit($crawler->selectButton('Save')->form([
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
        ]));
        $this->client->followRedirect();

        $written = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(EmailTemplateRepository::class)->forModule($module),
        );

        self::assertNotEmpty($written, 'the template was accepted');

        return (int) end($written)->getId();
    }

    /**
     * Fills in the chooser on the record page and presses Send.
     *
     * Through the form the modal actually renders, so the test presses what a
     * person presses — including the CSRF token and the address the resolver
     * put in the field.
     *
     * **The redirect is deliberately not followed here.** The message logger is
     * reset between requests, so following it first would throw away the very
     * events the assertions are about — and every test that also wants the page
     * afterwards follows it itself, in the order it needs.
     *
     * @param array{template: int, subject?: string, recipient?: string} $values
     */
    private function sendFrom(string $module, int $id, array $values): void
    {
        $this->submitChooser($module, $id, $values, 'Send');
    }

    /**
     * The same form, out through the other button.
     *
     * @param array{template: int, subject?: string, recipient?: string} $values
     */
    private function previewFrom(string $module, int $id, array $values): Crawler
    {
        return $this->submitChooser($module, $id, $values, 'Preview and send');
    }

    /** @param array{template: int, subject?: string, recipient?: string} $values */
    private function submitChooser(string $module, int $id, array $values, string $button): Crawler
    {
        $page = $this->client->request('GET', $this->url(sprintf('/m/%s/%d', $module, $id)));
        $form = $page->selectButton($button)->form();

        $form['template'] = (string) $values['template'];
        $form['subject'] = $values['subject'] ?? '';

        if (isset($values['recipient'])) {
            $form['recipient'] = $values['recipient'];
        }

        return $this->client->submit($form);
    }

    /** A CSRF token for the send form, taken from a record that offers one. */
    private function tokenFrom(int $contact): string
    {
        return (string) $this->client
            ->request('GET', $this->url('/m/contact/' . $contact . '/email'))
            ->filter('input[name="_token"]')
            ->attr('value');
    }

    /** The customer's own SMTP server, which is what XIV-37's guard refuses. */
    private function useRealSmtp(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(TenantProfileManager::class)
            ->applyMail('billing@sending.test', self::REAL_SMTP, 587, 'sending', 'hunter2'));
    }

    private function latestHistory(string $module, int $id): HistoryEntry
    {
        $entries = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $id): array {
            $definition = self::service(MetadataRepository::class)->get($module);

            return self::service(HistoryRepository::class)->findFor($definition, $id, 1);
        });

        self::assertNotEmpty($entries, 'something was recorded');

        return $entries[0];
    }

    private function recordOf(string $module, int $id): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $id): Record {
            $definition = self::service(MetadataRepository::class)->get($module);
            $record = self::service(RecordRepository::class)->find($definition, $id);
            self::assertInstanceOf(Record::class, $record);

            return $record;
        });
    }

    /**
     * Puts something in the address field that the form would never accept.
     *
     * The field type validates on the way in (§5), so the only way a record
     * holds "ring him" is that it arrived before the field was an email field,
     * or through an import — both of which are real and neither of which should
     * turn into a send.
     */
    private function storeRawEmail(string $module, int $id, string $value): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $id, $value): void {
            $definition = self::service(MetadataRepository::class)->get($module);
            $records = self::service(RecordRepository::class);
            $record = $records->find($definition, $id);
            self::assertInstanceOf(Record::class, $record);

            $record->set('email', $value);
            $records->save($definition, $record);
        });
    }

    /** @param array<string, string> $values */
    private function aContact(array $values = []): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace', ...$values],
            variant: 'person',
        ));
    }

    private function anArticle(): int
    {
        return $this->savedId($this->saveRecord(ArticleModule::KEY, ['title' => 'A widget']));
    }

    private function anOrder(int $contact): int
    {
        return $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $contact,
            'ordered_on' => '2026-08-15',
            'status' => OrderModule::DRAFT,
        ]));
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

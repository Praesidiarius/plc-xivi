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
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\Mail\EmailRenderer;
use Xivi\Core\Mail\EmailTemplateRepository;
use Xivi\Core\Mail\RenderedEmail;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Record\RecordRepository;

/**
 * Email templates, written in the application rather than uploaded (XIV-38).
 *
 * The counterpart to {@see DocumentTemplateTest}, and the difference between the
 * two files is the difference the ticket is about: there is no fixture to build
 * here, no zip to assemble and no library to trust with somebody else's XML.
 * A template is a name, a subject and some Markdown, typed into a form.
 *
 * What that leaves worth proving is in three parts. That the form keeps what was
 * typed; that the placeholder list is the *same* one documents offer, from the
 * same class, rather than a second implementation that agrees today; and that
 * what comes out the other end is safe to put in somebody's inbox — which is
 * where the raw-HTML and sanitizer decisions are checked rather than described.
 *
 * The sending half is XIV-39's, so nothing here presses send. The renderer is
 * reached directly, which is exactly the seam that ticket will use.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class EmailTemplateTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_email_templates';
    private const string HOST = 'emails.localhost';
    private const string ADMIN = 'admin@emails.test';
    /** Whose session a record is saved under unless a test says otherwise (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string WRITER = 'writer@emails.test';
    private const string DESIGNER = 'designer@emails.test';
    private const string PASSWORD = 'emails-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            ),
        );

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        // The two halves of the ticket's permission split, one person each: the
        // one who words the emails, and the one who keeps the stationery.
        $users->create($this->tenant, self::WRITER, 'Writer', self::PASSWORD, []);
        $users->create($this->tenant, self::DESIGNER, 'Designer', self::PASSWORD, []);

        $this->signIn(self::ADMIN);
    }

    // -- writing them -------------------------------------------------------

    /** The whole shape of the ticket: a form, not an upload. */
    public function testATemplateIsWrittenInTheApplicationAndReadBack(): void
    {
        $this->write('Order confirmation', 'Your order [record_id]', 'Dear [first_name],');

        $page = $this->client->request('GET', $this->url('/m/contact/email-templates'))->filter('main')->text();

        self::assertStringContainsString('Order confirmation', $page);
        self::assertStringContainsString('Your order [record_id]', $page, 'the subject is part of the template');
    }

    /** Nothing has to be uploaded again to fix a typo, which is the point. */
    public function testATemplateIsEditedInPlace(): void
    {
        $id = $this->write('Reminder', 'Overdue', 'Please pay.');

        $crawler = $this->client->request('GET', $this->url('/m/contact/email-templates/' . $id));
        $this->client->submit($crawler->selectButton('Save')->form([
            'name' => 'Reminder',
            'subject' => 'Still overdue',
            'body' => 'Please pay soon.',
        ]));

        $page = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('Still overdue', $page);
        self::assertStringNotContainsString('Overdue,', $page);
        self::assertSame('Please pay soon.', $this->template($id)->getBody());
    }

    public function testATemplateCanBeRemoved(): void
    {
        $this->write('Reminder', 'Overdue', 'Please pay.');

        $crawler = $this->client->request('GET', $this->url('/m/contact/email-templates'));
        $this->client->submit($crawler->selectButton('Delete')->form());

        $page = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('No email templates yet', $page);
    }

    /**
     * A body somebody spent ten minutes on is not lost to an empty subject line.
     *
     * There is nothing to fall back to here, unlike the document side where a
     * template nobody named can be called after its file — so the form refuses,
     * and refusing has to mean handing the work back rather than dropping it.
     */
    public function testAnIncompleteTemplateIsRefusedWithTheTypingStillInTheForm(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/email-templates/new'));
        $crawler = $this->client->submit($crawler->selectButton('Save')->form([
            'name' => 'Reminder',
            'subject' => '',
            'body' => 'Ten minutes of careful wording.',
        ]));

        self::assertStringContainsString('needs a name, a subject and a message', $crawler->filter('main')->text());
        self::assertStringContainsString('Ten minutes of careful wording.', $crawler->filter('textarea[name="body"]')->text());
        self::assertSame([], $this->templatesOfContact(), 'and nothing was written down');
    }

    // -- the placeholders, which are the document ones --------------------

    /**
     * The reason the form page is worth opening, and the ticket's sharpest
     * requirement: the same marker set documents offer, not a second one.
     */
    public function testThePageListsAPlaceholderForEveryFieldOfEveryVariant(): void
    {
        $page = $this->client->request('GET', $this->url('/m/contact/email-templates/new'))->filter('main')->text();

        foreach (['[first_name]', '[last_name]', '[email]', '[company_name]', '[record_id]'] as $marker) {
            self::assertStringContainsString($marker, $page);
        }
    }

    /**
     * A field added this morning is a marker in an email this afternoon.
     *
     * The claim the whole reuse rests on, checked against the customer's own
     * definitions rather than against a list in this repository.
     */
    public function testAFieldAddedTodayIsAMarkerToday(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/fields'));

        // The module's own shape is the first one the editor draws; the rest are
        // its collections, and a nickname does not belong to an address.
        $this->client->submit($crawler->filter('form[action$="/fields/add"]')->first()->form([
            'key' => 'nickname',
            'label' => 'Nickname',
            'type' => 'text',
        ]));

        $page = $this->client->request('GET', $this->url('/m/contact/email-templates/new'))->filter('main')->text();

        self::assertStringContainsString('[nickname]', $page);
    }

    /** A date is not a property of a person or of a company (§5.7). */
    public function testMarkersThatAreNotAboutTheRecordHaveTheirOwnSection(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/email-templates/new'));

        $general = $crawler->filter('#general-markers')->text();

        foreach (['[today]', '[tenant.name]', '[user.name]'] as $marker) {
            self::assertStringContainsString($marker, $general);
        }

        self::assertStringNotContainsString('[first_name]', $general);
    }

    /**
     * Repeating blocks are out of scope, and the page does not pretend otherwise.
     *
     * `RepeatingBlocks` scans Word's `<w:tr>` elements — the table row is the
     * unit because it is the unit Word gives a person, and Markdown has none.
     * Offering `[addresses.street]` here would be advertising something that
     * comes out blank, so it is not offered.
     */
    public function testCollectionPlaceholdersAreNotOffered(): void
    {
        $page = $this->client->request('GET', $this->url('/m/contact/email-templates/new'))->filter('main')->text();

        self::assertStringNotContainsString('[addresses.street]', $page);
    }

    /** And one written by hand anyway comes out blank rather than as brackets. */
    public function testACollectionPlaceholderWrittenAnywayComesOutBlank(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', 'You live at [addresses.street].'),
            $this->aContact(),
        );

        self::assertStringContainsString('You live at .', $rendered->text);
    }

    // -- what comes out ------------------------------------------------------

    /** Subject and body take the same markers, filled in from the same values. */
    public function testMarkersAreSubstitutedIntoBothTheSubjectAndTheBody(): void
    {
        $id = $this->aContact();
        $rendered = $this->render(
            $this->write('Letter', 'Hello [first_name] — record [record_id]', 'Dear [first_name] [last_name],'),
            $id,
        );

        self::assertSame('Hello Ada — record ' . $id, $rendered->subject);
        self::assertStringContainsString('Dear Ada Lovelace,', $rendered->text);
        self::assertStringContainsString('Dear Ada Lovelace,', $rendered->html);
    }

    /**
     * XIV-39 lets somebody type a different subject for one send, and a subject
     * typed by hand is not a subject that stopped understanding placeholders.
     */
    public function testASubjectGivenForOneSendStillTakesMarkers(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'The default', 'Dear [first_name],'),
            $this->aContact(),
            'Urgent: [first_name]',
        );

        self::assertSame('Urgent: Ada', $rendered->subject);
    }

    /** Markdown becomes HTML, and stays itself for the plain-text half. */
    public function testTheBodyBecomesHtmlAndItsSourceIsTheTextAlternative(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', "Dear **[first_name]**,\n\n- one\n- two"),
            $this->aContact(),
        );

        self::assertStringContainsString('<strong>Ada</strong>', $rendered->html);
        self::assertStringContainsString('<li>one</li>', $rendered->html);

        // The thing somebody typed *is* the text alternative, markers filled in.
        // Nothing generated it by stripping tags out of the HTML, which is the
        // step that quietly produces a text part nobody would want to read.
        self::assertSame("Dear **Ada**,\n\n- one\n- two", $rendered->text);
    }

    /**
     * Raw HTML is disabled, which is the ticket's "sanitize or disable" answered
     * in the first of two ways.
     *
     * A template author is a signed-in colleague rather than a stranger, so this
     * is not really about them — it is what makes the *values* substituted into
     * the source safe, since a record's data goes into the Markdown before the
     * parser reads it.
     */
    public function testRawHtmlInATemplateIsSentAsTheCharactersSomebodyTyped(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', 'Hello <script>alert(1)</script> there.'),
            $this->aContact(),
        );

        self::assertStringNotContainsString('<script>', $rendered->html);
        self::assertStringContainsString('&lt;script&gt;', $rendered->html);
    }

    /** And the same holds for a value that arrived from a customer's own record. */
    public function testAMarkerValueContainingMarkupDoesNotBecomeMarkup(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello [first_name]', 'Dear [first_name],'),
            $this->aContact(['first_name' => '<script>alert(1)</script>']),
        );

        self::assertStringNotContainsString('<script>', $rendered->html);
        self::assertStringContainsString('&lt;script&gt;', $rendered->html);
    }

    /**
     * The second of the two, and the reason both are here: this needs no raw HTML
     * at all.
     *
     * `[click](javascript:…)` is ordinary Markdown, so escaping raw HTML would
     * not have caught it. The sanitizer — Symfony's, configured in
     * config/packages/html_sanitizer.yaml — is what enforces which schemes a
     * link may use as a policy rather than as a parser setting.
     */
    public function testALinkWithAnUnsafeSchemeDoesNotSurvive(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', '[press here](javascript:alert(1)) and [home](https://example.test).'),
            $this->aContact(),
        );

        self::assertStringNotContainsString('javascript:', $rendered->html);
        self::assertStringContainsString('https://example.test', $rendered->html, 'an ordinary link is left alone');
    }

    // -- the base template ---------------------------------------------------

    /**
     * The wrapper ships in code, and this is what "a tenant cannot edit it"
     * means in practice: two templates with nothing in common come out inside
     * the identical skeleton, and neither wrote a line of it.
     */
    public function testEveryEmailIsDrawnInTheSameBaseTemplate(): void
    {
        $id = $this->aContact();

        $first = $this->render($this->write('One', 'Subject', 'Alpha.'), $id);
        $second = $this->render($this->write('Two', 'Subject', 'Beta.'), $id);

        self::assertStringStartsWith('<!doctype html>', $first->html, 'a whole document, not a fragment');
        self::assertStringContainsString('<p>Alpha.</p>', $first->html);

        // The same wrapper on both, found by taking each one's content out of it.
        self::assertSame(
            str_replace('<p>Alpha.</p>', '', $first->html),
            str_replace('<p>Beta.</p>', '', $second->html),
        );
    }

    /** And the form offers no way to change it — there is no field for it. */
    public function testTheFormOffersNothingThatWouldEditTheWrapper(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/email-templates/new'));

        $named = $crawler->filter('form input[name], form textarea[name], form select[name]')
            ->each(static fn ($node): string => (string) $node->attr('name'));

        self::assertSame(['_token', 'name', 'variant', 'subject', 'body'], $named);
    }

    // -- variants, and the permission ----------------------------------------

    /**
     * A mail to a person is not a mail to a company (§5.5).
     *
     * Asked of the repository rather than of a page, because the page that
     * offers templates on a record is XIV-39's — this is the answer that ticket
     * will be reading.
     */
    public function testATemplateForOneVariantIsOnlyOfferedOnThatKind(): void
    {
        $this->write('To a company', 'Hello', 'Dear [company_name],', ContactModule::COMPANY);
        $this->write('To anybody', 'Hello', 'Hello there.');

        $forPeople = array_map(
            static fn ($t): string => $t->getName(),
            $this->offeredFor(ContactModule::PERSON),
        );

        self::assertSame(['To anybody'], $forPeople);
        self::assertCount(2, $this->offeredFor(ContactModule::COMPANY));
    }

    /**
     * The ticket asked for writing templates to be its own permission, and this
     * is what that buys.
     *
     * Not a reuse of `templates`: the .docx is a design job and this is a
     * wording job, and a customer with a designer and a lawyer has two people.
     * Sending, which is sharper than either, is XIV-39's third.
     */
    public function testWritingEmailsIsNotTheSameGrantAsKeepingTheStationery(): void
    {
        $this->grant(self::WRITER, ModuleAction::EmailTemplates);
        $this->grant(self::DESIGNER, ModuleAction::Templates);

        $this->signIn(self::WRITER);
        $this->client->request('GET', $this->url('/m/contact/email-templates'));
        self::assertResponseIsSuccessful('whoever words the emails may open them');

        $this->client->request('GET', $this->url('/m/contact/templates'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'and not the document templates');

        $this->signIn(self::DESIGNER);
        $this->client->request('GET', $this->url('/m/contact/templates'));
        self::assertResponseIsSuccessful('whoever designs the stationery may open that');

        $this->client->request('GET', $this->url('/m/contact/email-templates'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'and not what the emails say');
    }

    // -- helpers ------------------------------------------------------------

    /** Writes a template through the browser and gives back its id. */
    private function write(string $name, string $subject, string $body, ?string $variant = null): int
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/email-templates/new'));

        $values = ['name' => $name, 'subject' => $subject, 'body' => $body];

        if ($variant !== null) {
            $values['variant'] = $variant;
        }

        $this->client->submit($crawler->selectButton('Save')->form($values));
        $this->client->followRedirect();

        $written = $this->templatesOfContact();
        self::assertNotEmpty($written, 'the template was accepted');

        return (int) end($written)->getId();
    }

    /**
     * One template, filled in for one record.
     *
     * This is the seam XIV-39's preview and send will both come through, which
     * is why it is exercised as a service rather than through a page that does
     * not exist yet.
     */
    private function render(int $templateId, int $recordId, ?string $subject = null): RenderedEmail
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function () use ($templateId, $recordId, $subject): RenderedEmail {
                $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
                $record = self::service(RecordRepository::class)->find($module, $recordId);
                self::assertNotNull($record);

                $template = self::service(EmailTemplateRepository::class)->find(ContactModule::KEY, $templateId);
                self::assertNotNull($template);

                return self::service(EmailRenderer::class)->render($template, $module, $record, $subject);
            },
        );
    }

    /** @return list<\Xivi\Core\Entity\EmailTemplate> */
    private function templatesOfContact(): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(EmailTemplateRepository::class)->forModule(ContactModule::KEY),
        );
    }

    /** @return list<\Xivi\Core\Entity\EmailTemplate> */
    private function offeredFor(string $variant): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(EmailTemplateRepository::class)->forRecord(ContactModule::KEY, $variant),
        );
    }

    private function template(int $id): \Xivi\Core\Entity\EmailTemplate
    {
        $template = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(EmailTemplateRepository::class)->find(ContactModule::KEY, $id),
        );

        self::assertNotNull($template);

        return $template;
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

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
        // Walked rather than posted, which is the cheapest way to keep this
        // honest about the editor it goes through ([XIV-163]): the doors, the
        // kinds of field, then the form for the one that was chosen. The
        // module's own shape is the first set of doors the page draws; the rest
        // belong to its collections, and a nickname does not belong to an
        // address.
        $crawler = $this->client->request('GET', $this->url('/m/contact/fields'));
        $crawler = $this->client->click($crawler->filter('main a[href$="/add"]')->first()->link());
        $crawler = $this->client->click($crawler->filter('main a[href$="/add/text"]')->first()->link());

        $this->client->submit($crawler->selectButton('Add')->form([
            'key' => 'nickname',
            'label' => 'Nickname',
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
     * The collections section, which XIV-38 deliberately did not have (XIV-62).
     *
     * The tokens offered are this page's own — `[addresses]`, the whole table —
     * and not the document page's `[addresses.street]`, which names a column
     * there and is a one-column table here. A panel that listed the column form
     * would be teaching somebody the vocabulary of the other screen.
     */
    public function testTheCollectionIsOfferedAsOneTokenThatMakesATable(): void
    {
        $panel = $this->client->request('GET', $this->url('/m/contact/email-templates/new'))
            ->filter('.email-collection-markers')->text();

        self::assertStringContainsString('[addresses]', $panel);
        self::assertStringNotContainsString('[addresses.street]', $panel, 'a column token belongs to the document page');
    }

    /**
     * And the panel says what it produces, rather than listing it beside the
     * fields as though it were one of them.
     *
     * `[addresses]` sits in a list next to `[first_name]` looking exactly like
     * it, and one of the two expands to a whole table. Somebody who finds that
     * out from the email they have just sent found out too late.
     */
    public function testThePanelSaysWhatACollectionTokenProduces(): void
    {
        $panel = $this->client->request('GET', $this->url('/m/contact/email-templates/new'))
            ->filter('.email-collection-markers')->text();

        self::assertStringContainsString('whole table', $panel);
        // The named-column form, as a worked example built from this
        // collection's own fields rather than as a sentence of grammar.
        self::assertStringContainsString('[addresses.label,street,postal_code,city,country]', $panel);
    }

    /** A collection written into the body renders its rows, which is the ticket. */
    public function testACollectionWrittenIntoTheBodyRendersItsRows(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', "Where you are:\n\n[addresses]"),
            $this->aContactWithAddresses([
                ['label' => 'Home', 'street' => 'Bahnhofstrasse 1', 'city' => 'Zürich'],
                ['label' => 'Office', 'street' => 'Seestrasse 2', 'city' => 'Bern'],
            ]),
        );

        // Markdown, in the source, which is the whole safety argument — see
        // testACollectionValueContainingMarkupArrivesAsText.
        self::assertStringContainsString('| Bahnhofstrasse 1 |', $rendered->text);
        self::assertStringContainsString('| Seestrasse 2 |', $rendered->text);

        // And a real table by the time it is HTML, which needs CommonMark's
        // table extension: without it this is a paragraph of pipe characters.
        self::assertStringContainsString('<table>', $rendered->html);
        self::assertStringContainsString('<td>Bahnhofstrasse 1</td>', $rendered->html);
        self::assertStringContainsString('<th>Street</th>', $rendered->html, "the field's own label, as the heading");
    }

    /**
     * The sharp part of XIV-62, and the reason the expansion is Markdown rather
     * than HTML.
     *
     * A `[lines]` that produced HTML would arrive *after* `html_input: escape`
     * had done its work and would hand raw markup to the sanitizer as its only
     * defence. Producing source keeps §5.13's property instead: the value goes
     * in as text and CommonMark is what decides it is text.
     */
    public function testACollectionValueContainingMarkupArrivesAsText(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', "Where you are:\n\n[addresses]"),
            $this->aContactWithAddresses([['label' => 'Home', 'street' => '<script>alert(1)</script>']]),
        );

        self::assertStringNotContainsString('<script>', $rendered->html);
        self::assertStringContainsString('&lt;script&gt;', $rendered->html);
        self::assertStringContainsString('<td>&lt;script&gt;alert(1)&lt;/script&gt;</td>', $rendered->html);
    }

    /**
     * A value containing the table's own punctuation does not break the table.
     *
     * The one cost of choosing a pipe table over HTML, and showing that it is
     * paid is this test's whole job.
     */
    public function testAValueContainingAPipeDoesNotBreakTheTable(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', "Where you are:\n\n[addresses.street,city]"),
            $this->aContactWithAddresses([['street' => 'Werkgasse 3 | rear entrance', 'city' => 'Basel']]),
        );

        self::assertStringContainsString('Werkgasse 3 \| rear entrance', $rendered->text, 'escaped in the source');
        self::assertStringContainsString('<td>Werkgasse 3 | rear entrance</td>', $rendered->html, 'one cell, pipe and all');
        self::assertStringContainsString('<td>Basel</td>', $rendered->html, 'and the next column is still the next column');
    }

    /** Naming the columns keeps the marker flat, with no block syntax to learn. */
    public function testNamingColumnsPicksThemAndTheirOrder(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', "Where you are:\n\n[addresses.city,street]"),
            $this->aContactWithAddresses([['label' => 'Home', 'street' => 'Bahnhofstrasse 1', 'city' => 'Zürich']]),
        );

        self::assertStringContainsString('| Zürich | Bahnhofstrasse 1 |', $rendered->text);
        self::assertStringNotContainsString('Home', $rendered->text, 'a column nobody named is not drawn');
    }

    /**
     * The plain-text alternative is still the thing somebody would read.
     *
     * §5.13's argument for Markdown was that the source *is* the text part. A
     * table rendered as HTML would have left that half a stripped-tag mess or
     * nothing at all; a pipe table is a table a person reads in a terminal.
     */
    public function testTheTextAlternativeIsStillReadable(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', "Where you are:\n\n[addresses.street,city]\n\nThanks."),
            $this->aContactWithAddresses([['street' => 'Bahnhofstrasse 1', 'city' => 'Zürich']]),
        );

        self::assertSame(
            "Where you are:\n\n| Street | City |\n| --- | --- |\n| Bahnhofstrasse 1 | Zürich |\n\nThanks.",
            $rendered->text,
        );
    }

    /**
     * A marker sharing its line with a sentence still gets the blank lines a
     * block needs.
     *
     * A pipe table is a block, so without them CommonMark reads it as more of
     * the paragraph it interrupts and the HTML half silently becomes a line of
     * pipe characters. The source is measured rather than padded blindly, which
     * is why the well-spaced case above keeps its exact spacing.
     */
    public function testATableInterruptingAParagraphIsStillATable(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', "Where you are:\n[addresses.street]\nThanks."),
            $this->aContactWithAddresses([['street' => 'Bahnhofstrasse 1']]),
        );

        self::assertStringContainsString('<table>', $rendered->html);
        self::assertStringContainsString('<td>Bahnhofstrasse 1</td>', $rendered->html);
        self::assertStringContainsString("Where you are:\n\n| Street |", $rendered->text);
    }

    /**
     * An empty collection leaves nothing behind, which is §5.11's call carried
     * over unchanged.
     *
     * Not a header with no rows under it: a heading over an empty space reads as
     * a defect, and a message that simply does not mention the list is the
     * sensible email for a record with none.
     */
    public function testACollectionWithNoRowsDrawsNothing(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', "Where you are:\n\n[addresses]\n\nThanks."),
            $this->aContact(),
        );

        self::assertStringNotContainsString('|', $rendered->text);
        self::assertStringNotContainsString('<table>', $rendered->html);
        self::assertStringContainsString('Thanks.', $rendered->text);
    }

    /**
     * A table is not a subject line, so a collection marker in one is blank.
     *
     * Blank rather than left as brackets: `addresses` is a word the engine
     * knows, and every marker the engine knows and cannot fill is blanked (§5.7).
     */
    public function testACollectionMarkerInTheSubjectComesOutBlank(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Your addresses [addresses]', 'Hello.'),
            $this->aContactWithAddresses([['street' => 'Bahnhofstrasse 1']]),
        );

        self::assertSame('Your addresses', trim($rendered->subject));
    }

    /**
     * A token nothing answers is still printed as it was typed (XIV-25).
     *
     * The substitution stopped being a `strtr()` for this ticket, and that rule
     * is the one thing about it a change of mechanism could most easily have
     * dropped without anything noticing.
     */
    public function testATokenNothingAnswersIsStillPrintedAsTyped(): void
    {
        $rendered = $this->render(
            $this->write('Letter', 'Hello', 'Dear [contacŧ],'),
            $this->aContact(),
        );

        self::assertStringContainsString('[contacŧ]', $rendered->text);
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

    /**
     * A contact with rows in its collection, for the tables XIV-62 renders.
     *
     * @param list<array<string, string>> $addresses
     */
    private function aContactWithAddresses(array $addresses): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            ['addresses' => array_map(static fn (array $fields): array => self::row($fields), $addresses)],
            variant: 'person',
        ));
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

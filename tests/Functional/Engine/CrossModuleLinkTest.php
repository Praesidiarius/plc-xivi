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

use App\Controller\ModuleController;
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
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Field\Autocomplete;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Form\RecordReferenceType;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * A link that leaves its own module (§7.6, XIV-13).
 *
 * Contact already links to contact, which proved a link can be a field type and
 * proved nothing about crossing a module boundary — the module key was its own
 * on both sides, so every lookup happened to land somewhere it already was.
 *
 * Here an article gets a field pointing at a contact, which is the shape an
 * order line will have. Built through the metadata editor rather than by
 * changing the article module, because a customer adding a link between two
 * modules they have is exactly the case this must support (§5.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CrossModuleLinkTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_cross_links';
    private const string HOST = 'crosslinks.localhost';
    private const string ADMIN = 'admin@crosslinks.test';
    /** Whose session a record is saved under unless a test says otherwise (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string MEMBER = 'member@crosslinks.test';
    private const string PASSWORD = 'crosslinks-password';
    private const string FORM = 'module_record';

    /** The field added to article, pointing at a contact. */
    private const string SUPPLIER = 'supplier';

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

            $installer->install($registry->get(ContactModule::KEY));
            $article = $installer->install($registry->get(ArticleModule::KEY));

            // The link itself: a customer's own field, added in the editor,
            // pointing at a module the article package knows nothing about.
            if ($article->getField(self::SUPPLIER) === null) {
                self::service(MetadataEditor::class)->addField(
                    $article,
                    self::SUPPLIER,
                    'Supplier',
                    'reference',
                    filterable: true,
                    // A column too, so the list is one of the places the link
                    // has to work (XIV-42).
                    listed: true,
                    options: ['module' => ContactModule::KEY, 'variant' => ContactModule::COMPANY],
                );
            }
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);

        $this->signIn(self::ADMIN);
    }

    /** The picker offers the other module's records, narrowed to its variant. */
    public function testTheFormOffersRecordsOfTheOtherModule(): void
    {
        $this->aCompany('Acme AG');
        $this->aPerson('Ada', 'Lovelace');

        $crawler = $this->client->request('GET', $this->url('/m/article/new?variant=' . ArticleModule::PLAIN));
        $options = self::optionsOf($crawler, sprintf('select[name="%s"]', self::field(self::SUPPLIER)));

        self::assertContains('Acme AG', $options);
        self::assertNotContains('Ada Lovelace', $options, 'narrowed to companies');
    }

    /** And the record page names the linked record rather than printing an id. */
    public function testALinkedRecordIsNamedOnThePage(): void
    {
        $company = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', $company);

        $page = $this->client->request('GET', $this->url('/m/article/' . $article))->filter('main')->text();

        self::assertStringContainsString('Acme AG', $page);
        self::assertStringNotContainsString('#' . $company, $page);
    }

    /**
     * The page is two columns, whatever is on it.
     *
     * The sidebar is laid out after the main content, and a grid row places
     * things in order — so a second card competing for the main column used to
     * push the sidebar down beside the *last* of them, leaving a gap above it.
     * One column holding the cards, rather than a column per card.
     */
    public function testTheSidebarStaysBesideTheMainColumn(): void
    {
        $company = $this->aCompany('Acme AG');
        $this->anArticle('Desk lamp', $company);
        $this->anArticle('Desk chair', $company);

        $row = $this->client->request('GET', $this->url('/m/contact/' . $company))
            ->filter('main > .row')
            ->first();

        self::assertCount(1, $row->children('.col-lg-8'), 'one main column, not one per card');
        self::assertCount(1, $row->children('.col-lg-4'), 'and one sidebar beside it');
        self::assertGreaterThan(
            1,
            $row->filter('.col-lg-8 .card')->count(),
            'with more than one card inside the main column, which is the case that broke',
        );
    }

    /**
     * The list of what points here is folded away until asked for.
     *
     * A contact with forty invoices is otherwise a page you scroll past to reach
     * anything else. The heading and the count stay visible, which is the part
     * somebody came to read.
     *
     * Asserted on the `open` attribute rather than on the text, because a closed
     * `<details>` still has all of its content in the document — which is what
     * makes it work without JavaScript and also what makes every other test here
     * unable to tell the difference.
     */
    public function testWhatPointsHereIsCollapsedUntilOpened(): void
    {
        $company = $this->aCompany('Acme AG');
        $this->anArticle('Desk lamp', $company);

        $group = $this->client->request('GET', $this->url('/m/contact/' . $company))
            ->filter('main details.linked-group');

        self::assertCount(1, $group, 'the group is a details element');
        self::assertNull($group->attr('open'), 'and it starts closed');
        self::assertStringContainsString('1', $group->filter('summary .badge')->text(), 'the count is on the summary');
        self::assertStringContainsString('Desk lamp', $group->text(), 'and what is inside is still in the page');
    }

    /**
     * A link is drawn, never *stored* in the value (XIV-42).
     *
     * The mistake this feature invites is returning an `<a>` from `display()`,
     * which would work on a page and then arrive inside a Word document, a
     * spreadsheet cell, and the `<option>` label of every reference picker —
     * because `recordTitle()` is built from the display of the title fields.
     *
     * So the anchor belongs to the template, and the value stays text. This is
     * the test that says so, and it is here rather than anywhere prettier
     * because it is the regression that would be embarrassing rather than
     * merely wrong.
     */
    public function testTheValueItselfStaysPlainText(): void
    {
        $company = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', $company);

        $shown = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($article): string {
            $articles = self::service(MetadataRepository::class)->get(ArticleModule::KEY);
            $record = self::service(RecordRepository::class)->find($articles, $article);
            self::assertNotNull($record);

            $field = $articles->getField(self::SUPPLIER);
            self::assertNotNull($field);

            return self::service(FieldTypeRegistry::class)
                ->get($field->getType())
                ->display($record->get(self::SUPPLIER), $field);
        });

        self::assertSame('Acme AG', $shown, 'the name, and nothing wrapped around it');
    }

    /**
     * A picker at its ceiling says so (XIV-35).
     *
     * The cap has always been there and always been silent, so a company that
     * could not be linked to looked exactly like a company that did not exist.
     * The count comes from the same access predicate as the options, because a
     * total that included records the reader may not see would say how many
     * exist one integer at a time — which is the leak scoping the picker closed.
     *
     * **The field is told to stay a plain select first** (XIV-36), and that is
     * the whole relationship between the two tickets. A ceiling and an apology
     * for it go together: `never` keeps both, and under the default a picker
     * with two hundred and five candidates is a search box that pages through
     * every one of them, so there is nothing left to apologise for. The notice
     * has not moved and still fires for exactly the case it was written for.
     */
    public function testAPickerSaysWhenItIsShowingOnlyTheFirstFew(): void
    {
        $wanted = RecordReferenceType::MAX_CHOICES + 5;

        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($wanted): void {
            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $writer = self::service(RecordWriter::class);

            foreach (range(1, $wanted) as $n) {
                $writer->save($contacts, new Record(data: [
                    'kind' => ContactModule::COMPANY,
                    'company_name' => sprintf('Company %03d', $n),
                ]));
            }
        });

        $this->keepsAPlainSelect();

        $help = $this->client->request('GET', $this->url('/m/article/new?variant=' . ArticleModule::PLAIN))
            ->filter(sprintf('#%s_help', str_replace(['[', ']'], ['_', ''], self::field(self::SUPPLIER))));

        self::assertCount(1, $help, 'the picker explains itself');
        self::assertStringContainsString((string) RecordReferenceType::MAX_CHOICES, $help->text());
        self::assertStringContainsString((string) $wanted, $help->text(), 'and says how many there really are');
    }

    /**
     * Says `never` on the supplier field, through the editor rather than by
     * reaching into the definition (XIV-36).
     *
     * Through `updateField()` on purpose: it is the same merge a customer's own
     * save goes through, so this exercises the option arriving the way it really
     * arrives instead of a shortcut that would still pass if the merge dropped
     * it.
     */
    private function keepsAPlainSelect(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $article = self::service(MetadataRepository::class)->get(ArticleModule::KEY);
            $field = $article->getField(self::SUPPLIER);
            self::assertNotNull($field);

            self::service(MetadataEditor::class)->updateField(
                field: $field,
                label: $field->getLabel(),
                required: $field->isRequired(),
                unique: $field->isUnique(),
                filterable: $field->isFilterable(),
                listed: $field->isListed(),
                title: $field->isTitle(),
                position: $field->getPosition(),
                options: [Autocomplete::OPTION => Autocomplete::Never->value],
                width: $field->getWidth(),
            );
        });
    }

    /** And the name is a way to get there (XIV-42). */
    public function testALinkedRecordIsAWayToOpenIt(): void
    {
        $company = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', $company);

        $crawler = $this->client->request('GET', $this->url('/m/article/' . $article));
        $link = $crawler->filter(sprintf('main a[href$="/m/contact/%d"]', $company));

        self::assertCount(1, $link, 'the supplier is a link to the supplier');
        self::assertSame('Acme AG', $link->text(), 'and it is the name that is the link');
    }

    /** The same in a list column, where a reference is most worth clicking. */
    public function testALinkedRecordIsClickableFromTheList(): void
    {
        $company = $this->aCompany('Acme AG');
        $this->anArticle('Desk lamp', $company);

        $crawler = $this->client->request('GET', $this->url('/m/article'));

        self::assertCount(
            1,
            $crawler->filter(sprintf('main table a[href$="/m/contact/%d"]', $company)),
            'the column links to the record it names',
        );
    }

    /**
     * A reference at a record that is gone stays text (§7.6).
     *
     * A stale link reads as `#id` and always has; what must not happen is an
     * anchor to a page that answers 404, which is worse than the text it
     * replaced.
     */
    public function testAStaleReferenceIsNotALink(): void
    {
        $company = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', $company);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($company): void {
            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $record = self::service(RecordRepository::class)->find($contacts, $company);
            self::assertNotNull($record);
            self::service(RecordWriter::class)->delete($contacts, $record);
        });

        $crawler = $this->client->request('GET', $this->url('/m/article/' . $article));

        self::assertStringContainsString('#' . $company, $crawler->filter('main')->text(), 'it says the link is stale');
        self::assertCount(
            0,
            $crawler->filter(sprintf('main a[href$="/m/contact/%d"]', $company)),
            'and does not offer to open what is not there',
        );
    }

    /**
     * Somebody who may not open the target sees its name and no link (XIV-42).
     *
     * Both halves matter. Hiding the name would leave an article whose supplier
     * nobody can read; offering the link would offer a door that answers 404,
     * since a record you may not view is one this application says does not
     * exist rather than one it refuses (§8.4).
     */
    public function testAReferenceIsNotALinkForSomebodyWhoCannotOpenIt(): void
    {
        $company = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', $company);

        // Articles but not contacts: the case a picker was scoped for in XIV-13,
        // one page over.
        $this->grant(self::MEMBER, ArticleModule::KEY, ModuleAction::View);
        $this->grant(self::MEMBER, ArticleModule::KEY, ModuleAction::List);
        $this->signIn(self::MEMBER);

        $crawler = $this->client->request('GET', $this->url('/m/article/' . $article));

        self::assertStringContainsString('Acme AG', $crawler->filter('main')->text(), 'the name is still readable');
        self::assertCount(
            0,
            $crawler->filter(sprintf('main a[href$="/m/contact/%d"]', $company)),
            'and no link is offered to a page that would 404',
        );
    }

    /**
     * The reverse, which is the half that did not exist: a contact's page lists
     * the articles naming it, across the module boundary.
     */
    public function testTheLinkedRecordListsWhatPointsAtItFromAnotherModule(): void
    {
        $company = $this->aCompany('Acme AG');
        $this->anArticle('Desk lamp', $company);

        $page = $this->client->request('GET', $this->url('/m/contact/' . $company))->filter('main')->text();

        self::assertStringContainsString('Articles', $page, 'grouped by the module that points here');
        self::assertStringNotContainsString('Contacts 1', $page, 'and not filed under this record\'s own module');
        self::assertStringContainsString('Desk lamp', $page);
    }

    /**
     * More linked records than the card shows (XIV-52).
     *
     * The case that used to be silent: the card read the first few and counted
     * the array it had just capped, so a contact with 207 orders was reported as
     * having as many as happened to fit. The badge is the count now, and the card
     * says what it is holding back and where the rest are.
     */
    public function testACardSaysHowManyItIsNotShowing(): void
    {
        $company = $this->aCompany('Acme AG');
        $total = ModuleController::LINKED_ON_RECORD + 2;

        for ($i = 1; $i <= $total; ++$i) {
            $this->anArticle(sprintf('Lamp %02d', $i), $company);
        }

        $page = $this->client->request('GET', $this->url('/m/contact/' . $company))->filter('main');
        $text = $page->text();

        self::assertStringContainsString(
            sprintf('Articles %d', $total),
            $text,
            'the badge counts what points here, not what fitted on the card',
        );
        self::assertStringContainsString(
            sprintf('Showing %d of %d.', ModuleController::LINKED_ON_RECORD, $total),
            $text,
            'and the card admits it is holding some back',
        );
        // Newest first, so the two oldest are the ones off the end of the card.
        self::assertStringNotContainsString('Lamp 01', $text, 'which it is: this one is not on the page');

        // The way to the rest: the article list, filtered to this contact — the
        // same URL the filter bar would produce (XIV-13).
        $link = $page->filter(sprintf(
            'a[href*="filter%%5B0%%5D%%5Bpath%%5D=supplier"][href*="filter%%5B0%%5D%%5Bvalue%%5D=%d"]',
            $company,
        ));

        self::assertCount(1, $link, 'a link to the whole list, filtered to this record');

        $listed = $this->client->request('GET', (string) $link->attr('href'))->filter('table')->text();
        self::assertStringContainsString('Lamp 01', $listed, 'and it really does reach the ones the card left out');
    }

    /** A card showing everything says nothing extra — "showing 2 of 2" is noise. */
    public function testACardShowingEverythingSaysNothingExtra(): void
    {
        $company = $this->aCompany('Beta GmbH');
        $this->anArticle('Desk lamp', $company);
        $this->anArticle('Cable', $company);

        $page = $this->client->request('GET', $this->url('/m/contact/' . $company))->filter('main')->text();

        self::assertStringContainsString('Articles 2', $page);
        self::assertStringNotContainsString('Showing', $page);
    }

    /** Filtering through the link: "articles whose supplier is in Zürich". */
    public function testTheListCanFilterThroughALink(): void
    {
        $acme = $this->aCompany('Acme AG');
        $other = $this->aCompany('Beta GmbH');

        $this->anArticle('Desk lamp', $acme);
        $this->anArticle('Cable', $other);

        $table = $this->client->request('GET', $this->url(
            '/m/article?filter[0][path]=supplier.company_name&filter[0][op]=eq&filter[0][value]=Acme+AG',
        ))->filter('table')->text();

        self::assertStringContainsString('Desk lamp', $table);
        self::assertStringNotContainsString('Cable', $table);
    }

    /** And the filter bar offers the path, so nobody has to know the syntax. */
    public function testTheFilterBarOffersPathsThroughALink(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/article'));
        $options = self::optionsOf($crawler, 'select[name="filter[0][path]"]');

        self::assertContains('Supplier: Company name', $options);
        self::assertNotContains('Supplier: Supplier', $options, 'one hop, not two');
    }

    /**
     * Following a link is reading the other module, so the other module's
     * permissions apply — otherwise a filter is a way to sift records by values
     * somebody may not see (§8.4).
     */
    public function testFilteringThroughALinkRespectsTheOtherModulesPermissions(): void
    {
        $acme = $this->aCompany('Acme AG');
        $this->anArticle('Desk lamp', $acme);

        // May list articles, and knows nothing about contacts.
        $this->grant(self::MEMBER, ArticleModule::KEY, ModuleAction::List);
        $this->grant(self::MEMBER, ArticleModule::KEY, ModuleAction::View);

        $this->signIn(self::MEMBER);

        $unfiltered = $this->client->request('GET', $this->url('/m/article'))->filter('table')->text();
        self::assertStringContainsString('Desk lamp', $unfiltered, 'the articles themselves are theirs to see');

        $filtered = $this->client->request('GET', $this->url(
            '/m/article?filter[0][path]=supplier.company_name&filter[0][op]=eq&filter[0][value]=Acme+AG',
        ))->filter('main')->text();

        self::assertStringNotContainsString('Desk lamp', $filtered, 'but not to sift by');
    }

    // -- helpers ------------------------------------------------------------

    private function aCompany(string $name): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => $name],
            variant: 'company',
        ));
    }

    private function aPerson(string $first, string $last): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => $first, 'last_name' => $last],
            variant: 'person',
        ));
    }

    private function anArticle(string $title, ?int $supplier = null): int
    {
        $fields = [ArticleModule::KIND => ArticleModule::PLAIN, 'title' => $title];

        if ($supplier !== null) {
            $fields[self::SUPPLIER] = (string) $supplier;
        }

        return $this->savedId($this->saveRecord(ArticleModule::KEY, $fields, variant: ArticleModule::PLAIN));
    }

    private function grant(string $email, string $module, ModuleAction $action): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email, $module, $action): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            $manager->persist(PermissionGrant::forUser($user, $module, $action, PermissionScope::All));
            $manager->flush();
        });
    }

    /**
     * Every option of a select, as text.
     *
     * Crawler::text() answers for the first node only, which for a select is
     * always the placeholder — a trap worth a helper rather than a comment.
     *
     * @return list<string>
     */
    private static function optionsOf(\Symfony\Component\DomCrawler\Crawler $crawler, string $selector): array
    {
        return $crawler->filter($selector . ' option')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $node): string => trim($node->text()),
        );
    }

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
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

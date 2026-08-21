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
use App\Tenant\Entity\PermissionGroup;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Xivi\Article\ArticleModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Export\RecordExporter;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\RecordGroup;
use Xivi\Knowledge\KnowledgeModule;

/**
 * An index that is a card per value rather than a page of rows (XIV-168).
 *
 * **The subject is the engine, not the knowledge base.** A module declares
 * {@see \Xivi\Core\Module\GroupedList} naming one of its own fields, and §5.3's
 * one generic index draws a card per value of that field. Knowledge is the only
 * module that declares it today, which makes it the fixture rather than the
 * feature: the article module is installed in the same tenant throughout, and
 * the last test here is the one that says its list is still a list.
 *
 * The tests are grouped by the decision each defends, because most of what this
 * ticket cost was decisions rather than code: what happens to records that
 * answered the grouping question with nothing, what happens to a value nobody
 * has used, what a card does when it has more than fits, and what the page costs
 * when a customer invents six more topics.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class GroupedIndexTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_grouped_index';
    private const string HOST = 'grouped.localhost';

    /** An administrator, who bypasses the permission axis (§8.4). */
    private const string EMAIL = 'writer@grouped.test';

    /** Somebody who may only list their own, for the counting test. */
    private const string READER = 'reader@grouped.test';

    private const string PASSWORD = 'a-long-enough-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $this->inTenant(static function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            $installer->install($registry->get(KnowledgeModule::KEY));
            // A module that declares no grouping, standing in the same tenant
            // for every module in the build. Its index is the control: if
            // anything here leaks out of the declaration, the last test in this
            // file is what turns red.
            $installer->install($registry->get(ArticleModule::KEY));
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::EMAIL, 'Writer', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::READER, 'Reader', self::PASSWORD, []);

        $this->signIn(self::EMAIL);
    }

    // -- the shape of the page ----------------------------------------------

    /**
     * **One card per topic, each holding its entries' titles as links.**.
     *
     * The whole ask in one assertion, and the negative half matters as much as
     * the positive one: there is no table left. A page that had grown cards
     * above a table would have kept every row action this ticket was about
     * removing.
     */
    public function testTheIndexIsACardPerValueHoldingTitlesThatLink(): void
    {
        $this->write('Mahnlauf, Schritt für Schritt', KnowledgeModule::PROCESS);
        $this->write('Reklamation: was wir zuerst prüfen', KnowledgeModule::PROCESS);
        $id = $this->savedId($this->write('Anzahlung ab welchem Betrag', KnowledgeModule::POLICY));

        $page = $this->index();

        self::assertSame(['Process', 'Policy'], $this->headings($page));
        self::assertCount(0, $page->filter('main table'), 'no rows anywhere');

        self::assertSame(
            ['Mahnlauf, Schritt für Schritt', 'Reklamation: was wir zuerst prüfen'],
            $this->titlesUnder($page, 'Process'),
        );

        // And a title really is the way in, rather than text beside a link.
        self::assertCount(
            1,
            $page->filter(sprintf('main .card a[href$="/m/knowledge/%d"]', $id)),
            'the title opens the entry',
        );
    }

    /**
     * **A card carries no edit and no delete.**.
     *
     * Both live on the record page already, so nothing became unreachable and
     * deleting an entry gained a click. That is the ask, and this test is here so
     * that nobody puts them back as a kindness.
     */
    public function testACardCarriesNoEditAndNoDelete(): void
    {
        $this->write('Wie wir eine Gutschrift verbuchen', KnowledgeModule::PROCESS);

        $page = $this->index();

        self::assertCount(0, $page->filter('main a[href$="/edit"]'), 'nothing offers to edit an entry');
        self::assertCount(0, $page->filter('main form[action$="/delete"]'), 'and nothing offers to delete one');
        self::assertCount(0, $page->filter('main .row-actions'));
    }

    /**
     * **Everything above the cards stays exactly as it was.**.
     *
     * The filter bar and the six buttons are the module's own screen furniture
     * and none of them is about how the records below are drawn. Asserted by
     * their routes rather than by their words, so a wording change does not
     * fail this and a missing button does.
     */
    public function testTheFilterBarAndEveryActionButtonAreStillThere(): void
    {
        $this->write('Preise für Montage vor Ort', KnowledgeModule::PRODUCT);

        $page = $this->index();

        self::assertCount(1, $page->filter('main form[method="get"]'), 'the filter bar');
        self::assertCount(1, $page->filter('main select[name="filter[0][path]"]'));

        foreach ([
            '/m/knowledge/export',
            '/m/knowledge/import',
            '/m/knowledge/templates',
            '/m/knowledge/email-templates',
            '/m/knowledge/fields',
            '/m/knowledge/new',
        ] as $path) {
            self::assertGreaterThan(
                0,
                $page->filter(sprintf('main a[href*="%s"]', $path))->count(),
                $path . ' is still offered',
            );
        }
    }

    // -- filtering has to reshape the cards, not sit above them --------------

    /** A search for a word produces cards holding the matches, and no others. */
    public function testSearchingReshapesTheCards(): void
    {
        $this->write('Lieferantenwechsel', KnowledgeModule::SUPPLIER, 'Zuerst Keller AG anfragen.');
        $this->write('Ferien im Juli', KnowledgeModule::OTHER, 'Nichts von Bedeutung.');

        $page = $this->index(['filter' => [[
            'path' => KnowledgeModule::BODY, 'op' => Operator::Contains->value, 'value' => 'Keller',
        ]]]);

        self::assertSame(['Supplier'], $this->headings($page));
        self::assertSame(['Lieferantenwechsel'], $this->titlesUnder($page, 'Supplier'));
    }

    /** And a filter on the grouping field itself leaves that one card. */
    public function testFilteringToOneTopicLeavesThatOneCard(): void
    {
        $this->write('Mahnlauf, Schritt für Schritt', KnowledgeModule::PROCESS);
        $this->write('Anzahlung ab welchem Betrag', KnowledgeModule::POLICY);

        $page = $this->index(['filter' => [[
            'path' => KnowledgeModule::TOPIC, 'op' => Operator::Equals->value, 'value' => KnowledgeModule::POLICY,
        ]]]);

        self::assertSame(['Policy'], $this->headings($page));
    }

    /**
     * **An export of a filtered index holds what the cards showed.**.
     *
     * Export runs the same query the page ran, deliberately, and grouping does
     * not change that. What it *could* have changed is the other direction: a
     * card stops at a ceiling, so the file has to hold the entries the ceiling
     * cut as well as the ones on the screen. Asserted on the entry that was left
     * off the card.
     */
    public function testAFilteredExportStillHoldsWhatTheCardsShowed(): void
    {
        $this->writeMany(KnowledgeModule::PROCESS, ModuleController::LINKED_ON_RECORD + 2);
        $this->write('Ferien im Juli', KnowledgeModule::OTHER);

        $filter = ['filter' => [[
            'path' => KnowledgeModule::TOPIC, 'op' => Operator::Equals->value, 'value' => KnowledgeModule::PROCESS,
        ]]];

        $shown = $this->titlesUnder($this->index($filter), 'Process');
        self::assertCount(ModuleController::LINKED_ON_RECORD, $shown, 'the card stopped short');

        $exported = $this->exported(new RecordQuery([
            new Filter(KnowledgeModule::TOPIC, Operator::Equals, KnowledgeModule::PROCESS),
        ]));

        foreach ($shown as $title) {
            self::assertStringContainsString($title, $exported, 'the file holds what the card showed');
        }

        self::assertStringContainsString('Eintrag 01', $exported, 'and the one the ceiling cut');
        self::assertStringNotContainsString('Ferien im Juli', $exported, 'and nothing the filter excluded');
    }

    // -- the two empty cases, decided rather than discovered ------------------

    /**
     * **Entries with no topic get a card of their own, and it is last.**.
     *
     * The topic field is not required on purpose (§5.22): writing something down
     * at half past five should not be stopped by a dropdown. So untopiced
     * entries are ordinary, and a grouping that drew only the field's options
     * would make them invisible on their own index page, which is worse than the
     * table it replaced.
     *
     * Last rather than first, because the cards above it are in the order the
     * customer arranged their own options in and this one is not one of them.
     */
    public function testEntriesWithNoTopicGetTheirOwnCardAndItComesLast(): void
    {
        $this->write('Mahnlauf, Schritt für Schritt', KnowledgeModule::PROCESS);
        $this->write('Notiz von gestern Abend', null);

        $page = $this->index();

        self::assertSame(['Process', 'No Topic'], $this->headings($page));
        self::assertSame(['Notiz von gestern Abend'], $this->titlesUnder($page, 'No Topic'));
    }

    /**
     * **A topic nobody has written under draws no card.**.
     *
     * Both answers were defensible and this one is chosen: a fresh tenant would
     * otherwise open on six empty boxes saying "there is nothing here" six times,
     * topics are the customer's to add so the count of boxes is theirs to grow by
     * accident, and a card with nothing in it under a search would be a heading
     * claiming a match it has not got.
     *
     * What is given up is the invitation, the empty card that says a topic exists
     * and asks for the first entry under it. The field editor and the record
     * form's dropdown both still show every topic, which is where somebody
     * deciding what to file something under is looking.
     */
    public function testATopicNobodyHasWrittenUnderDrawsNoCard(): void
    {
        $this->write('Mahnlauf, Schritt für Schritt', KnowledgeModule::PROCESS);

        $page = $this->index();

        self::assertSame(['Process'], $this->headings($page), 'the other five shipped topics are not drawn');
    }

    /** And with nothing at all written, §5.3's own empty state is what shows. */
    public function testAnEmptyModuleStillShowsTheWayIn(): void
    {
        $page = $this->index();

        self::assertSame([], $this->headings($page));
        self::assertGreaterThan(
            0,
            $page->filter('main a[href$="/m/knowledge/new"]')->count(),
            'the way in, not a count of zero',
        );
    }

    // -- the cards come from the field, not from the blueprint ----------------

    /**
     * **A topic a customer adds gets a card without anybody writing code.**.
     *
     * The cards are the field's *current* options read at render time, so the six
     * this module ships are not the list (§5.20, XIV-144). Reading the
     * blueprint's constants instead would work perfectly until the first customer
     * added "machine", which is the whole point of §6.1.
     */
    public function testATopicTheCustomerAddedGetsACard(): void
    {
        $this->addTopic('machine', 'Machine');
        $this->write('Wartungsintervall der Presse', 'machine');

        self::assertSame(['Machine'], $this->headings($this->index()));
    }

    /**
     * And the card order is the field's own option order, which is the order the
     * customer arranged.
     *
     * `Policy` is declared after `Process` in the blueprint, so a page that had
     * sorted the headings alphabetically would put it first and pass every other
     * test in this file.
     */
    public function testTheCardsFollowTheFieldsOwnOptionOrder(): void
    {
        $this->write('Anzahlung ab welchem Betrag', KnowledgeModule::POLICY);
        $this->write('Mahnlauf, Schritt für Schritt', KnowledgeModule::PROCESS);
        $this->write('Ferien im Juli', KnowledgeModule::OTHER);

        self::assertSame(['Process', 'Policy', 'Other'], $this->headings($this->index()));
    }

    // -- no card grows without bound ------------------------------------------

    /**
     * **A card stops at the ceiling, says so, and links to the rest.**.
     *
     * Eight hundred entries under six topics is eight hundred links on one page
     * if nothing caps it. The ceiling is the one a card of linked records already
     * had (XIV-52), reused rather than invented, and it is stated on screen on
     * XIV-35's precedent instead of truncating quietly.
     *
     * The link is where the interesting part is. It narrows this same index to
     * this one value *and* asks for it as rows, because a grouped page narrowed
     * to one value is one card with the same ceiling on it and the link would
     * point at itself.
     */
    public function testACardStopsAtTheCeilingSaysSoAndLinksToTheRest(): void
    {
        $total = ModuleController::LINKED_ON_RECORD + 2;
        $this->writeMany(KnowledgeModule::PROCESS, $total);

        $page = $this->index();
        $text = $page->filter('main')->text();

        self::assertCount(ModuleController::LINKED_ON_RECORD, $this->titlesUnder($page, 'Process'));
        self::assertStringContainsString(
            sprintf('Showing %d of %d.', ModuleController::LINKED_ON_RECORD, $total),
            $text,
            'the card admits what it is holding back',
        );
        self::assertStringContainsString(sprintf('Process %d', $total), $text, 'and the badge counts them all');

        $link = $page->filter(sprintf('main .card a[href*="%s=%s"]', RecordGroup::VIEW, RecordGroup::AS_LIST));
        self::assertCount(1, $link, 'exactly one way past the ceiling');

        $rest = $this->client->request('GET', (string) $link->attr('href'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $rest->filter('main table'), 'and it is the list, with its headers and its pager');
        self::assertStringContainsString(
            'Eintrag 01',
            $rest->filter('main table')->text(),
            'which really does reach the entries the card left out',
        );
    }

    /**
     * **The page costs the same number of queries whatever the number of
     * topics.**.
     *
     * §5.3's discipline, and the reason `findGrouped()` exists at all: topics are
     * customer-extensible, so a query per card is unbounded query count on a page
     * a customer can grow by hand. Two sizes compared with `assertSame` rather
     * than a number written down, because a number would be this page's current
     * cost and would be edited by whoever next adds a query to it, at which point
     * it stops being about grouping.
     *
     * The larger shape is deliberately larger in both directions, more cards and
     * more records per card, since a per-record query and a per-card query fail
     * this the same way and only one of them is what the window function was for.
     */
    public function testThePageCostsAFlatNumberOfQueriesWhateverTheNumberOfCards(): void
    {
        $this->write('Mahnlauf, Schritt für Schritt', KnowledgeModule::PROCESS);
        $this->write('Anzahlung ab welchem Betrag', KnowledgeModule::POLICY);

        $small = $this->queriesOnTheIndex();

        foreach ([
            KnowledgeModule::CUSTOMER,
            KnowledgeModule::SUPPLIER,
            KnowledgeModule::PRODUCT,
            KnowledgeModule::OTHER,
        ] as $topic) {
            $this->writeMany($topic, 4);
        }

        // And one more with nothing, so the unfiled card is in the larger shape
        // too: it is built by the same pass and would fail this the same way.
        $this->write('Notiz von gestern Abend', null);

        self::assertSame(
            $small,
            $this->queriesOnTheIndex(),
            'a page of six cards asked the database more than a page of two',
        );
    }

    // -- scoping is unchanged --------------------------------------------------

    /**
     * **No count on a card includes a record the reader may not see** (§8.4).
     *
     * Record-level access is a `WHERE` clause and it stays one: the same
     * predicate compiles into the window function, so the records on the card and
     * the number beside the heading are read under it together. A total counted
     * without it would give away how many entries exist one integer at a time,
     * which is the inference channel §8.4 is careful about, and it would be the
     * easy mistake here because a `COUNT(*) OVER ()` is written once and read by
     * everybody.
     */
    public function testACardCountsOnlyWhatThisReaderMaySee(): void
    {
        $this->write('Mahnlauf, Schritt für Schritt', KnowledgeModule::PROCESS);
        $this->write('Reklamation: was wir zuerst prüfen', KnowledgeModule::PROCESS);

        // Scoped to their own, and they own none of the two above.
        $this->grantOwnOnly();
        $this->signIn(self::READER);
        $this->write('Meine eigene Notiz', KnowledgeModule::PROCESS, as: self::READER);

        $page = $this->index();

        self::assertSame(['Meine eigene Notiz'], $this->titlesUnder($page, 'Process'));
        self::assertStringContainsString(
            'Process 1',
            $page->filter('main')->text(),
            'the badge counts one, not the three that exist',
        );
    }

    // -- every other module is untouched ---------------------------------------

    /**
     * A module that declares no grouping still has the list it always had, in the
     * same tenant, in the same request cycle.
     *
     * This is the assertion the whole design is for. The index template branches
     * on whether it was handed cards and never on which module it is drawing, so
     * "knowledge is different" is a fact about one line in one blueprint.
     */
    public function testAModuleThatDeclaresNoGroupingStillHasItsList(): void
    {
        $this->saveRecord(
            ArticleModule::KEY,
            [ArticleModule::KIND => ArticleModule::PLAIN, 'title' => 'Schreibtischlampe'],
            variant: ArticleModule::PLAIN,
        );

        $page = $this->client->request('GET', $this->url('/m/' . ArticleModule::KEY));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('main table'), 'still a table');
        self::assertGreaterThan(0, $page->filter('main thead th')->count(), 'still with sortable headers');
        self::assertGreaterThan(0, $page->filter('main .row-actions')->count(), 'still with its row actions');
    }

    // -- helpers ----------------------------------------------------------------

    /** @param array<string, mixed> $parameters */
    private function index(array $parameters = []): Crawler
    {
        $url = $this->url('/m/knowledge');

        if ($parameters !== []) {
            $url .= '?' . http_build_query($parameters);
        }

        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * The card headings, in the order they are drawn.
     *
     * The count badge is inside the header, so it is trimmed off here: what these
     * tests are about is which cards there are and in what order, and the numbers
     * are asserted where they are the subject.
     *
     * @return list<string>
     */
    private function headings(Crawler $page): array
    {
        return $page->filter('.card-header > span:first-child')
            ->each(static fn (Crawler $span): string => trim($span->text()));
    }

    /**
     * The entry titles on one card, in the order they are drawn.
     *
     * @return list<string>
     */
    private function titlesUnder(Crawler $page, string $heading): array
    {
        foreach ($page->filter('main .card') as $node) {
            $card = new Crawler($node);
            $headings = $this->headings($card);

            if (($headings[0] ?? null) === $heading) {
                return $card->filter('.list-group-item a')
                    ->each(static fn (Crawler $link): string => trim($link->text()));
            }
        }

        self::fail(sprintf('no card headed "%s" on the page', $heading));
    }

    /**
     * How many database statements the index costs, on both connections.
     *
     * Both deliberately, the way {@see \App\Tests\Functional\Tenant\EveryPageNoticeTest}
     * counts: a per-card query would land on the customer's database, and a count
     * that looked only at the control plane would miss exactly that.
     */
    private function queriesOnTheIndex(): int
    {
        // Once without counting, because the client keeps its kernel between
        // requests here and a few reads are made once per process rather than
        // once per request: the operator notice board is, so the very first
        // measurement in a test would be one higher than every later one and the
        // comparison would fail on a difference that has nothing to do with
        // cards. Warming inside the measurement rather than once in the test
        // keeps every caller honest without anybody remembering to.
        $this->client->request('GET', $this->url('/m/knowledge'));

        $this->client->enableProfiler();
        $this->client->request('GET', $this->url('/m/knowledge'));

        $profile = $this->client->getProfile();
        \assert($profile instanceof Profile);

        $collector = $profile->getCollector('db');
        \assert($collector instanceof DoctrineDataCollector);

        return $collector->getQueryCount();
    }

    /** Every cell of an export of this query, as one string to search. */
    private function exported(RecordQuery $query): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grouped') . '.xlsx';

        $this->inTenant(function () use ($query, $path): void {
            self::service(RecordExporter::class)->toFile(
                self::service(MetadataRepository::class)->get(KnowledgeModule::KEY),
                $query,
                RecordAccess::unrestricted(),
                $path,
            );
        });

        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);

        $cells = '';

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->toArray() as $value) {
                    $cells .= \is_scalar($value) ? $value . "\n" : '';
                }
            }
        }

        $reader->close();
        unlink($path);

        return $cells;
    }

    private function write(string $title, ?string $topic, string $body = 'Was wir tun.', ?string $as = null): Response
    {
        $fields = [KnowledgeModule::TITLE => $title, KnowledgeModule::BODY => $body];

        if ($topic !== null) {
            $fields[KnowledgeModule::TOPIC] = $topic;
        }

        return $this->saveRecord(KnowledgeModule::KEY, $fields, as: $as);
    }

    /**
     * Numbered entries under one topic.
     *
     * Numbered from the end so that "Eintrag 01" is the *last* one in title
     * order, which is what a card sorting by title leaves off. A test that
     * happened to cut the entry it was about to look for would pass for the
     * wrong reason.
     */
    private function writeMany(string $topic, int $count): void
    {
        for ($i = $count; $i >= 1; --$i) {
            $this->write(sprintf('Eintrag %02d', $i), $topic);
        }
    }

    /** A topic the customer added in the field editor (§5.20, XIV-144). */
    private function addTopic(string $value, string $label): void
    {
        $this->inTenant(static function () use ($value, $label): void {
            $module = self::service(MetadataRepository::class)->get(KnowledgeModule::KEY);
            $field = $module->getField(KnowledgeModule::TOPIC);
            \assert($field instanceof FieldDefinition);

            $choices = $field->getOption('choices', []);
            \assert(\is_array($choices));

            self::service(MetadataEditor::class)->updateField(
                $field,
                $field->getLabel(),
                $field->isRequired(),
                $field->isUnique(),
                $field->isFilterable(),
                $field->isListed(),
                $field->isTitle(),
                $field->getPosition(),
                ['choices' => [...$choices, $value => $label]],
            );
        });
    }

    /** The reader may list, view and add, and only their own. */
    private function grantOwnOnly(): void
    {
        $this->inTenant(function (): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $group = new PermissionGroup('readers', 'Readers');
            $manager->persist($group);

            foreach ([ModuleAction::List, ModuleAction::View, ModuleAction::Add, ModuleAction::Edit] as $action) {
                $manager->persist(PermissionGrant::forGroup(
                    $group,
                    KnowledgeModule::KEY,
                    $action,
                    PermissionScope::Own,
                ));
            }

            $user = self::service(UserRepository::class)->findOneByEmail(self::READER);
            \assert($user instanceof User);
            $user->addPermissionGroup($group);

            $manager->flush();
        });
    }

    private function inTenant(callable $work): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, $work);
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
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

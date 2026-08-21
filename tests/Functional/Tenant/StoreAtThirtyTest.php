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

namespace App\Tests\Functional\Tenant;

use App\Registry\Catalog\ModuleCatalog;
use App\Registry\Entity\Module;
use App\Registry\Entity\ModuleState;
use App\Registry\Entity\Tenant;
use App\Registry\Pricing\ModulePrice;
use App\Store\ModuleStore;
use App\Store\Requirement;
use App\Store\StoreOffer;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\Dbal\CountsQueries;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Knowledge\KnowledgeModule;
use Xivi\Voucher\VoucherModule;

/**
 * The store when the catalogue stops being small (XIV-140).
 *
 * {@see ModuleStoreTest} owns *the store working*: that a module can be
 * installed from a screen, that the permission axis is real, that a requirement
 * is refused with guidance. It passes unchanged through this ticket, which was
 * XIV-102's acceptance criterion and is XIV-140's again. This class owns the
 * separate claim that the same screen is still usable when the build carries
 * thirty modules instead of six, and it is a separate class rather than eight
 * more methods over there for exactly that reason.
 *
 * ### What "stops working at thirty" turned out to be
 *
 * Not volume. Thirty cards is a scroll, and a scroll is survivable. What breaks
 * is that the page answers neither of the two questions somebody arrives with:
 *
 *   * **Ordering.** The catalogue orders by module key, and labels are
 *     translated, so on a German screen the grid reads Artikel, Kontakte,
 *     Rechnungen, Wissen, Bestellungen, Gutscheine. There is no rule a reader
 *     can infer, so there is no way to look something up.
 *   * **Mixing.** What the customer has and what they could add were one list
 *     with a badge on part of it, so both questions cost the whole page.
 *   * **Weight, but of queries rather than of bytes.** Composing the page asked
 *     the control plane for the module rows once per tile and the tenant for its
 *     definitions once per tile, including once for every module they have not
 *     got. Invisible at six.
 *
 * Search is here too, and is deliberately the last of the four.
 *
 * ### On the control plane and paratest
 *
 * Which modules the store offers is a control-plane fact, and DAMA deliberately
 * does not roll the control plane back, so the rows written here go by hand in
 * `tearDown()`, and this class keeps to module keys no other class writes state
 * for. {@see ModuleStoreTest} has contact, order and invoice;
 * {@see \App\Tests\Functional\ControlPlane\ModuleStateTest} has article;
 * {@see \App\Tests\Functional\ControlPlane\ModulePriceTest} and
 * {@see ModulePurchaseTest} have the two test-only modules. **Knowledge and
 * voucher are ours**, and nothing here publishes anything else.
 *
 * That constraint is also why the one claim about requirements on a tile is
 * proved by rendering the tile rather than by browsing to it: the only modules
 * in this build with a `requires` list are order and invoice, and those belong
 * to somebody else's class.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class StoreAtThirtyTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_shelf';
    private const string HOST = 'shelf.localhost';
    private const string ADMIN = 'admin@shelf.test';
    private const string PASSWORD = 'shelf-password';

    /** The two keys this class is allowed to decide anything about. */
    private const array OURS = [KnowledgeModule::KEY, VoucherModule::KEY];

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(UserCreator::class)
            ->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
    }

    protected function tearDown(): void
    {
        $this->forgetStates();

        parent::tearDown();
    }

    // -- the two questions, and the two shelves ------------------------------

    /**
     * What they have is its own section, in their own words, and is not offered
     * back to them.
     *
     * The second half is the one that halves the page for an established
     * customer: an installed module used to sit in the grid with a green badge on
     * it, which meant the eighteen modules still worth reading were mixed into
     * thirty.
     */
    public function testWhatTheyHaveIsItsOwnSectionAndIsNotOfferedBackToThem(): void
    {
        $this->publish(...self::OURS);
        $this->installForTenant(KnowledgeModule::KEY);
        $this->signIn();

        $page = $this->client->request('GET', $this->url('/store'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Knowledge', $this->textOf($page, '#store-yours'));
        self::assertStringNotContainsString(
            'Knowledge',
            $this->textOf($page, '#store-available'),
            'a module they have is not something they can add',
        );

        // And the other shelf still does its job, buttons and all: the split is
        // meant to make the offer easier to act on, not to move it out of reach.
        self::assertStringContainsString('Vouchers', $this->textOf($page, '#store-available'));
        self::assertCount(1, $page->filter('#store-available a[href$="/store/voucher/install"]'));
    }

    /**
     * A module the store has stopped offering is still theirs, and still says so.
     *
     * **The reason "what you have" is read from the customer's own definitions
     * rather than from the catalogue** (§6.2: leaving the store never uninstalls
     * anything). An operator moving a module back to development, pricing it
     * `not_for_sale`, or a deploy dropping it, must not make it disappear from a
     * list whose whole promise is to say what this installation consists of. A
     * section that quietly omits a module somebody used that morning is worse
     * than no section.
     */
    public function testAModuleTheStoreNoLongerOffersIsStillTheirs(): void
    {
        $this->publish(...self::OURS);
        $this->installForTenant(KnowledgeModule::KEY);

        // Withdrawn after they installed it, which is the ordinary sequence:
        // nothing withdraws a module somebody has not got yet.
        self::service(ModuleCatalog::class)->moveTo(KnowledgeModule::KEY, ModuleState::Development);

        $this->signIn();
        $page = $this->client->request('GET', $this->url('/store'));

        self::assertStringContainsString('Knowledge', $this->textOf($page, '#store-yours'));

        // No link, because there is no page: a card that offered one would be an
        // invitation to a 404.
        self::assertCount(0, $page->filter('a[href$="/store/knowledge"]'));

        $this->client->request('GET', $this->url('/store/knowledge'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // -- finding one of thirty ------------------------------------------------

    /**
     * The offer is in the reader's alphabet, not in module-key order.
     *
     * **Asserted in German, because English hides the bug.** Every module in this
     * build has an English label that begins with the same letter as its key, so
     * ordering by key and ordering by label agree and a test in English would
     * pass over a sort that had been deleted. In German they disagree flatly:
     * `knowledge` is Wissen and `voucher` is Gutscheine, so key order puts Wissen
     * first and the reader's alphabet puts Gutscheine first.
     *
     * Relative positions rather than an exact list, because the control plane is
     * shared by every paratest worker and another class's module may be published
     * while this runs.
     */
    public function testTheOfferIsInTheReadersAlphabetNotInModuleKeyOrder(): void
    {
        $this->publish(...self::OURS);
        $this->signIn();

        // Through Accept-Language rather than by storing a locale on the user:
        // `UserLocaleListener` falls back to what the browser asks for, and the
        // language is the only thing this test is about.
        $page = $this->client->request('GET', $this->url('/store'), [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        $labels = $page->filter('#store-available .card')->each(
            static fn ($card): string => $card->text(),
        );

        $gutscheine = $this->positionOf($labels, 'Gutscheine');
        $wissen = $this->positionOf($labels, 'Wissen');

        self::assertLessThan(
            $wissen,
            $gutscheine,
            'sorted by the module key, Wissen (knowledge) would come before Gutscheine (voucher)',
        );
    }

    /**
     * Typing a word narrows both shelves, and a word that matches nothing says
     * so.
     *
     * A substring over the labels already on the page, which §3.2 licenses: this
     * catalogue is curated and small, so there is no index and nothing to rank.
     */
    public function testSearchNarrowsTheOfferAndSaysWhenAWordMatchesNothing(): void
    {
        $this->publish(...self::OURS);
        $this->signIn();

        $found = $this->client->request('GET', $this->url('/store?q=vouch'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Vouchers', $this->textOf($found, '#store-available'));
        self::assertStringNotContainsString('Knowledge', $this->textOf($found, 'main'));

        // The way back out is on the page, because a filtered store that cannot
        // be unfiltered is a store somebody has to know to edit the URL of.
        self::assertCount(1, $found->filter('main a[href$="/store"]'));

        $empty = $this->client->request('GET', $this->url('/store?q=zzzznothing'));

        self::assertStringContainsString('zzzznothing', $empty->filter('main')->text());
        self::assertCount(0, $empty->filter('#store-available'), 'no shelf rather than an empty one');
    }

    // -- what a tile says -----------------------------------------------------

    /**
     * A tile names every module this one needs, and which of them are missing.
     *
     * **Rendered rather than browsed**, for the reason in the class docblock:
     * order and invoice are the only modules in this build with requirements and
     * they belong to another test class's control-plane rows. What is under test
     * is the markup, so the markup is what is exercised, with an offer built by
     * hand to put a satisfied requirement beside an unsatisfied one.
     *
     * Both are named. Listing only the gaps would mean a tile that says nothing
     * is a tile you still have to open in order to find out why.
     */
    public function testATileNamesEveryModuleItNeedsAndWhichAreMissing(): void
    {
        $this->signIn();

        $offer = new StoreOffer(
            blueprint: self::service(ModuleRegistry::class)->get(KnowledgeModule::KEY),
            label: 'Knowledge',
            installed: false,
            price: ModulePrice::free(),
            requirements: [
                new Requirement('contact', 'Contacts', installed: true, offered: true),
                new Requirement('order', 'Orders', installed: false, offered: true),
            ],
        );

        $tile = self::service(Environment::class)->render('store/_available.html.twig', [
            'offer' => $offer,
            'currency' => 'CHF',
        ]);

        self::assertStringContainsString('Contacts', $tile, 'the one they have is named too');
        self::assertStringContainsString('Orders', $tile);
        // Colour is not a statement, so the state is in words as well as in the
        // class that draws it.
        self::assertStringContainsString('Not installed', $tile);
        self::assertStringContainsString('text-bg-warning', $tile);
    }

    /**
     * The store and [XIV-70]'s upgrade offer stay different screens.
     *
     * "Install something new" and "take more of what you already own" are
     * different acts, and the second one lives on the module's own settings. This
     * is the assertion that fails the day somebody helpfully puts an upgrade
     * banner on the shelf of things a customer already has.
     */
    public function testTheStoreNeverOffersTheUpgradeScreen(): void
    {
        $this->publish(...self::OURS);
        $this->installForTenant(KnowledgeModule::KEY);
        $this->signIn();

        $page = $this->client->request('GET', $this->url('/store'));

        self::assertCount(0, $page->filter('main a[href*="/upgrade"]'));
    }

    // -- what the operator has not decided about ------------------------------

    /**
     * A module nobody has priced is invisible, and is not in the count either.
     *
     * The count is the half worth writing a test for: a heading saying "8" over
     * seven cards tells a customer that there is a module they are not being
     * shown, which is [XIV-101]'s withholding leaking through the one number on
     * the page that was not filtered. Asserted as agreement between the badge and
     * the cards rather than against a fixed number, because another paratest
     * worker may have published something of its own while this runs.
     */
    public function testAModuleNobodyHasPricedIsInvisibleAndIsNotInTheCount(): void
    {
        $this->publish(KnowledgeModule::KEY);

        // Published and deliberately not priced, which §6.5 says is not free but
        // "nobody has decided yet", and is therefore withheld.
        self::service(ModuleCatalog::class)->moveTo(VoucherModule::KEY, ModuleState::Published);

        $this->signIn();
        $page = $this->client->request('GET', $this->url('/store'));

        self::assertStringContainsString('Knowledge', $this->textOf($page, '#store-available'));
        self::assertStringNotContainsString('Vouchers', $page->filter('main')->text());

        self::assertSame(
            $page->filter('#store-available .card')->count(),
            (int) $page->filter('#store-available-heading .badge')->text(),
            'the count says how many modules are on the shelf, never how many exist',
        );
    }

    // -- the weight, which is queries rather than bytes -----------------------

    /**
     * Offering one more module costs the tenant's database nothing.
     *
     * **The N+1 this ticket removed, stated as a property instead of as a
     * number.** The page used to ask `MetadataRepository::find()` once per
     * offered module, including once for every module the customer has *not*
     * got, since a miss is cached but is still asked once. Publishing a module
     * made every store page in every tenant one statement heavier. It now
     * reads `all()` once and answers from the set, so this number does not move.
     *
     * The control-plane half of the same fix (one `module` read for the page
     * instead of two per tile) is not measured here: the counting middleware is
     * wired onto the tenant connection alone, on purpose, and the registry's
     * reads would only add noise. See {@see CountsQueries}.
     *
     * Both ends are asserted, because two zeroes are equal too and would mean the
     * middleware had stopped counting.
     */
    public function testOfferingOneMoreModuleCostsTheTenantsDatabaseNothing(): void
    {
        $this->publish(KnowledgeModule::KEY);
        $one = $this->queriesToOffer();

        $this->publish(VoucherModule::KEY);
        $two = $this->queriesToOffer();

        self::assertSame($one, $two, sprintf(
            'one offered module cost %d statements and two cost %d, so the page reads per module',
            $one,
            $two,
        ));
        self::assertGreaterThan(0, $two, 'a count of nothing means nothing is being counted');
        self::assertLessThan(6, $two, 'composing the shelf is a handful of reads, not one per module');
    }

    // -- helpers -------------------------------------------------------------

    /** What one full composition of the offer costs the tenant's connection. */
    private function queriesToOffer(): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function (): int {
            // Entering a tenant empties the metadata cache, so this measures a
            // cold read rather than the second one. That is the right thing to
            // measure: a web request is a process (§7.4), so every store page a
            // customer opens is the cold read.
            $counter = self::service(CountsQueries::class);
            $counter->reset();

            $offers = self::service(ModuleStore::class)->available();

            self::assertNotEmpty($offers, 'a query count over an empty shelf would prove nothing');

            return $counter->count();
        });
    }

    /**
     * German collation, not byte order, and this is the case that tells them
     * apart (XIV-140).
     *
     * **The sibling test above cannot see this.** It asserts Gutscheine before
     * Wissen, which is "sorted by label rather than by module key", and `strcmp`
     * satisfies that too because G precedes W in bytes as well as in German. So
     * that test protects the sort existing; it does not protect the sort being
     * `Collator`. Measured: replacing `ModuleStore::compareLabels()` with
     * `strcmp` leaves all eight of the other tests here green.
     *
     * `Ärzte` and `Zimmer` disagree flatly. `Ä` is two bytes beginning `0xC3`,
     * `Z` is `0x5A`, so a byte comparison files Zimmer first while a German
     * reader looks for Ärzte at the top. That is the failure the docblock on
     * `compareLabels()` describes, and until now nothing held it.
     *
     * Asserted on the owned shelf because those labels are the **customer's**
     * (§6.1), so a test can set them. The offer's labels come from the
     * translation catalogue and no test may rename a module for everybody.
     */
    public function testTheOwnedShelfSortsByGermanCollationRatherThanByBytes(): void
    {
        $this->publish(...self::OURS);
        $this->installForTenant(KnowledgeModule::KEY);
        $this->installForTenant(VoucherModule::KEY);
        $this->relabel([KnowledgeModule::KEY => 'Zimmer', VoucherModule::KEY => 'Ärzte']);
        $this->signIn();

        $page = $this->client->request('GET', $this->url('/store'), [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        $labels = $page->filter('#store-yours .card')->each(
            static fn ($card): string => $card->text(),
        );

        self::assertLessThan(
            $this->positionOf($labels, 'Zimmer'),
            $this->positionOf($labels, 'Ärzte'),
            'byte order files Zimmer before Ärzte; a German reader does not',
        );
    }

    /**
     * Renames installed modules the way the metadata editor does.
     *
     * Through {@see MetadataEditor::renameShape()} rather than by setting the
     * label and flushing here, which is what I tried first and what does not
     * work: the definitions are read once per tenant per request (XIV-53), so a
     * write that does not clear that cache is invisible to the page this test
     * then asks for. The editor's own method flushes and clears, which is the
     * whole reason to go through it.
     *
     * @param array<string, string> $labels module key => the customer's word for it
     */
    private function relabel(array $labels): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($labels): void {
            $metadata = self::service(MetadataRepository::class);
            $editor = self::service(MetadataEditor::class);

            foreach ($labels as $key => $label) {
                $definition = $metadata->find($key);
                self::assertNotNull($definition, sprintf('module "%s" is installed', $key));
                $editor->renameShape($definition, $label);
            }
        });
    }

    /**
     * Where in a list of card texts the first one mentioning a label sits.
     *
     * @param list<string> $labels
     */
    private function positionOf(array $labels, string $needle): int
    {
        foreach ($labels as $position => $text) {
            if (str_contains($text, $needle)) {
                return $position;
            }
        }

        self::fail(sprintf('No card on the shelf mentions "%s".', $needle));
    }

    private function textOf(\Symfony\Component\DomCrawler\Crawler $page, string $selector): string
    {
        $found = $page->filter($selector);

        return $found->count() === 0 ? '' : $found->text();
    }

    /** Published *and* priced, because since [XIV-101] the store needs both. */
    private function publish(string ...$modules): void
    {
        $catalog = self::service(ModuleCatalog::class);

        foreach ($modules as $module) {
            $catalog->moveTo($module, ModuleState::Published);
            $catalog->priceAt($module, ModulePrice::free());
        }
    }

    /** The control plane is not rolled back, so the rows go by hand. */
    private function forgetStates(): void
    {
        $manager = self::getContainer()->get('doctrine.orm.control_entity_manager');
        \assert($manager instanceof EntityManagerInterface);

        $manager->createQuery('DELETE FROM ' . Module::class . ' m WHERE m.key IN (:keys)')
            ->setParameter('keys', self::OURS)
            ->execute();

        $manager->clear();
    }

    /** Installs straight into the tenant, bypassing the store: a starting state. */
    private function installForTenant(string $module): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get($module),
            );
        });
    }

    private function signIn(): void
    {
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::ADMIN,
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

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

namespace App\Tests\Functional\ControlPlane;

use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\Exception\NoTenantResolvedException;
use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Entity\TenantUsage;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorCreator;

/**
 * What a tenant actually has installed, and where that disagrees with the
 * registry (XIV-95).
 *
 * **The reading half only**, like {@see TenantUsageTest} and for the same reason:
 * the installed list is written into `tenant_usage` by `tenant:usage:collect`,
 * and what this class is about is what the page does with it afterwards. That
 * collection is asserted in {@see TenantUsageCollectionTest}, which pays for real
 * databases because it has to. So the customers here have DSNs pointing at a host
 * that does not resolve — XIV-58's fixture trick, inherited a second time: a page
 * that connected to a tenant to reconcile these lists would not be subtly wrong,
 * it would be red.
 *
 * ## What is actually being guarded
 *
 * Four things, and only the first is about drawing a list:
 *
 *   1. **Both directions are named.** A module the customer installed from the
 *      console and nobody recorded, and a module the registry lists whose tables
 *      are not there (§4.1). Either one alone would be a half-built feature that
 *      looked finished, because the fixture with drift in one direction passes a
 *      test written for the other.
 *   2. **A difference reads as information.** §6.1 makes disagreement legitimate
 *      — a customer's own definitions are the truth once installed — so the cell
 *      must not paint one as a fault. That is asserted against the markup rather
 *      than the words, because the way this would regress is somebody adding
 *      `text-bg-danger` to make it stand out.
 *   3. **A long list does not eat the row.** The per-module counts are text now,
 *      not a `title`, so a customer with a dozen modules is a dozen lines unless
 *      something folds them — and whatever folds them must never fold the
 *      disagreements, which is the one thing worth reading.
 *   4. **The list is as old as the collection and says so**, in the same three
 *      states XIV-59 established for the figures.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantInstalledModulesTest extends WebTestCase
{
    private const string EMAIL = 'tenant-modules@example.test';
    private const string PASSWORD = 'operator-password-95';

    /** The registry and the customer's database describe the same three modules. */
    private const string AGREED = 'test_xiv95_agreed';

    /**
     * Drift in both directions at once, which is the fixture the ticket is about.
     *
     * `article` is installed and not recorded — somebody ran
     * `tenant:module:install` and nothing wrote it into the registry row.
     * `invoice` is recorded and not installed — a provisioning run that wrote the
     * row and never created the tables (§4.1), or a module taken out by hand.
     */
    private const string DRIFTED = 'test_xiv95_drifted';

    /** Eight modules, one of them drifting, to prove what folding does and does not hide. */
    private const string MANY = 'test_xiv95_many';

    /** Tried, and the database did not answer: the installed list is not known. */
    private const string UNREADABLE = 'test_xiv95_unreadable';

    /** Never collected: also not known, and a different sentence. */
    private const string UNCOLLECTED = 'test_xiv95_uncollected';

    /**
     * The seven modules both sources agree about on {@see MANY}, plus one that
     * only the tenant has.
     *
     * `warehouse` sorts last alphabetically and is the drifting one on purpose:
     * if it appears first in the cell, that is the ordering doing its job rather
     * than the alphabet doing it by accident — the same trick
     * {@see TenantListTest} plays with `test_xiv58_zzz_stuck`.
     */
    private const array MANY_AGREED = ['article', 'contact', 'invoice', 'order', 'project', 'service', 'ticket'];
    private const string MANY_DRIFTING = 'warehouse';

    /** No database is on the other end of this, which is the point. */
    private const string DSN = 'postgresql://xiv95role:@xiv95-secret-host.invalid:5432/xiv95db?serverVersion=16';

    /** When every collection in these fixtures was taken, to the minute. */
    private const string COLLECTED_AT = '2026-08-15 03:00';

    private KernelBrowser $client;
    private string $host;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // The container has to survive the request for the connection assertion
        // below, which asks what state the tenant connection was left in.
        $this->client->disableReboot();

        $this->host = self::service(ControlPlaneHost::class)->normalisedHost();

        $this->removeFixtures();
        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();

        parent::tearDown();
    }

    /**
     * **The page shows what the customer has**, which is the headline acceptance
     * criterion and the thing XIV-58 could not do.
     *
     * Each module named, each with its own record count as readable text. The
     * counts are asserted here as well as in the tooltip test below because they
     * are half of what makes this a module *list* rather than a module *set*: an
     * operator asking which customer is using invoicing wants to see that one of
     * them has four invoices and the other nine thousand.
     */
    public function testTheListShowsTheModulesTheTenantActuallyHasInstalled(): void
    {
        $row = $this->rowFor(self::AGREED);

        self::assertStringContainsString('contact', $row);
        self::assertStringContainsString('article', $row);

        self::assertStringContainsString('9 records', $row, 'the count for one of them');
        self::assertStringContainsString('4 records', $row, 'and for the other');
    }

    /**
     * **Per-module counts are text, not a `title`.**.
     *
     * XIV-59 put the breakdown in a tooltip, which is a control that a touch
     * screen does not have and a screen reader does not announce — so the answer
     * to *of what* was reaching a mouse and nobody else. Asserted as the absence
     * of any `title` attribute in the table body rather than as the presence of
     * the text, because "the numbers are on the page" would go on passing if
     * somebody put them back in a tooltip as well.
     */
    public function testThePerModuleCountsAreNotHiddenInATooltip(): void
    {
        $body = $this->openList()->filter('tbody')->html();

        self::assertStringNotContainsString('title=', $body, 'nothing in the table hides behind a hover');

        // And the numbers really are somewhere, so that the assertion above is
        // not passing because the breakdown was dropped rather than moved.
        self::assertStringContainsString('9 records', $this->rowFor(self::AGREED));
    }

    /**
     * **A disagreement is shown and named in both directions**, which is the
     * criterion this ticket exists for.
     *
     * One module the customer has and the registry does not list, one the registry
     * lists and the customer has not got, on the same row — because a cell that
     * handled one direction would pass a test written with one fixture and fail
     * the first real customer that drifted the other way.
     */
    public function testADisagreementIsNamedInBothDirections(): void
    {
        $row = $this->rowFor(self::DRIFTED);

        self::assertStringContainsString('2 modules differ from the registry', $row);

        // Installed here, not written into the registry row.
        self::assertMatchesRegularExpression('/article\s+7 records\s+not recorded/', $row);

        // Listed by the registry, absent from the customer's database — and drawn
        // with no count beside it, because there is nothing to count and a zero
        // there would be a finding about a customer rather than the absence of
        // one.
        self::assertMatchesRegularExpression('/invoice\s+not installed/', $row);
    }

    /**
     * **A difference reads as information rather than as a fault.**.
     *
     * §6.1 makes the two lists able to disagree by design, and one of the ways
     * they get there — `tenant:module:install` from a console — is an operator
     * doing their job. So the cell says what it found and stops: no warning
     * colour, no alarm icon, and nothing offering to reconcile it. Reconciliation
     * would be a different feature with a much higher bar than a list has to
     * clear.
     *
     * Asserted against the markup because that is how it would regress. The words
     * are neutral today and the first person who wants the cell to stand out will
     * reach for `text-bg-danger`, which is one attribute and no discussion.
     */
    public function testADifferenceIsNotDrawnAsAFault(): void
    {
        $cell = $this->modulesCellFor(self::DRIFTED);

        foreach (['text-bg-danger', 'text-bg-warning', 'alert-danger', 'alert-warning', 'bi-exclamation'] as $alarm) {
            self::assertStringNotContainsString($alarm, $cell, sprintf('drift is not drawn with "%s"', $alarm));
        }

        // Nor does a drifting customer join the count of customers that are not
        // being served. That banner is about a tenant nobody can reach; this one
        // is active, serving, and merely arranged differently from what the
        // registry remembers.
        self::assertStringNotContainsString(self::DRIFTED, $this->openList()->filter('[role="alert"]')->text(''));
    }

    /**
     * **A customer with many modules still has a readable row** — and folding the
     * tail never folds a disagreement.
     *
     * Six is the most any customer in this repository can have today and nothing
     * stops there being more, so the cell shows the first few and puts the rest
     * behind a disclosure: a real control, reachable by keyboard and announced by
     * a screen reader, unlike the tooltip this ticket removed one cell along.
     *
     * The ordering is what makes that safe, and it is the half worth asserting.
     * `warehouse` sorts last alphabetically and is the module the two sources
     * disagree about; it appears outside the disclosure, above modules whose names
     * begin with A.
     */
    public function testAManyModuleRowFoldsItsTailAndNeverItsDifferences(): void
    {
        $cell = $this->modulesCellFor(self::MANY);

        self::assertStringContainsString('<details', $cell, 'eight modules do not all sit in the row');

        $folded = $this->openList()->filter('tbody tr')->reduce(
            static fn (Crawler $row): bool => str_contains($row->text(), self::MANY),
        )->filter('details')->html();

        self::assertStringContainsString('3 more modules', $folded, 'and the count of what is folded is said');
        self::assertStringNotContainsString(
            self::MANY_DRIFTING,
            $folded,
            'the one module the two sources disagree about is never the one hidden',
        );

        // Which is only meaningful if it is on the page at all.
        self::assertStringContainsString(self::MANY_DRIFTING, $cell);
    }

    /**
     * **The installed list is as old as the last collection, and says which of the
     * three states it is in** — exactly as XIV-59's figures do, because it is
     * collected by the same run and dropped by the same failure.
     *
     * The state that matters most is the middle one. A customer whose database did
     * not answer has told us nothing about what they have installed, and the
     * registry's list drawn on its own in that cell would read as *what they
     * have* — a confident claim assembled out of not knowing.
     */
    public function testTheInstalledListSaysHowOldItIsAndWhenItIsNotKnown(): void
    {
        self::assertStringContainsString(
            'installed as of ' . self::COLLECTED_AT,
            $this->rowFor(self::AGREED),
        );

        $unreadable = $this->rowFor(self::UNREADABLE);
        self::assertStringContainsString('Installed modules could not be read', $unreadable);
        self::assertStringContainsString('tried ' . self::COLLECTED_AT, $unreadable);
        self::assertStringNotContainsString('installed as of', $unreadable);

        $uncollected = $this->rowFor(self::UNCOLLECTED);
        self::assertStringContainsString('Installed modules not collected yet', $uncollected);
        self::assertStringNotContainsString('could not be read', $uncollected);
        self::assertStringNotContainsString('differ from the registry', $uncollected);
    }

    /**
     * **Neither state without an installed list invents a disagreement.**.
     *
     * The failure mode is specific and would look like a working feature: compare
     * the registry's modules against an empty list and every one of them is
     * "recorded, not installed", so a customer whose database was briefly
     * unreachable is reported as having lost all their modules. The registry's own
     * list is still drawn in both states — it is a control-plane column and always
     * current — and it is labelled as the registry's rather than left to be read
     * as the customer's.
     */
    public function testAnAbsentCollectionDoesNotReadAsAnEmptyTenant(): void
    {
        foreach ([self::UNREADABLE, self::UNCOLLECTED] as $slug) {
            $row = $this->rowFor($slug);

            self::assertStringContainsString('contact', $row, 'the registry still says what was arranged');
            self::assertStringNotContainsString('not installed', $row, sprintf('nothing is claimed about %s', $slug));
            self::assertStringNotContainsString('not recorded', $row);
        }
    }

    /**
     * **Reconciling the two lists opens no tenant connection**, which is the
     * property that decided where this feature was built.
     *
     * The obvious implementation of this ticket reads each customer's metadata on
     * page load, and it would have been three lines. XIV-58's
     * `testTheListOpensNoTenantConnection` is the primary proof that it does not
     * and is deliberately untouched by this branch; this is the same assertion
     * made while the page is actually drawing a reconciliation, which is the case
     * that ticket could not construct. With these fixtures it would fail loudly:
     * none of these five customers has a database to connect to.
     */
    public function testReconcilingTheModuleListsOpensNoTenantConnection(): void
    {
        $this->openList();

        self::assertNull(
            self::service(TenantContext::class)->tryGetTenant(),
            'a control-plane request resolves no tenant',
        );

        $connection = self::service(ManagerRegistry::class)->getConnection('tenant');
        \assert($connection instanceof Connection);

        self::assertFalse($connection->isConnected(), 'the tenant connection was never opened');

        // Which is what stops the line above from being a statement about DBAL's
        // laziness rather than about this page.
        $this->expectException(NoTenantResolvedException::class);
        $connection->executeQuery('SELECT 1');
    }

    /**
     * The page, signing in first if this test has not already.
     *
     * The guard is not decoration: the browser keeps its session between requests,
     * so a second visit to the sign-in page redirects to the list and there is no
     * form on it to submit.
     */
    private function openList(): Crawler
    {
        $login = $this->client->request('GET', sprintf('https://%s/control/login', $this->host));

        if ($login->selectButton('Sign in')->count() > 0) {
            $this->client->submit($login->selectButton('Sign in')->form([
                'email' => self::EMAIL,
                'password' => self::PASSWORD,
            ]));
        }

        $crawler = $this->client->request('GET', sprintf('https://%s/control/', $this->host));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * One row's visible text, found by the slug the template prints under the
     * name.
     *
     * Read per row rather than off the whole page, because every assertion here is
     * about one customer being distinguishable from another — asserting over the
     * page text would pass just as happily if two rows had swapped their cells.
     */
    private function rowFor(string $slug): string
    {
        return $this->row($slug)->text();
    }

    /** The modules cell's markup, for the assertions that are about how it is drawn. */
    private function modulesCellFor(string $slug): string
    {
        return $this->row($slug)->filter('td.module-cell')->html();
    }

    private function row(string $slug): Crawler
    {
        $rows = $this->openList()->filter('tbody tr')->reduce(
            static fn (Crawler $row): bool => str_contains($row->text(), $slug),
        );

        self::assertGreaterThan(0, $rows->count(), sprintf('No row for "%s" on the tenant list.', $slug));

        return $rows->first();
    }

    private function createFixtures(): void
    {
        $entityManager = self::service(EntityManagerInterface::class);

        // slug => [what the registry says, what the last collection found, the
        // counts it found]. A null second element is a customer whose collection
        // failed; a slug missing from here entirely has never been collected.
        $collections = [
            self::AGREED => [['article', 'contact'], ['article', 'contact'], ['article' => 4, 'contact' => 9]],
            self::DRIFTED => [['contact', 'invoice'], ['article', 'contact'], ['article' => 7, 'contact' => 5]],
            self::MANY => [
                self::MANY_AGREED,
                [...self::MANY_AGREED, self::MANY_DRIFTING],
                array_fill_keys([...self::MANY_AGREED, self::MANY_DRIFTING], 1),
            ],
            self::UNREADABLE => [['contact'], null, []],
            self::UNCOLLECTED => [['contact'], null, []],
        ];

        $usages = [];

        foreach ($collections as $slug => [$enabled, $installed, $records]) {
            $tenant = new Tenant($slug, ucfirst($slug), self::DSN);
            $tenant->addDomain(str_replace('_', '-', $slug) . '.xiv95.test', true);

            // Provisioned, and therefore active: a customer that is not being
            // served shows up in every other class's count of them, and drift is
            // deliberately a thing that happens to a healthy customer.
            $tenant->markProvisioned();
            $tenant->setEncryptedDatabasePassword('XIV95CIPHERTEXT');
            $tenant->setEnabledModules($enabled);

            $entityManager->persist($tenant);

            if ($slug === self::UNCOLLECTED) {
                continue;
            }

            // Written straight into the control plane rather than through the
            // collector, which is what makes this class about the page: what put
            // these rows here is XIV-59's business and is asserted there.
            $usage = new TenantUsage($tenant);

            if ($installed === null) {
                $usage->recordFailure('Doctrine\DBAL\Exception\ConnectionException');
            } else {
                $usage->record(3, new \DateTimeImmutable('2026-08-14 09:30'), $installed, $records);
            }

            $entityManager->persist($usage);
            $usages[] = $usage;
        }

        $entityManager->flush();

        // `collected_at` is set to "now" by the entity, because in production a
        // collection is always happening now. Moved back afterwards so the
        // assertions can name a fixed minute rather than reconstructing the one
        // the fixtures happened to be built at.
        $entityManager->createQuery(
            'UPDATE ' . TenantUsage::class . ' u SET u.collectedAt = :at WHERE u IN (:usages)',
        )
            ->setParameter('at', new \DateTimeImmutable(self::COLLECTED_AT))
            ->setParameter('usages', $usages)
            ->execute();

        $entityManager->clear();

        self::service(OperatorCreator::class)->create(self::EMAIL, 'The Operator', self::PASSWORD);
    }

    /**
     * The control plane is not rolled back between tests — a tenant database is
     * made with `CREATE DATABASE`, which no transaction can undo — so the fixtures
     * go by hand at both ends, as in {@see TenantListTest}. The usage rows go with
     * their tenants on the foreign key's cascade, which is the same property that
     * keeps a collection from standing between an operator and a customer they are
     * removing.
     */
    private function removeFixtures(): void
    {
        $entityManager = self::service(EntityManagerInterface::class);
        $tenants = self::service(TenantRepository::class);

        foreach ([self::AGREED, self::DRIFTED, self::MANY, self::UNREADABLE, self::UNCOLLECTED] as $slug) {
            $tenant = $tenants->findOneBySlug($slug);

            if ($tenant instanceof Tenant) {
                $entityManager->remove($tenant);
            }
        }

        $operator = self::service(OperatorRepository::class)->findOneByEmail(self::EMAIL);

        if ($operator instanceof Operator) {
            $entityManager->remove($operator);
        }

        $entityManager->flush();
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

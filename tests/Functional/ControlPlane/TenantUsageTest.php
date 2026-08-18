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
 * What a tenant uses, as the list draws it (XIV-59).
 *
 * **This class is about the reading half only**, and the fixtures say so: four
 * customers whose figures are written straight into `tenant_usage` and whose
 * databases do not exist at all. Nothing here collects anything — that is
 * {@see TenantUsageCollectionTest}, which needs real databases and pays for them.
 * The separation is the design: collecting opens a customer's database and
 * rendering never does, so a test of the rendering that needed a customer's
 * database would be testing something other than what ships.
 *
 * It inherits XIV-58's fixture trick for the same reason that ticket used it: a
 * page that connected to a tenant here would not be quietly wrong, it would be
 * red, because there is nothing on the other end of these DSNs.
 * `TenantListTest::testTheListOpensNoTenantConnection` remains the primary proof
 * and is deliberately untouched by this branch; the one below is the same
 * assertion made while the page is actually drawing usage figures, which is the
 * case XIV-58 could not construct.
 *
 * ## The three states, which are the whole ticket
 *
 * A figure that is old, a figure that is zero and a figure that could not be read
 * are three different things, and the failure this class guards against is a page
 * that renders any two of them the same way. So there is a customer with figures,
 * a customer whose real answer is nothing at all, a customer whose collection
 * failed, and a customer nobody has ever collected — and the assertions are as
 * much about what each one does *not* say as about what it does.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantUsageTest extends WebTestCase
{
    private const string EMAIL = 'tenant-usage@example.test';
    private const string PASSWORD = 'operator-password-59';

    /** Somebody using the product: people, records, a recent sign-in. */
    private const string BUSY = 'test_xiv59_busy';

    /**
     * Collected successfully, and there is nothing in there.
     *
     * The customer the ticket describes: provisioned in March, never came back.
     * Every number is a real zero and the page has to say so as a finding rather
     * than as a blank.
     */
    private const string QUIET = 'test_xiv59_quiet';

    /** Tried, and the database did not answer. */
    private const string UNREADABLE = 'test_xiv59_unreadable';

    /** Never collected at all: no row, which is neither zero nor a failure. */
    private const string UNCOLLECTED = 'test_xiv59_uncollected';

    /** No database is on the other end of this, which is the point. */
    private const string DSN = 'postgresql://xiv59role:@xiv59-secret-host.invalid:5432/xiv59db?serverVersion=16';

    /**
     * What the failed collection stored, and what must not be on the page.
     *
     * The row keeps the exception's class so that an operator querying the
     * control plane can tell an unreachable database from a missing schema. The
     * page does not draw it: every value that reaches HTML is one somebody can
     * later be tempted to make more helpful, and the helpful version of this is
     * the driver's message, which names the host and the role.
     */
    private const string FAILURE_CLASS = 'Doctrine\DBAL\Exception\ConnectionException';

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
     * The three figures the ticket asked for, on the customer that has all of
     * them.
     */
    public function testEachTenantShowsItsUsersItsLastSignInAndItsRecords(): void
    {
        $row = $this->rowFor(self::BUSY);

        self::assertStringContainsString('12 users', $row);
        // Deliberately under a thousand: `#` in an ICU plural formats for the
        // locale, so a five-figure total would be drawn with a group separator
        // and this assertion would be about number formatting rather than about
        // the total being right.
        self::assertStringContainsString('210 records', $row, 'the total across every installed module');
        self::assertStringContainsString('2026-08-14 09:30', $row, 'the most recent sign-in across its users');
    }

    /**
     * **Figures say how old they are.**.
     *
     * The acceptance criterion, and the reason it is one: these numbers are as
     * old as the last collection run, and a reader who does not know that will
     * act on March's figures in August. The page cannot make them fresher; it can
     * refuse to let somebody mistake them for fresh.
     */
    public function testTheCollectionTimeIsShownBesideTheFigures(): void
    {
        self::assertStringContainsString('collected 2026-08-15 03:00', $this->rowFor(self::BUSY));
    }

    /**
     * **Zero, failed and never collected are three different sentences.**.
     *
     * Asserted in both directions for the failed customer, because the bug this
     * guards against is not a missing label — it is a failed collection rendered
     * with the zeroes that a null count would produce, which reads as *this
     * customer has nothing in it* and is a statement nobody has any grounds for.
     * That is [XIV-39]'s distinction, one screen along: nothing happened and we
     * do not know are different answers.
     */
    public function testAFailedCollectionDoesNotReadAsAnEmptyTenant(): void
    {
        $quiet = $this->rowFor(self::QUIET);
        $unreadable = $this->rowFor(self::UNREADABLE);
        $uncollected = $this->rowFor(self::UNCOLLECTED);

        // The empty customer: a real answer, drawn as a finding.
        self::assertStringContainsString('no users', $quiet);
        self::assertStringContainsString('no records', $quiet);
        self::assertStringContainsString('nobody has signed in', $quiet);
        self::assertStringContainsString('collected', $quiet);

        // The unreadable one says so, and says when it was tried — and says
        // nothing at all about how much is in there, because nobody knows.
        self::assertStringContainsString('Could not be read', $unreadable);
        self::assertStringContainsString('tried 2026-08-15 03:00', $unreadable);
        self::assertStringNotContainsString('no users', $unreadable);
        self::assertStringNotContainsString('no records', $unreadable);
        self::assertStringNotContainsString('nobody has signed in', $unreadable);

        // And one nobody has looked at yet is a third thing again: not zero, not
        // broken, simply not collected.
        self::assertStringContainsString('Not collected yet', $uncollected);
        self::assertStringNotContainsString('Could not be read', $uncollected);
        self::assertStringNotContainsString('no records', $uncollected);
    }

    /**
     * **Drawing usage figures opens no tenant connection**, which is the property
     * the whole design exists to keep.
     *
     * The same three assertions XIV-58 makes, made again in the one situation
     * that ticket could not set up: a page rendering per-tenant figures that
     * *came from* customers' databases. If usage were ever fetched on page load,
     * this is the test that would go red — and with these fixtures it would go red
     * loudly, since none of these four customers has a database to connect to.
     */
    public function testDrawingUsageOpensNoTenantConnection(): void
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
     * The failure's class name stays in the database and off the page.
     *
     * A small assertion about a small thing, and it is here because the
     * temptation it guards against is real: the obvious way to make "could not be
     * read" more useful is to print what went wrong, the obvious source for that
     * is the driver, and a driver's message names the host, the port and the role.
     * The class name is harmless and is still not drawn — the page says what an
     * operator can act on from a list, and the run's own output says the rest.
     */
    public function testTheStoredFailureIsNotRendered(): void
    {
        $response = $this->openListResponse();

        self::assertStringNotContainsString(self::FAILURE_CLASS, $response);
        self::assertStringNotContainsString('xiv59-secret-host.invalid', $response, 'nor the DSN it came from');
    }

    /**
     * The page, signing in first if this test has not already.
     *
     * The guard is not decoration: the browser keeps its session between
     * requests, so a second visit to the sign-in page redirects to the list and
     * there is no form on it to submit. Every test here reads more than one row,
     * and reading a row means asking for the page.
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

    private function openListResponse(): string
    {
        $this->openList();

        $response = $this->client->getResponse();

        return (string) $response . (string) $response->getContent();
    }

    /**
     * One row's visible text, found by the slug the template prints under the
     * name.
     *
     * Read per row rather than off the whole page, because every assertion here
     * is about one customer being distinguishable from another — asserting over
     * the page text would pass just as happily if two rows had swapped their
     * cells.
     */
    private function rowFor(string $slug): string
    {
        $rows = $this->openList()->filter('tbody tr')->each(
            static fn (Crawler $row): string => $row->text(),
        );

        foreach ($rows as $text) {
            if (str_contains($text, $slug)) {
                return $text;
            }
        }

        self::fail(sprintf('No row for "%s" on the tenant list.', $slug));
    }

    private function createFixtures(): void
    {
        $entityManager = self::service(EntityManagerInterface::class);

        $tenants = [];

        foreach ([self::BUSY, self::QUIET, self::UNREADABLE, self::UNCOLLECTED] as $slug) {
            $tenant = new Tenant($slug, ucfirst($slug), self::DSN);
            $tenant->addDomain(str_replace('_', '-', $slug) . '.xiv59.test', true);
            $tenant->markProvisioned();
            $tenant->setEncryptedDatabasePassword('XIV59CIPHERTEXT');

            // The registry's own module list, set so that it agrees with what the
            // collections below say each customer has installed. This class is
            // about the *figures* and nothing else, and since [XIV-95] the modules
            // cell reports where those two lists differ — so leaving the registry
            // side empty here would have every row of these fixtures reporting a
            // disagreement that has nothing to do with what is being tested.
            // Drift has its own fixtures, in {@see TenantInstalledModulesTest}.
            $tenant->setEnabledModules($slug === self::BUSY ? ['contact', 'invoice'] : ['contact']);

            $entityManager->persist($tenant);
            $tenants[$slug] = $tenant;
        }

        // Written directly rather than through the collector, and that is the
        // point of this class: these are control-plane rows, and what put them
        // there is not what this test is about.
        $busy = new TenantUsage($tenants[self::BUSY]);
        $busy->record(
            12,
            new \DateTimeImmutable('2026-08-14 09:30'),
            ['contact', 'invoice'],
            ['contact' => 200, 'invoice' => 10],
        );

        $quiet = new TenantUsage($tenants[self::QUIET]);
        $quiet->record(0, null, ['contact'], ['contact' => 0]);

        $unreadable = new TenantUsage($tenants[self::UNREADABLE]);
        $unreadable->recordFailure(self::FAILURE_CLASS);

        foreach ([$busy, $quiet, $unreadable] as $usage) {
            $entityManager->persist($usage);
        }

        $entityManager->flush();

        // `collected_at` is set by the entity to "now", because in production a
        // collection is always happening now. Moved back afterwards, so the
        // assertions above can name a fixed moment rather than reconstructing the
        // one the fixture happened to be built at.
        $entityManager->createQuery(
            'UPDATE ' . TenantUsage::class . ' u SET u.collectedAt = :at WHERE u.tenant IN (:tenants)',
        )
            ->setParameter('at', new \DateTimeImmutable('2026-08-15 03:00'))
            ->setParameter('tenants', [$tenants[self::BUSY], $tenants[self::QUIET], $tenants[self::UNREADABLE]])
            ->execute();

        $entityManager->clear();

        self::service(OperatorCreator::class)->create(self::EMAIL, 'The Operator', self::PASSWORD);
    }

    /**
     * The control plane is not rolled back between tests (a tenant database is
     * created by `CREATE DATABASE`, which no transaction can undo), so the
     * fixtures go by hand at both ends — as in {@see TenantListTest}. The usage
     * rows go with their tenants: the foreign key cascades, which is the same
     * property that keeps `tenant:deprovision` from tripping over them.
     */
    private function removeFixtures(): void
    {
        $entityManager = self::service(EntityManagerInterface::class);
        $tenants = self::service(TenantRepository::class);

        foreach ([self::BUSY, self::QUIET, self::UNREADABLE, self::UNCOLLECTED] as $slug) {
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

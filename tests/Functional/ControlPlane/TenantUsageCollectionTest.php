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
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Xivi\Contact\ContactModule;
use Xivi\ControlPlane\Entity\TenantUsage;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\ControlPlane\Repository\TenantUsageRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * `tenant:usage:collect` against a real customer, and against one whose database
 * is not there (XIV-59).
 *
 * The other half of the ticket lives in {@see TenantUsageTest}, which is about
 * what the page does with a figure. This class is about where the figure comes
 * from, and there is no way to write it without real databases: the whole claim
 * is that the collector opens a customer's own database, counts what is in it,
 * closes it again and goes on to the next one.
 *
 * ## The two properties that are not about arithmetic
 *
 * **One unreachable tenant does not cost the others their figures.** A run that
 * stopped at the first database that did not answer would leave everybody after
 * it in the registry with figures from whenever it last worked, drawn on the page
 * as current — because a timestamp cannot tell you about a run that never reached
 * that row. So the fixture is deliberately a broken customer *beside* a working
 * one, collected in one run, and the assertion is that both rows are right
 * afterwards.
 *
 * **The customer's connection is shut before the next one opens.** There is one
 * tenant connection object in the process and `TenantSwitcher` closes it on every
 * switch, so this is a property of the design rather than of the loop — but it is
 * the property that stops a nightly collection being the thing that blocks an
 * operator's `tenant:deprovision`, since Postgres will not drop a database
 * somebody is attached to ([XIV-94]). Asserted after a collection rather than
 * during one, in the same three-part shape XIV-58 uses: the connection is closed,
 * and touching it throws rather than reaching anybody.
 *
 * ## Why this provisions its own customer rather than sharing one
 *
 * `SharesATenant` would have been cheaper and is wrong here, for a reason worth
 * writing down because it is the test-suite echo of what this ticket is about.
 * That trait leaves its database standing — DAMA holds a connection to it until
 * the process ends, and Postgres will not drop a database somebody is attached
 * to — and it leaves the registry row with it. A run over the *whole* registry,
 * which is what proves the carry-on behaviour, then means this class both reads
 * every other class's leftovers and leaves its own for them, and the first thing
 * that goes wrong is a `DROP DATABASE` refused because a fan-out somewhere else
 * is still attached. [XIV-94], one floor down.
 *
 * So this carries `#[SkipDatabaseRollback]`, provisions per test and removes both
 * fixtures again at both ends — the shape `TenantDeprovisionCommandTest` uses for
 * the same reason. The cost is a `CREATE DATABASE` per test method. What it buys
 * is a class that leaves nothing behind: nothing for the next run to trip over,
 * and — because DAMA's static connections are off for the duration — no cached
 * connection to any customer this run happened to count.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class TenantUsageCollectionTest extends KernelTestCase
{
    private const string SLUG = 'test_xiv59_collect';
    private const string HOST = 'usage-collection.localhost';

    /** Somebody who signed in, so `MAX(last_login_at)` has something to find. */
    private const string RETURNING = 'returning@usage.test';

    /** And somebody who never has, so the maximum is not merely "the only row". */
    private const string NEVER = 'never@usage.test';

    /**
     * A registry row whose database was never created.
     *
     * Exactly what a provisioning run that died before `CREATE DATABASE` leaves
     * behind (§4.1) — and, for this test, indistinguishable from a customer whose
     * server is down, which is the case the acceptance criterion is about.
     */
    private const string BROKEN = 'test_xiv59_broken';
    private const string BROKEN_DSN = 'postgresql://xiv59broken:@xiv59-broken-host.invalid:5432/xiv59brokendb?serverVersion=16';

    private Tenant $tenant;

    protected function setUp(): void
    {
        // SymfonyStyle wraps its table to the terminal and the assertions below
        // read cells out of that output — the same pin, for the same reason, as
        // TenantDeprovisionCommandTest.
        putenv('COLUMNS=240');

        self::bootKernel();

        // Both fixtures are removed on the way in as well as on the way out. The
        // control plane is not rolled back between tests — a tenant database is
        // made with `CREATE DATABASE`, which no transaction can undo, so the
        // registry cannot be inside one either (see
        // config/packages/test/dama_doctrine_test.yaml) — and a run that died
        // halfway leaves rows that would otherwise make the next one fail for a
        // reason that has nothing to do with the code.
        $this->removeFixtures();

        $this->tenant = self::service(TenantProvisioner::class)->provision(self::SLUG, 'Usage Collection', [self::HOST]);

        $this->fillTheTenant();
        $this->createBrokenTenant();
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();

        putenv('COLUMNS');

        parent::tearDown();
    }

    /**
     * The three figures, read out of a real database and written into the control
     * plane.
     */
    public function testItCollectsUsersTheLastSignInAndRecordsIntoTheControlPlane(): void
    {
        $tester = $this->collect(self::SLUG);
        $tester->assertCommandIsSuccessful();

        $usage = $this->usageFor(self::SLUG);

        self::assertFalse($usage->hasFailed());
        self::assertSame(2, $usage->getUserCount(), 'both users, including the one who never signed in');
        self::assertSame(3, $usage->getRecordCount(), 'three live contacts');
        self::assertSame([ContactModule::KEY => 3], $usage->getRecordsByModule());

        // **And what the customer's own database says it has installed** (XIV-95),
        // which is the list the tenant list reconciles against `enabled_modules`.
        // This fixture is itself the drift the ticket is about: the module was
        // installed by calling `ModuleInstaller` inside the tenant, exactly as
        // `tenant:module:install` does, and nothing wrote it into the registry
        // row. So the two lists disagree here, and the collector reports what it
        // saw rather than what the control plane expected.
        self::assertSame([ContactModule::KEY], $usage->getInstalledModules());
        self::assertSame([], $this->tenantRow()->getEnabledModules(), 'which the registry does not know about');

        // The most recent sign-in across the tenant's users, to the minute the
        // fixture recorded it.
        self::assertNotNull($usage->getLastLoginAt());
        self::assertSame(
            $this->recordedLogin()->format('Y-m-d H:i'),
            $usage->getLastLoginAt()->format('Y-m-d H:i'),
        );

        // And the figures are stamped with when they were taken, which is the
        // whole reason the page can say how old they are.
        self::assertEqualsWithDelta(time(), $usage->getCollectedAt()->getTimestamp(), 120);
    }

    /**
     * **A customer nobody has ever signed in to is reported to the operator, and
     * to nobody's database** (XIV-125).
     *
     * This is what an abandoned tenant looks like from the outside: the database
     * exists, the registry row is fine, and not one person has ever used it.
     * Worth saying out loud on a schedule, because the alternative is that it is
     * visible only to somebody who opens the tenant list and reads a cell.
     *
     * **Reported and never removed**, which is the assertion at the end. §4.1
     * makes deprovision loud, interactive and refused unattended and §4.6 forbids
     * any automatic state from destroying data, so what this run may do is name
     * the customer and name the command; the decision stays with a person. The
     * run also stays successful, since nothing here is a failure.
     *
     * Asserted in both directions in one method, because the fixture's whole
     * value is the *change*: with a sign-in on record the section is absent, and
     * with the sign-ins taken away the same customer appears in it. An assertion
     * that only ever saw the second half would pass against a section that is
     * always drawn.
     */
    public function testACustomerNobodyHasEverSignedInToIsReportedAndNeverRemoved(): void
    {
        self::assertStringNotContainsString(
            'Nobody has ever signed in',
            $this->collect(self::SLUG)->getDisplay(),
            'this customer has a sign-in on record',
        );

        // Straight SQL inside the tenant, because `User` offers no way back from
        // having signed in and should not: the application has no such operation.
        // What is being simulated is a customer nobody ever opened, and the only
        // honest way to build one out of this fixture is to make the column say
        // what it would have said.
        self::service(TenantSwitcher::class)->runFor($this->tenant, static function (): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $manager->getConnection()->executeStatement('UPDATE app_user SET last_login_at = NULL');
        });

        $tester = $this->collect(self::SLUG);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();

        self::assertStringContainsString('Nobody has ever signed in', $display);
        self::assertStringContainsString(self::SLUG, $display, 'named, so the operator knows which customer');
        self::assertStringContainsString(
            'tenant:deprovision',
            $display,
            'the report names the manual command and does not run anything itself',
        );

        // And the customer is still there afterwards, which is the half of this
        // that would be expensive to get wrong.
        self::assertInstanceOf(Tenant::class, self::service(TenantRepository::class)->findOneBySlug(self::SLUG));
    }

    /**
     * A soft-deleted record is not one of them.
     *
     * `countAll()` filters on `deleted_at IS NULL`, which is the same predicate
     * every list in the application uses — so what an operator sees here is what
     * the customer sees, rather than a number that includes rows they threw away.
     */
    public function testDeletedRecordsAreNotCounted(): void
    {
        $this->collect(self::SLUG);
        self::assertSame(3, $this->usageFor(self::SLUG)->getRecordCount());

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            self::service(RecordWriter::class)->delete(
                $contacts,
                self::service(RecordRepository::class)->findAll($contacts)[0],
            );
        });

        $this->collect(self::SLUG);
        self::assertSame(2, $this->usageFor(self::SLUG)->getRecordCount());
    }

    /**
     * **The acceptance criterion: one unreachable database does not cost the
     * others their figures.**.
     *
     * A run over the whole registry, with a customer in it whose database does
     * not exist. Afterwards the broken row says it failed and the real one has its
     * numbers — and the run exits non-zero, because under cron that is the only
     * way anybody finds out at all.
     */
    public function testATenantThatCannotBeReachedDoesNotStopTheOthers(): void
    {
        $tester = $this->collect();

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'a run with a failure in it says so');

        $broken = $this->usageFor(self::BROKEN);
        self::assertTrue($broken->hasFailed(), 'the customer that could not be read is recorded as such');
        self::assertNull($broken->getUserCount(), 'and not as a customer with nobody in it');
        self::assertNull($broken->getRecordCount());
        self::assertSame([], $broken->getInstalledModules(), 'nor as a customer with no modules (XIV-95)');
        self::assertNotNull($broken->getFailure(), 'the class of what went wrong is kept');

        // The one the run had every opportunity to abandon. Its slug sorts before
        // the broken one's, so this is asserted from both directions below rather
        // than resting on the order the registry happens to return.
        $collected = $this->usageFor(self::SLUG);
        self::assertFalse($collected->hasFailed());
        self::assertSame(3, $collected->getRecordCount());

        // The report names the failure in words, which is what lands in the cron
        // mail, and it is the only place the driver's own message appears.
        self::assertStringContainsString(self::BROKEN, $tester->getDisplay());
        self::assertStringContainsString('Could not be collected', $tester->getDisplay());
    }

    /**
     * A run over the registry in *either* order still collects the good customer.
     *
     * The test above proves it for whatever order `findAllOrdered()` produces —
     * alphabetically `test_xiv59_broken` comes first, so the working tenant is
     * collected *after* the failure. This one asserts the other half explicitly:
     * collecting the broken tenant on its own must leave the process able to
     * collect the next one, which is the state a caught exception can quietly
     * ruin by leaving a closed entity manager behind.
     */
    public function testAFailureLeavesTheProcessAbleToCollectTheNextTenant(): void
    {
        $this->collect(self::BROKEN);
        self::assertTrue($this->usageFor(self::BROKEN)->hasFailed());

        $tester = $this->collect(self::SLUG);
        $tester->assertCommandIsSuccessful();

        self::assertSame(3, $this->usageFor(self::SLUG)->getRecordCount(), 'the next customer is collected normally');
    }

    /**
     * **The customer's database is not still open afterwards**, which is what
     * keeps a collection run from blocking a deprovision ([XIV-94]).
     *
     * Three assertions and the third is what makes the second mean something: no
     * tenant is current, the connection is closed, and touching it now throws
     * `NoTenantResolvedException` rather than reaching whoever was collected last.
     * That last one is the difference between "closed" and "closed and cannot be
     * reopened by accident on the tenant that has just been counted".
     */
    public function testTheTenantConnectionIsClosedAfterEachCollection(): void
    {
        $this->collect();

        self::assertNull(
            self::service(TenantContext::class)->tryGetTenant(),
            'the run leaves no tenant current',
        );

        $connection = self::service(ManagerRegistry::class)->getConnection('tenant');
        \assert($connection instanceof Connection);

        self::assertFalse($connection->isConnected(), 'nothing is still attached to the last customer counted');

        $this->expectException(NoTenantResolvedException::class);
        $connection->executeQuery('SELECT 1');
    }

    /** The report an operator reads, in the vocabulary the page uses. */
    public function testTheRunReportsWhatItFound(): void
    {
        $display = $this->collect(self::SLUG)->getDisplay();

        self::assertStringContainsString(self::SLUG, $display);
        self::assertStringContainsString('1 tenant(s) collected', $display);
    }

    private function collect(?string $slug = null): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        $command = (new Application($kernel))->find('tenant:usage:collect');
        $tester = new CommandTester($command);
        $tester->execute($slug === null ? [] : ['--slug' => $slug]);

        return $tester;
    }

    /** The registry row for the customer this class provisions, read back fresh. */
    private function tenantRow(): Tenant
    {
        $tenant = self::service(TenantRepository::class)->findOneBySlug(self::SLUG);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function usageFor(string $slug): TenantUsage
    {
        $tenant = self::service(TenantRepository::class)->findOneBySlug($slug);
        \assert($tenant instanceof Tenant);

        $usage = self::service(TenantUsageRepository::class)->findOneForTenant($tenant);
        self::assertInstanceOf(TenantUsage::class, $usage, sprintf('no collection was stored for "%s"', $slug));

        // The command wrote it through the same entity manager this reads from,
        // so without this the identity map could answer from before the run.
        self::service(EntityManagerInterface::class)->refresh($usage);

        return $usage;
    }

    /**
     * Two users — one who has signed in and one who has not — and three contacts.
     *
     * Thrown away with the database in `tearDown` and built again in the next
     * `setUp`, which is what makes the numbers above constants rather than
     * whatever the previous test left.
     */
    private function fillTheTenant(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $returning = new User(self::RETURNING, 'Returning');
            $returning->recordLogin();

            $manager->persist($returning);
            $manager->persist(new User(self::NEVER, 'Never'));
            $manager->flush();

            $contacts = self::service(ModuleInstaller::class)
                ->install(self::service(ModuleRegistry::class)->get(ContactModule::KEY));

            $writer = self::service(RecordWriter::class);

            foreach (['Alice', 'Bruno', 'Chiara'] as $name) {
                $writer->save($contacts, new Record(data: ['first_name' => $name, 'last_name' => 'Example']));
            }
        });
    }

    /** When the returning user's sign-in was recorded, read back from the tenant. */
    private function recordedLogin(): \DateTimeImmutable
    {
        $at = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            static fn (): ?\DateTimeImmutable => self::service(UserRepository::class)
                ->findOneByEmail(self::RETURNING)?->getLastLoginAt(),
        );

        self::assertInstanceOf(\DateTimeImmutable::class, $at);

        return $at;
    }

    /**
     * A registry row with a DSN that resolves to nothing.
     *
     * Written straight into the control plane rather than provisioned, because
     * the state under test is precisely a customer the control plane knows about
     * and cannot reach.
     *
     * `markProvisioned()` matters more than it looks: it leaves the row *active*,
     * so this fixture does not turn up in another class's count of customers that
     * are not being served while it exists.
     */
    private function createBrokenTenant(): void
    {
        $manager = self::service(EntityManagerInterface::class);

        $broken = new Tenant(self::BROKEN, 'Broken Customer', self::BROKEN_DSN);
        $broken->addDomain('broken.xiv59.test', true);
        $broken->markProvisioned();
        $broken->setEncryptedDatabasePassword('XIV59BROKENCIPHERTEXT');

        $manager->persist($broken);
        $manager->flush();
    }

    /**
     * Both customers go, and their collections go with them.
     *
     * `deprovision()` for the real one — the row, the database and the role, the
     * same call the operator's command makes — and a plain `remove()` for the
     * broken one, which never had a database to drop.
     *
     * **The usage rows are not deleted here, and that is an assertion in
     * disguise.** Nothing in this method mentions `tenant_usage`; if the foreign
     * key's `ON DELETE CASCADE` were missing, every test in this class would fail
     * in `tearDown` with a constraint violation. That cascade is what stops a
     * collection row from standing between an operator and a customer they are
     * removing, and this is the cheapest place it gets exercised.
     */
    private function removeFixtures(): void
    {
        $tenants = self::service(TenantRepository::class);
        $manager = self::service(EntityManagerInterface::class);

        $collected = $tenants->findOneBySlug(self::SLUG);

        if ($collected instanceof Tenant) {
            self::service(TenantProvisioner::class)->deprovision($collected);
        }

        // The cascade removes the collection row in the *database*; the identity
        // map knows nothing about it and would still be holding one, pointing at
        // a tenant that has just gone. The next flush walks that association and
        // reports a tenant it cannot save, which is a confusing way to be told
        // the fixtures are gone. Clearing says it instead.
        $manager->clear();

        $broken = $tenants->findOneBySlug(self::BROKEN);

        if ($broken instanceof Tenant) {
            $manager->remove($broken);
            $manager->flush();
        }

        $manager->clear();
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

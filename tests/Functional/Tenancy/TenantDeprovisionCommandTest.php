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

namespace App\Tests\Functional\Tenancy;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\Dbal\TenantDsnParser;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * `tenant:deprovision` against a real tenant: the row, the database and the role
 * all go, and the ways of not meaning it all stop it (XIV-72).
 *
 * Outside DAMA's transaction, because a database cannot be created or dropped
 * inside one and the assertions here are about what the *cluster* holds — asked
 * of `pg_database` and `pg_roles` through an admin connection rather than of the
 * application, which would only be repeating what it had just been told.
 *
 * Every assertion names this class's own tenant. The registry is shared with
 * every other class in the run (see SharesATenant), so anything phrased about
 * the registry as a whole would be an assertion about somebody else's fixtures.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class TenantDeprovisionCommandTest extends KernelTestCase
{
    private const string SLUG = 'test_deprovision';

    private TenantProvisioner $provisioner;
    private ?Connection $admin = null;

    protected function setUp(): void
    {
        // The command reports through SymfonyStyle, which wraps its blocks and
        // its definition list to the terminal. Under PHPUnit that width is
        // whatever the terminal running the suite happens to be, so a database
        // name asserted on below would be split across two lines on a narrow one
        // and not on a wide one. Pinned, so the assertions are about the output
        // and not about the window.
        putenv('COLUMNS=200');

        self::bootKernel();

        $provisioner = self::getContainer()->get(TenantProvisioner::class);
        \assert($provisioner instanceof TenantProvisioner);
        $this->provisioner = $provisioner;

        $this->removeTenant();
        $this->provisioner->provision(self::SLUG, 'Deprovision', ['deprovision.localhost']);
    }

    protected function tearDown(): void
    {
        $this->removeTenant();

        $this->admin?->close();
        $this->admin = null;

        putenv('COLUMNS');

        parent::tearDown();
    }

    /** The row, the database and the role: all three, and nothing left to clean up by hand. */
    public function testForceRemovesTheRowTheDatabaseAndTheRole(): void
    {
        $database = $this->databaseName();
        $role = $this->roleName();

        self::assertTrue($this->clusterHasDatabase($database));
        self::assertTrue($this->clusterHasRole($role));

        $tester = $this->command();
        $tester->execute(['slug' => self::SLUG, '--force' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertNull($this->findTenant());
        self::assertFalse($this->clusterHasDatabase($database), 'the database is dropped, not orphaned');
        self::assertFalse($this->clusterHasRole($role), 'and so is the role, which a DROP DATABASE leaves behind');
    }

    /**
     * The guard the ticket was written for: a scheduled job that reaches this
     * command with no terminal must not remove a customer because nobody was
     * there to answer the question.
     *
     * `setInputs([])` with `interactive: false` is exactly the state such a job
     * is in — Symfony would otherwise answer its own question with the default,
     * and a default is not consent.
     */
    public function testItRefusesUnattendedWithoutForceAndLeavesEverythingStanding(): void
    {
        $database = $this->databaseName();

        $tester = $this->command();
        $tester->execute(['slug' => self::SLUG], ['interactive' => false]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertInstanceOf(Tenant::class, $this->findTenant());
        self::assertTrue($this->clusterHasDatabase($database));
    }

    /** Pressing return is "no". The default cannot be the one that destroys. */
    public function testTheInteractiveDefaultIsNo(): void
    {
        $tester = $this->command();
        $tester->setInputs(['']);
        $tester->execute(['slug' => self::SLUG]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Nothing was removed', $tester->getDisplay());
        self::assertInstanceOf(Tenant::class, $this->findTenant());
    }

    public function testSayingYesRemovesIt(): void
    {
        $tester = $this->command();
        $tester->setInputs(['yes']);
        $tester->execute(['slug' => self::SLUG]);

        $tester->assertCommandIsSuccessful();
        self::assertNull($this->findTenant());
    }

    /**
     * What it names before it asks. Without these the confirmation is a yes/no
     * about a slug, and the slug is the one thing somebody who typed the wrong
     * one already believes.
     */
    public function testItNamesWhatItIsAboutToDestroy(): void
    {
        $tester = $this->command();
        $tester->setInputs(['']);
        $tester->execute(['slug' => self::SLUG]);

        $display = $tester->getDisplay();

        self::assertStringContainsString($this->databaseName(), $display);
        self::assertStringContainsString($this->roleName(), $display);
        self::assertStringContainsString('deprovision.localhost', $display);
        self::assertStringContainsString('no modules installed', $display, 'the record count, on an empty tenant');
    }

    public function testASlugThatDoesNotExistIsSaidPlainly(): void
    {
        $tester = $this->command();
        $tester->execute(['slug' => 'test_no_such_tenant', '--force' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No tenant with slug "test_no_such_tenant"', $tester->getDisplay());
    }

    /**
     * A tenant whose database somebody already dropped by hand — the wreckage
     * this command exists to be able to clear. The row goes anyway.
     */
    public function testARowWhoseDatabaseIsAlreadyGoneCanStillBeRemoved(): void
    {
        $database = $this->databaseName();
        $this->admin()->executeStatement(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $database));

        $tester = $this->command();
        $tester->execute(['slug' => self::SLUG, '--force' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('could not be read', $tester->getDisplay());
        self::assertNull($this->findTenant());
    }

    private function command(): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        return new CommandTester((new Application($kernel))->find('tenant:deprovision'));
    }

    private function databaseName(): string
    {
        return $this->dsnParser()->databaseName($this->tenant()->getDatabaseDsn());
    }

    private function roleName(): string
    {
        $role = $this->dsnParser()->userName($this->tenant()->getDatabaseDsn());
        self::assertIsString($role);

        return $role;
    }

    private function clusterHasDatabase(string $name): bool
    {
        return $this->admin()->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$name]) !== false;
    }

    private function clusterHasRole(string $name): bool
    {
        return $this->admin()->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$name]) !== false;
    }

    /**
     * The provisioning credentials, which are the only ones that can see the
     * whole cluster — the tenant's own role reaches exactly one database, which
     * is §4's isolation and the reason this cannot be asked any other way.
     */
    private function admin(): Connection
    {
        if ($this->admin instanceof Connection) {
            return $this->admin;
        }

        $params = $this->dsnParser()->parse((string) ($_SERVER['TENANT_ADMIN_DSN'] ?? ''));
        unset($params['url']);

        return $this->admin = DriverManager::getConnection($params);
    }

    private function dsnParser(): TenantDsnParser
    {
        $parser = self::getContainer()->get(TenantDsnParser::class);
        \assert($parser instanceof TenantDsnParser);

        return $parser;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->findTenant();
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }

    private function findTenant(): ?Tenant
    {
        $controlPlane = self::getContainer()->get('doctrine.orm.control_entity_manager');
        \assert($controlPlane instanceof EntityManagerInterface);
        $controlPlane->clear();

        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);

        return $tenants->findOneBySlug(self::SLUG);
    }

    private function removeTenant(): void
    {
        $tenant = $this->findTenant();

        if ($tenant instanceof Tenant) {
            $this->provisioner->deprovision($tenant);
        }
    }
}

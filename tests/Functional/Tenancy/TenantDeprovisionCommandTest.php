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

use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenancy\Security\TenantSecretCipher;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;

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
 * **The last three tests are XIV-94's**, and they are about the removal working
 * on a tenant that is still in use rather than about the ceremony in front of it.
 * One holds a real session open on the tenant's database while the command runs,
 * one asks the cluster whether the shipped provisioning credentials are actually
 * permitted to end such a session, and one arranges a removal that stops half
 * way to check that what is left is findable and repairable. They cost a
 * connection each, which is the price of the failure they cover having been
 * reported from production-shaped code and reproduced in the suite.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class TenantDeprovisionCommandTest extends KernelTestCase
{
    private const string SLUG = 'test_deprovision';

    private TenantProvisioner $provisioner;
    private ?Connection $admin = null;

    /**
     * A connection held open *to the tenant's own database*, for the tests about
     * dropping one out from under somebody. Closed in tearDown for the runs where
     * the drop never reached it; where it did, closing a terminated connection is
     * a no-op rather than an error.
     */
    private ?Connection $session = null;

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
        $this->session?->close();
        $this->session = null;

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

    /**
     * The bug (XIV-94): a tenant with a session open to it is still removable.
     *
     * This is the case §4.1 makes unavoidable rather than exotic. `suspended` is
     * deliberately not a prerequisite for removal, so the command has to work on
     * a customer who is being served right now — and a customer who is being
     * served is, by definition, one with a connection open to their database.
     * Before this, Postgres refused the drop with `SQLSTATE[55006] … is being
     * accessed by other users`, and it refused it *after* the control-plane row
     * had already been deleted.
     *
     * The session opened here is the real thing rather than a stand-in: the
     * tenant's own Postgres role, with the password out of the registry, on the
     * tenant's own database — which is exactly what a request being served looks
     * like from the cluster's side, and which nothing in this process can close,
     * so clearing the tenant switcher cannot be what makes this pass.
     */
    public function testItRemovesATenantSomebodyIsStillConnectedTo(): void
    {
        $database = $this->databaseName();
        $this->openATenantSession();

        self::assertGreaterThan(0, $this->sessionsOn($database), 'the session is really attached');

        $tester = $this->command();
        $tester->execute(['slug' => self::SLUG, '--force' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertNull($this->findTenant());
        self::assertFalse($this->clusterHasDatabase($database), 'dropped out from under the open session');
    }

    /**
     * Whether the shipped provisioning credentials may end somebody else's
     * session — asked, not assumed.
     *
     * The removal above only works because Postgres lets `TENANT_ADMIN_DSN`'s role
     * call `pg_terminate_backend()` on a backend belonging to a tenant role, and
     * that is **not** implied by the `CREATE DATABASE` and `CREATE ROLE` rights
     * the DSN is documented as needing. Postgres grants it to a superuser, to a
     * member of `pg_signal_backend`, or to a role that inherits the privileges of
     * the connected role — and a plain `CREATEDB CREATEROLE` role holds none of
     * those over the tenant roles it created itself, because a `CREATEROLE` grant
     * carries `ADMIN` without `INHERIT` from Postgres 16 onward. Measured on this
     * cluster while XIV-94 was being written: such a role fails with
     * `42501 permission denied to terminate process`.
     *
     * Development and test run as the cluster superuser, which is why the test
     * above passes at all. This test is the one that says so out loud, so that a
     * deployment which narrows the provisioning role finds out here rather than
     * at three in the morning — and `TenantProvisioner` catches the same error
     * and answers it with the grant, for the deployments this suite never runs
     * against.
     */
    public function testTheProvisioningCredentialsMayEndATenantSession(): void
    {
        $database = $this->databaseName();
        $this->openATenantSession();

        $terminated = $this->admin()->fetchFirstColumn(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
            [$database],
        );

        self::assertNotSame([], $terminated, 'there was a session to end');
        self::assertNotContains(false, $terminated, 'and the provisioning role was allowed to end it');
        self::assertSame(0, $this->sessionsOn($database));
    }

    /**
     * A removal that stops part-way leaves the registry row, and says so.
     *
     * Arranged rather than waited for: the tenant's role is given something to
     * own in another database on the cluster, which makes `DROP ROLE` fail while
     * `DROP DATABASE` has already succeeded. That is an artificial cause and a
     * real state — a role outliving its database because something elsewhere
     * still depends on it is exactly what happens when a tenant role was ever
     * granted anything by hand.
     *
     * What is being asserted is the ordering decision, not the wording: the
     * control-plane row is **still there** after a failure, which is what makes
     * the leftovers findable by `tenant:list` and repairable by running the same
     * command again. Under the order this replaced, the row would already have
     * been deleted and the database left standing with nothing naming it.
     */
    public function testAFailurePartWayLeavesTheRowAndSaysWhatIsStanding(): void
    {
        $database = $this->databaseName();
        $role = $this->roleName();

        // Something for the role to own, in the admin connection's own database,
        // so that dropping the role is refused.
        $this->admin()->executeStatement(sprintf('CREATE TABLE xiv94_holds_%s ()', self::SLUG));
        $this->admin()->executeStatement(sprintf('ALTER TABLE xiv94_holds_%s OWNER TO "%s"', self::SLUG, $role));

        try {
            $tester = $this->command();
            $tester->execute(['slug' => self::SLUG, '--force' => true]);

            $display = $tester->getDisplay();

            self::assertSame(Command::FAILURE, $tester->getStatusCode());

            // **Whitespace-collapsed before matching**, because the sentence is
            // rendered inside Symfony's `[ERROR]` block, which wraps to the
            // terminal width and pads every line out to it. Where the wrap falls
            // depends on how long the tenant's name is, so asserting the raw
            // display passes for a short slug and fails for a longer one — which
            // is a test that reports on the fixture's name rather than on the
            // behaviour. The claim is that a person is shown a sentence; how the
            // console chose to fold it is not part of that claim.
            $sentence = (string) preg_replace('/\\s+/', ' ', $display);

            self::assertStringContainsString('could not be dropped', $sentence, 'a sentence, not a stack trace');
            self::assertStringContainsString('Where "' . self::SLUG . '" stands now', $sentence);
            self::assertStringContainsString('tenant:deprovision ' . self::SLUG . ' --force', $display);

            // The state itself, asked of the cluster and the registry rather than
            // read back out of the message that claims it.
            self::assertFalse($this->clusterHasDatabase($database));
            self::assertTrue($this->clusterHasRole($role));
            self::assertInstanceOf(Tenant::class, $this->findTenant(), 'the row outlives the failure');
        } finally {
            $this->admin()->executeStatement(sprintf('DROP TABLE IF EXISTS xiv94_holds_%s', self::SLUG));
        }

        // And the promise the report makes: the same line again finishes the job.
        $again = $this->command();
        $again->execute(['slug' => self::SLUG, '--force' => true]);

        $again->assertCommandIsSuccessful();
        self::assertFalse($this->clusterHasRole($role));
        self::assertNull($this->findTenant());
    }

    /**
     * A live connection to this class's tenant, held open until the test ends.
     *
     * Built the way `TenantConnectionParameters` builds one — the DSN out of the
     * registry, the password out of the encrypted column — because a session
     * opened as anybody else would not prove the interesting half: the tenant
     * role is the one whose backend the provisioning role has to be *permitted*
     * to terminate.
     *
     * `SELECT 1` is not decoration. DBAL connects lazily, so without a statement
     * there is no backend for `pg_stat_activity` to show and the test would pass
     * while proving nothing.
     */
    private function openATenantSession(): void
    {
        $cipher = self::getContainer()->get(TenantSecretCipher::class);
        \assert($cipher instanceof TenantSecretCipher);

        $ciphertext = $this->tenant()->getEncryptedDatabasePassword();
        self::assertIsString($ciphertext);

        $params = $this->dsnParser()->parse($this->tenant()->getDatabaseDsn());
        unset($params['url']);
        $params['password'] = $cipher->decrypt($ciphertext);

        $this->session = DriverManager::getConnection($params);
        $this->session->fetchOne('SELECT 1');
    }

    /** Sessions on $database other than the admin connection asking the question. */
    private function sessionsOn(string $database): int
    {
        return (int) $this->admin()->fetchOne(
            'SELECT count(*) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
            [$database],
        );
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

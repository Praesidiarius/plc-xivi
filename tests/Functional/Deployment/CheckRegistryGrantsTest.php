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

namespace App\Tests\Functional\Deployment;

use App\Deployment\RegistryGrants;
use App\Deployment\RegistryPrivileges;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * **That `deploy:check-grants` catches a deployment which forgot to re-run
 * `deploy:registry-grants`** (XIV-143, docs/architecture.md §4.4).
 *
 * ## What has to be true for this class to be worth anything
 *
 * A check that has only ever been shown passing is a check nobody should trust,
 * so every test here breaks a real privilege on a real role and asserts the
 * command goes red about it: a `SELECT` revoked on one registry table, an
 * `INSERT` granted on another, a `SELECT` granted on the administration
 * surface's own `operator`, and a role that does not exist at all.
 *
 * ## And the role is genuinely restricted, which is asserted rather than assumed
 *
 * `NoticeGrantsTest` and `SupportGrantsTest` set the shape: make the role with
 * {@see RegistryGrants}'s own statements and open a connection **as that role**.
 * This class is a little different — the command under test connects as the
 * *administrator* and asks PostgreSQL what somebody else may do — so being the
 * restricted role is not automatic here, and a version of this test that only
 * read the command's output would prove that `has_table_privilege` agrees with
 * `has_table_privilege`.
 *
 * So every finding is corroborated from inside the role: where the command says
 * a privilege is missing, this connects as that role and shows the statement
 * being refused with SQLSTATE 42501; where the command says a privilege is
 * excessive, it shows the statement **succeeding**. Swap the credentials in
 * {@see connectAsTheRestrictedRole()} for the administrator's and those
 * assertions fail, which is the property [XIV-120] and [XIV-123] each asked of
 * their own grants test.
 *
 * **Every corroborating write is `INSERT … SELECT … WHERE false`**, which is a
 * statement PostgreSQL checks the privilege for and then inserts nothing. That
 * is deliberate and is [XIV-120]'s scar: its first refusal test inserted a row
 * that violated a unique constraint, so it threw for a privileged role too and
 * would have passed with `INSERT` granted. Here no row is ever produced, so no
 * constraint can fire, and the privilege is the only thing that can decide the
 * outcome — in *either* direction, which matters because two of these
 * assertions are that the statement succeeds.
 *
 * ## Cleanup, and why the role name is this long
 *
 * A role is a cluster-wide object, so two test classes sharing one name would
 * meet — and `bin/ci` runs eight workers against one cluster. The name is
 * therefore `app.tenant_object_prefix` (which carries the checkout and the
 * worker) plus a suffix nothing else uses. The control-plane database is not
 * rolled back between tests, so the role is dropped on the way in as well as on
 * the way out: a run that died halfway leaves it standing, and the next run is
 * the one that has to cope.
 *
 * Nothing else is written. No tenant row, no notice, no support request — this
 * class is about privileges, and the tables it names are read only.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CheckRegistryGrantsTest extends KernelTestCase
{
    private const string PASSWORD = 'not-a-secret-this-role-lives-for-one-test';

    /** A registry table the dashboard reads on every request — [XIV-120]'s, and the reason this ticket exists. */
    private const string DASHBOARD_TABLE = 'notice';

    /** The administration surface's own, which §4.4 withholds on every privilege. */
    private const string WITHHELD_TABLE = 'operator';

    private Connection $administrator;

    /** The role under test, namespaced per worker and per checkout — a role is a cluster-wide object. */
    private string $role;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $administrator = $container->get('doctrine.dbal.control_connection');
        \assert($administrator instanceof Connection);
        $this->administrator = $administrator;

        $prefix = $container->getParameter('app.tenant_object_prefix');
        \assert(\is_string($prefix));

        // Not a prefix of NoticeGrantsTest's or SupportGrantsTest's role, and
        // theirs are not prefixes of this one. Cluster-wide names and a `LIKE`
        // in somebody's cleanup are how XIV-120 deleted another class's fixtures.
        $this->role = $prefix . 'grants_audit_reader';

        $this->dropRole();

        $this->administrator->executeStatement(sprintf(
            'CREATE ROLE %s LOGIN PASSWORD %s',
            $this->quote($this->role),
            $this->administrator->quote(self::PASSWORD),
        ));

        $this->grantWhatThisBuildAsksFor();
    }

    protected function tearDown(): void
    {
        $this->dropRole();

        parent::tearDown();
    }

    /**
     * The baseline, and it is not decoration: without it every red test below
     * would also pass for a check that reports everything as broken.
     *
     * The corroboration is both halves of §4.4's sentence, from inside the role:
     * the dashboard's table is readable, and the registry cannot be written.
     */
    public function testARoleHoldingExactlyWhatThisBuildGrantsIsReportedAsMatching(): void
    {
        self::assertSame(
            0,
            (int) $this->connectAsTheRestrictedRole()->fetchOne(
                sprintf('SELECT count(*) FROM %s WHERE false', self::DASHBOARD_TABLE),
            ),
            'the role cannot read the registry, so this class is not testing the role it thinks it is',
        );

        $this->assertRefused(sprintf('INSERT INTO %s (title) SELECT %s WHERE false', self::DASHBOARD_TABLE, "'x'"));

        $tester = $this->check();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('may read all', $tester->getDisplay());
    }

    /**
     * **The ticket's central claim.** A release that added a registry table and a
     * deployment that did not re-run `deploy:registry-grants` is exactly this:
     * one revoked `SELECT`, and a dashboard that answers 500 for every user of
     * every tenant.
     *
     * The refusal is proved before the command is run, so the finding cannot be
     * an artefact of the catalogue query — and the `SELECT` on `tenant` that
     * follows it proves the connection itself is fine, which is what separates
     * "this privilege is missing" from "this role cannot reach the database".
     */
    public function testAMissingSelectIsReportedAndTheCommandFails(): void
    {
        $this->administrator->executeStatement(sprintf(
            'REVOKE SELECT ON TABLE %s FROM %s',
            self::DASHBOARD_TABLE,
            $this->quote($this->role),
        ));

        $this->assertRefused(sprintf('SELECT count(*) FROM %s', self::DASHBOARD_TABLE));

        self::assertSame(
            0,
            (int) $this->connectAsTheRestrictedRole()->fetchOne('SELECT count(*) FROM tenant WHERE false'),
            'the rest of the registry is still readable, so the refusal above is about one privilege',
        );

        $tester = $this->check();

        self::assertSame(3, $tester->getStatusCode());

        $display = $tester->getDisplay();

        self::assertStringContainsString(self::DASHBOARD_TABLE, $display);
        self::assertStringContainsString('not granted', $display);

        // It says what to run, and it does not run it: the role is still missing
        // the privilege after the check has been and gone.
        self::assertStringContainsString('deploy:registry-grants ' . $this->role, $display);
        $this->assertRefused(sprintf('SELECT count(*) FROM %s', self::DASHBOARD_TABLE));
    }

    /**
     * **Excess, which is the quieter finding and the worse one.** A missing
     * `SELECT` is an outage somebody will report; an `INSERT` on a registry table
     * is §4.4's guarantee not holding while every page works perfectly.
     *
     * The corroboration runs the insert **successfully** as the role, so this
     * cannot pass for a check that reports a privilege the cluster does not
     * actually hold.
     */
    public function testAPrivilegeBeyondSelectIsReported(): void
    {
        $this->administrator->executeStatement(sprintf(
            'GRANT INSERT ON TABLE tenant TO %s',
            $this->quote($this->role),
        ));

        // No row is produced, so nothing is written to the registry and no
        // constraint can fire — but PostgreSQL still checks INSERT on the target
        // relation, so this only succeeds because the privilege is really held.
        $this->connectAsTheRestrictedRole()->executeStatement(
            "INSERT INTO tenant (slug) SELECT 'never-written' WHERE false",
        );

        $tester = $this->check();

        self::assertSame(3, $tester->getStatusCode());

        $display = $tester->getDisplay();

        self::assertStringContainsString('tenant', $display);
        self::assertStringContainsString('INSERT', $display);
        self::assertStringContainsString('beyond SELECT', $display);
    }

    /**
     * The administration surface's tables are withheld on *every* privilege, so
     * a bare `SELECT` on one of them is a finding too.
     *
     * `operator` is the sharpest of the four: a customer-facing instance able to
     * read it reads the hashes of the people who administer every tenant.
     */
    public function testReadingAnAdministrationTableIsReported(): void
    {
        $this->administrator->executeStatement(sprintf(
            'GRANT SELECT ON TABLE %s TO %s',
            self::WITHHELD_TABLE,
            $this->quote($this->role),
        ));

        self::assertSame(
            0,
            (int) $this->connectAsTheRestrictedRole()->fetchOne(
                sprintf('SELECT count(*) FROM %s WHERE false', self::WITHHELD_TABLE),
            ),
            'the role really can read the administration surface, which is the finding under test',
        );

        $tester = $this->check();

        self::assertSame(3, $tester->getStatusCode());

        $display = $tester->getDisplay();

        self::assertStringContainsString(self::WITHHELD_TABLE, $display);
        self::assertStringContainsString('withheld', $display);
    }

    /**
     * **The check and the grant cannot disagree**, asserted against the generator
     * rather than against a list written here.
     *
     * With every privilege revoked, what the audit reports as missing has to be
     * exactly {@see RegistryGrants::readableTables()} — the same method
     * `deploy:registry-grants` builds its `GRANT`s from. A test naming today's
     * seven tables would pass for a check that had quietly stopped following the
     * mapping, which is the entire failure this ticket is about;
     * {@see \App\Tests\Unit\Deployment\RegistryPrivilegeExpectationsTest} covers
     * the other end, where an entity is invented and both lists have to grow.
     */
    public function testWhatIsCheckedIsWhatTheGrantGeneratorNames(): void
    {
        $this->administrator->executeStatement(sprintf(
            'REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM %s',
            $this->quote($this->role),
        ));

        $container = static::getContainer();

        $grants = $container->get(RegistryGrants::class);
        \assert($grants instanceof RegistryGrants);

        $privileges = $container->get(RegistryPrivileges::class);
        \assert($privileges instanceof RegistryPrivileges);

        $report = $privileges->audit($this->role);

        self::assertSame($grants->readableTables(), array_keys($report->missing));
        self::assertSame(
            array_fill(0, \count($grants->readableTables()), ['SELECT']),
            array_values($report->missing),
        );
    }

    /**
     * A named role the cluster does not have is a finding rather than a skip.
     *
     * An installation that set `XIVI_PUBLIC_ROLE` runs the split deployment, and
     * a customer-facing instance whose role does not exist cannot open a
     * connection at all — the loudest version of this ticket's failure, usually
     * one typo away.
     */
    public function testARoleThatDoesNotExistIsReported(): void
    {
        $tester = $this->check($this->role . '_typo');

        self::assertSame(3, $tester->getStatusCode());
        // Not "does not exist": SymfonyStyle wraps its error block at 80 columns
        // and the role name here is long enough to break that phrase in half.
        self::assertStringContainsString('not exist in this cluster', $tester->getDisplay());
    }

    /**
     * A role that bypasses privilege checks entirely, which is what a
     * `DATABASE_URL` still carrying the administrator's credentials produces.
     *
     * Reported on its own rather than as one finding per table: it is not a grant
     * that went wrong, it is §4.4 not applying to this instance.
     */
    public function testASuperuserRoleIsReported(): void
    {
        if ((int) $this->administrator->fetchOne(
            'SELECT rolsuper::int FROM pg_catalog.pg_roles WHERE rolname = current_user',
        ) !== 1) {
            self::markTestSkipped('this cluster\'s test account cannot make another role a superuser');
        }

        $this->administrator->executeStatement(sprintf('ALTER ROLE %s SUPERUSER', $this->quote($this->role)));

        try {
            $tester = $this->check();

            self::assertSame(3, $tester->getStatusCode());
            self::assertStringContainsString('superuser', $tester->getDisplay());
        } finally {
            // Before `dropRole()` runs, because a superuser is a thing worth
            // taking away even if the drop below fails.
            $this->administrator->executeStatement(sprintf('ALTER ROLE %s NOSUPERUSER', $this->quote($this->role)));
        }
    }

    /**
     * An installation that runs one image says nothing and the deploy carries on.
     *
     * `XIVI_PUBLIC_ROLE` is empty here, as it is in a fresh checkout, so this is
     * the shipped behaviour: a full sentence and exit 0. A check that stopped
     * every single-instance deploy would be a check somebody appends `|| true` to
     * within the week, and then it is not a check.
     */
    public function testAnInstallationThatConfiguresNoRoleIsNotStopped(): void
    {
        $tester = $this->check('');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(RegistryPrivileges::VARIABLE, $tester->getDisplay());
    }

    /** Runs the command the way `bin/deploy` does, against this class's role unless told otherwise. */
    private function check(?string $role = null): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        $tester = new CommandTester((new Application($kernel))->find('deploy:check-grants'));
        $tester->execute(['role' => $role ?? $this->role]);

        return $tester;
    }

    /**
     * That PostgreSQL refuses `$sql` **for want of a privilege**, when it is the
     * restricted role running it.
     *
     * SQLSTATE rather than the message text: `permission denied for table …` is
     * translated by the server's own `lc_messages`, and a check on prose is a
     * test that passes on a German cluster by accident.
     */
    private function assertRefused(string $sql): void
    {
        try {
            $this->connectAsTheRestrictedRole()->executeStatement($sql);
        } catch (DriverException $e) {
            self::assertSame('42501', $e->getSQLState(), sprintf('refused, but not for a privilege: %s', $e->getMessage()));

            return;
        }

        self::fail(sprintf('"%s" was not refused, so this role is not restricted at all', $sql));
    }

    /**
     * A connection **as the role under test**, with the administrator's
     * parameters and nothing of the administrator's authority.
     *
     * This is the line to change to check that this class proves anything: with
     * the administrator's credentials in it, every refusal below stops being a
     * refusal.
     */
    private function connectAsTheRestrictedRole(): Connection
    {
        $params = $this->administrator->getParams();

        $params['user'] = $this->role;
        $params['password'] = self::PASSWORD;

        // Whatever DAMA and the tenant middleware wrapped the real connection in,
        // this one is a plain connection to the same database.
        unset($params['wrapperClass']);

        return DriverManager::getConnection($params);
    }

    /** Exactly what `deploy:registry-grants` prints, run as the administrator. */
    private function grantWhatThisBuildAsksFor(): void
    {
        $grants = static::getContainer()->get(RegistryGrants::class);
        \assert($grants instanceof RegistryGrants);

        foreach ($grants->statements($this->role) as $statement) {
            $this->administrator->executeStatement($statement);
        }
    }

    /**
     * Removes the role, tolerating every state it might be in.
     *
     * `DROP OWNED BY` first, because PostgreSQL refuses to drop a role that is
     * still referenced by a privilege — and this one is granted several by
     * construction.
     */
    private function dropRole(): void
    {
        $exists = $this->administrator->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$this->role]);

        if ($exists === false) {
            return;
        }

        $quoted = $this->quote($this->role);

        $this->administrator->executeStatement(sprintf('ALTER ROLE %s NOSUPERUSER', $quoted));
        $this->administrator->executeStatement(sprintf('DROP OWNED BY %s', $quoted));
        $this->administrator->executeStatement(sprintf('DROP ROLE IF EXISTS %s', $quoted));
    }

    /** Identifier quoting, matching {@see RegistryGrants}'s own. */
    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

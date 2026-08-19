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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **That the customer-facing instance's database user really cannot write the
 * registry** (XIV-96, docs/architecture/deployment.md §4.4).
 *
 * ## Why this is a functional test and not an assertion about a string
 *
 * The easy version of this test reads the statements
 * {@see RegistryGrants::statements()} produces and checks that none of them says
 * `INSERT`. That would pass for a grant script that names the wrong tables, for
 * one that forgets `REVOKE`, for one PostgreSQL rejects outright, and — most
 * likely of all — for one that is correct and never gets run because the role
 * inherited write privileges from somewhere else. Every one of those is a
 * deployment where the sentence "the public instance cannot write to `tenant`"
 * is false while the check is green.
 *
 * So this makes a real role in the real control-plane database, runs the real
 * statements, opens a **second connection as that role**, and asks PostgreSQL.
 * The claim being made is about a database, and a database is what answers it.
 *
 * ## Why it can do that at all
 *
 * DAMA's transaction wrapping is switched off for the `control` connection
 * (`config/packages/test/dama_doctrine_test.yaml`), because creating a tenant
 * involves `CREATE DATABASE` and that cannot be rolled back. The same property
 * is what lets this test create a role: a role created inside a transaction that
 * is never committed would not exist for a second connection to log in as, so
 * the wrapping being off here is a precondition rather than a detail.
 *
 * The price is that this test really does leave something behind if it dies
 * badly, which is what `tearDown()` is for and why the drop is written to
 * succeed against a role that is half-made.
 *
 * ## Parallel workers
 *
 * A role is a **cluster-wide** object, not a per-database one, so eight paratest
 * workers creating `xivi_public` would be eight workers sharing one — and, worse,
 * dropping each other's between the grant and the assertion. The name therefore
 * carries `%app.tenant_object_prefix%`, which is the same mechanism XIV-9 and
 * XIV-51 built for exactly this: one namespace per worker and one per checkout
 * above it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RegistryGrantsTest extends KernelTestCase
{
    /** The password the role logs in with. It exists for one process and never leaves this file. */
    private const string PASSWORD = 'not-a-secret-this-role-lives-for-one-test';

    private Connection $administrator;

    /** The role under test, namespaced per worker and per checkout. */
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
        $this->role = $prefix . 'public_reader';

        // Idempotent on the way in rather than trusting the way out, which is
        // the same argument `SharesATenant` makes about tenant databases: a run
        // that died halfway leaves the object standing, and the next run is the
        // one that has to cope.
        $this->dropRole();

        $this->administrator->executeStatement(sprintf(
            'CREATE ROLE %s LOGIN PASSWORD %s',
            $this->quote($this->role),
            $this->administrator->quote(self::PASSWORD),
        ));

        $grants = $container->get(RegistryGrants::class);
        \assert($grants instanceof RegistryGrants);

        foreach ($grants->statements($this->role) as $statement) {
            $this->administrator->executeStatement($statement);
        }
    }

    protected function tearDown(): void
    {
        $this->dropRole();

        parent::tearDown();
    }

    /**
     * The half that must work. A public instance that cannot read the registry
     * cannot resolve a hostname, which is to say it cannot serve anybody at all
     * (§3.1) — so "grants nothing" is not the safe failure it sounds like.
     */
    public function testThePublicRoleCanReadTheRegistry(): void
    {
        $public = $this->connectAsThePublicRole();

        // The count is uninteresting; that the statement completes is the whole
        // assertion. A role without SELECT gets SQLSTATE 42501 here instead.
        $count = $public->fetchOne('SELECT count(*) FROM tenant');

        self::assertIsNumeric($count);
    }

    /** And its domains, which is the other half of turning a Host header into a customer. */
    public function testThePublicRoleCanReadTenantDomains(): void
    {
        $public = $this->connectAsThePublicRole();

        self::assertIsNumeric($public->fetchOne('SELECT count(*) FROM tenant_domain'));
    }

    /**
     * The one the ticket turns on. An `INSERT INTO tenant` from the process
     * facing the internet is not a thing that should be possible, whatever the
     * routing says.
     */
    public function testThePublicRoleCannotCreateATenant(): void
    {
        $public = $this->connectAsThePublicRole();

        $this->expectException(DbalException::class);

        $public->executeStatement(
            "INSERT INTO tenant (slug, name, status, created_at) VALUES ('smuggled', 'Smuggled', 'active', now())",
        );
    }

    /**
     * Written separately from the insert because they fail for the same reason
     * and would be lost together: a role that could `UPDATE` could point a
     * customer's hostname at another customer's database, which is a worse
     * outcome than being able to add a row nobody has a hostname for.
     */
    public function testThePublicRoleCannotRewriteATenant(): void
    {
        $public = $this->connectAsThePublicRole();

        $this->expectException(DbalException::class);

        $public->executeStatement("UPDATE tenant SET name = 'renamed by the public instance'");
    }

    /** And cannot remove one, which is the same privilege from the other end. */
    public function testThePublicRoleCannotDeleteATenant(): void
    {
        $public = $this->connectAsThePublicRole();

        $this->expectException(DbalException::class);

        $public->executeStatement("DELETE FROM tenant WHERE slug = 'nothing-matches-this'");
    }

    /**
     * The administration surface's own tables are not readable either, and that
     * is a stronger statement than "the public image has no code that reads
     * them".
     *
     * `operator` holds the password hashes of the people who can see every
     * customer at once. The image not containing the entity is what makes
     * reading it pointless; the grant is what makes it impossible, and the two
     * fail independently.
     */
    public function testThePublicRoleCannotReadOperators(): void
    {
        $public = $this->connectAsThePublicRole();

        $this->expectException(DbalException::class);

        $public->fetchOne('SELECT count(*) FROM operator');
    }

    /**
     * The generated list is derived from the mapping, and this is the assertion
     * that keeps it honest in the direction the functional tests cannot see.
     *
     * Everything above proves things about the tables that *are* granted. If a
     * future registry entity were added and this class kept passing without it —
     * because no test happens to read that table — the first person to find out
     * would be a customer meeting a permission error. So the list is asserted
     * against the mapping's own answer as well as exercised.
     */
    public function testEveryRegistryTableIsGrantedAndNoAdministrationTableIs(): void
    {
        $container = static::getContainer();
        $grants = $container->get(RegistryGrants::class);
        \assert($grants instanceof RegistryGrants);

        $readable = $grants->readableTables();

        self::assertContains('tenant', $readable);
        self::assertContains('tenant_domain', $readable);
        self::assertContains('module', $readable);
        self::assertContains(RegistryGrants::MIGRATIONS_TABLE, $readable);

        foreach ($grants->withheldTables() as $withheld) {
            self::assertNotContains(
                $withheld,
                $readable,
                sprintf('"%s" belongs to the administration surface and must not be readable here.', $withheld),
            );
        }

        self::assertContains('operator', $grants->withheldTables());
    }

    /**
     * A connection with the public instance's credentials and nothing else
     * changed: same host, same port, same database.
     *
     * Built from the administrator connection's own parameters rather than from
     * `DATABASE_URL`, so that it follows the test suite's database-name suffix
     * without this file having to know that one exists.
     */
    private function connectAsThePublicRole(): Connection
    {
        $params = $this->administrator->getParams();

        $params['user'] = $this->role;
        $params['password'] = self::PASSWORD;

        // Whatever DAMA and the tenant middleware wrapped the real connection
        // in, this one is a plain connection to the same database. Keeping the
        // driver and the platform from the original is what makes it the same
        // database rather than a different one that happens to answer.
        unset($params['wrapperClass']);

        return DriverManager::getConnection($params);
    }

    /**
     * Removes the role, tolerating every state it might be in.
     *
     * `DROP OWNED BY` first, because PostgreSQL refuses to drop a role that is
     * still referenced by a privilege — and this role is granted several by
     * construction, so the plain `DROP ROLE` would fail every single time. The
     * `IF EXISTS` is for the setUp call, which runs before the role is made.
     */
    private function dropRole(): void
    {
        $exists = $this->administrator->fetchOne(
            'SELECT 1 FROM pg_roles WHERE rolname = ?',
            [$this->role],
        );

        if ($exists === false) {
            return;
        }

        $quoted = $this->quote($this->role);

        $this->administrator->executeStatement(sprintf('DROP OWNED BY %s', $quoted));
        $this->administrator->executeStatement(sprintf('DROP ROLE IF EXISTS %s', $quoted));
    }

    /** Identifier quoting, matching {@see RegistryGrants}'s own. */
    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

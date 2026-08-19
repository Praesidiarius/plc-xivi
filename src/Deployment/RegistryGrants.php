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

namespace App\Deployment;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * **The SQL that makes a customer-facing instance unable to write to the
 * registry** (XIV-96, docs/architecture.md §4.4).
 *
 * ## The argument, which is not about network topology
 *
 * XIV-96 splits the deployment in two: a customer-facing instance built without
 * the administration surface, and an internal one that has it. Both talk to the
 * **same control-plane database**, because §3.1 established that they must — a
 * tenant request reads the registry before it can know whose request it is, so
 * an instance without that database cannot answer the first question it is
 * asked.
 *
 * That makes "which instance is on which network" the weakest boundary
 * available, because both instances are on the network that matters. The sharp
 * one is **two database users with different grants**. The public instance needs
 * to *read* a tenant row, its domains, its encrypted credential and the module
 * catalogue; it has never needed to write one and, with the administration
 * surface removed from its image, has nothing left that could. An `INSERT INTO
 * tenant` arriving from the process facing the internet is not a thing that
 * should be possible, whatever the routing says and whatever a future bug in a
 * controller does.
 *
 * It is nearly free to arrange while an installation is being provisioned and
 * genuinely awkward once there are customers on it, which is why XIV-96 settles
 * it rather than leaving it as a note.
 *
 * ## What the public instance is allowed to touch, and why each one is on the list
 *
 * The tables come from the mapping rather than from a literal list, which is the
 * whole reason this is a class and not a `.sql` file in a directory. `App\Registry`
 * is what a customer's request reads (§3.1); adding an entity to it is a normal
 * thing to do, and a hand-written grant script would be correct until the first
 * time somebody did — and then wrong in the direction that takes a deployment
 * down with a permission error on a table nobody remembered to list.
 *
 * The one addition to the mapping is `doctrine_migration_versions`, which is not
 * an entity and is not optional either: the public image's entrypoint asks
 * whether the control-plane schema is up to date before it serves anything, and
 * that question is a `SELECT` on that table. See `frankenphp/docker-entrypoint.sh`
 * for why the public image asks rather than migrates.
 *
 * ## What it deliberately does not grant
 *
 * Everything else. No `INSERT`, `UPDATE`, `DELETE` or `TRUNCATE` anywhere, no
 * DDL, no sequence privileges — a role that cannot insert has no use for a
 * sequence — and **no access at all to `operator`, `signup_request` or
 * `tenant_usage`**, which are the administration surface's own tables
 * (`Xivi\ControlPlane\Entity`). Those are excluded by construction rather than
 * by name: this asks the mapping for `App\Registry\Entity` and nothing else, so
 * a table belonging to the other half of the database is never a candidate.
 *
 * The revoke comes first and is not decoration. `GRANT` is additive, so running
 * this against a role that was previously the *internal* instance's would leave
 * every write privilege it already had; starting from `REVOKE ALL` makes the
 * result a statement about the role rather than about the role's history.
 *
 * ## Why this prints SQL instead of running it
 *
 * Creating a database role is not something a running instance should be able to
 * do, and an application that could grant privileges to itself could be made to
 * grant itself others. Every statement here is one a database administrator runs
 * against the control-plane database with an account this application does not
 * have; what the application contributes is knowing exactly which tables the
 * list has to cover today, which is the part that goes stale.
 *
 * ## And what happens when nobody runs it
 *
 * Nothing, until a customer's dashboard answers 500. That gap is [XIV-143]'s:
 * {@see RegistryPrivileges} reads these same two lists and asks PostgreSQL what
 * the role is actually holding, `bin/console deploy:check-grants` reports the
 * difference, and `bin/deploy` runs it on every release. The derivation below is
 * therefore read twice — once to say what to grant, once to say whether it was —
 * which is the property that stops the check and the grant from disagreeing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RegistryGrants
{
    /**
     * Doctrine Migrations' own bookkeeping table.
     *
     * Not an entity, so it cannot come out of the mapping, and not omitted
     * either: the customer-facing image reads it on every container start to
     * refuse to serve against a schema older than itself. The name is
     * `doctrine_migrations.yaml`'s default and this application does not change
     * it; a deployment that did would have to say so here too, which is a fair
     * price for not inventing a second place to configure it.
     */
    public const string MIGRATIONS_TABLE = 'doctrine_migration_versions';

    public function __construct(
        private EntityManagerInterface $controlPlane,
        private Connection $control,
    ) {
    }

    /**
     * Every table the customer-facing instance may read, in a stable order.
     *
     * Sorted so that two runs of the command produce byte-identical output —
     * this ends up pasted into somebody's provisioning notes, and a diff that
     * moves lines around because Doctrine returned its metadata in a different
     * order is a diff nobody reads twice.
     *
     * @return list<string>
     */
    public function readableTables(): array
    {
        $tables = [self::MIGRATIONS_TABLE];

        foreach ($this->controlPlane->getMetadataFactory()->getAllMetadata() as $metadata) {
            \assert($metadata instanceof ClassMetadata);

            // The registry only. The administration surface's entities are
            // mapped on this same entity manager and into this same database
            // (§3.1), and the whole point of the exercise is that the public
            // instance cannot see them — so the filter is on the namespace the
            // application owns rather than on a list of table names to keep in
            // step with two packages.
            if (!str_starts_with($metadata->getName(), 'App\\Registry\\Entity\\')) {
                continue;
            }

            $tables[] = $metadata->getTableName();
        }

        sort($tables);

        return array_values(array_unique($tables));
    }

    /**
     * The statements that leave `$role` able to read the registry and nothing
     * else.
     *
     * Returned as a list rather than as one blob so that a caller can print them
     * one per line, and so that the test can assert about them individually
     * rather than by matching against a paragraph.
     *
     * The role is expected to exist already: creating one means choosing a
     * password, and a password chosen by a command that prints its output to a
     * terminal is a password in somebody's shell history. The command says so
     * and shows the `CREATE ROLE` to run first.
     *
     * @return list<string>
     */
    public function statements(string $role): array
    {
        $role = $this->quoteIdentifier($role);
        $database = $this->quoteIdentifier($this->control->getDatabase() ?? 'app');

        $statements = [
            // Start from nothing, so the result describes the role rather than
            // its history. `ALL PRIVILEGES` covers the write privileges an
            // internal instance's role would have; the schema-level revoke
            // covers `CREATE`, which is what would let it make a table of its
            // own and is granted to `PUBLIC` on databases created before
            // PostgreSQL 15.
            sprintf('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM %s;', $role),
            sprintf('REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM %s;', $role),
            sprintf('REVOKE CREATE ON SCHEMA public FROM %s;', $role),

            // Then the two things any reader needs before a `SELECT` can even be
            // attempted: reaching the database and seeing into the schema.
            sprintf('GRANT CONNECT ON DATABASE %s TO %s;', $database, $role),
            sprintf('GRANT USAGE ON SCHEMA public TO %s;', $role),
        ];

        foreach ($this->readableTables() as $table) {
            $statements[] = sprintf('GRANT SELECT ON TABLE %s TO %s;', $this->quoteIdentifier($table), $role);
        }

        return $statements;
    }

    /**
     * The administration surface's tables, named only so that the command can
     * say out loud which ones it is *not* granting.
     *
     * A grant script that lists what it allows is checkable; one that also says
     * what it withheld is auditable, and the difference matters here because the
     * withheld set is the interesting one. Derived the same way and from the
     * same metadata, so the two lists cannot be describing different databases.
     *
     * @return list<string>
     */
    public function withheldTables(): array
    {
        $tables = [];

        foreach ($this->controlPlane->getMetadataFactory()->getAllMetadata() as $metadata) {
            \assert($metadata instanceof ClassMetadata);

            if (str_starts_with($metadata->getName(), 'App\\Registry\\Entity\\')) {
                continue;
            }

            $tables[] = $metadata->getTableName();
        }

        sort($tables);

        return array_values(array_unique($tables));
    }

    /**
     * PostgreSQL identifier quoting, done here rather than through DBAL's
     * platform because these strings are printed for a human to run rather than
     * executed.
     *
     * A role name with a double quote in it is not a role name anybody should
     * have, and doubling the quote is the standard's own escape, so this is
     * correct as well as short.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

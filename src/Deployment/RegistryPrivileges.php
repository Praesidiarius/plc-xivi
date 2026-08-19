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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * **What the customer-facing instance's database role can actually do, against
 * what {@see RegistryGrants} says it should** (XIV-143, docs/architecture.md
 * §4.4).
 *
 * ## The gap this closes
 *
 * §4.4 gives the customer-facing role `SELECT` on a *derived* list of tables:
 * {@see RegistryGrants::readableTables()} asks the mapping for
 * `App\Registry\Entity\` and grants exactly what it finds. That derivation is
 * the good half — a release that adds a registry entity cannot leave a
 * hand-maintained script behind. The bad half is that the grant only exists in
 * the database once somebody has run `bin/console deploy:registry-grants` and
 * pasted its output into `psql`, and **nothing checked that they had**.
 *
 * It has happened twice. [XIV-120] added `notice` and `notice_recipient`,
 * [XIV-123] added `support_request`; both shipped a `CHANGELOG` bullet saying
 * the command has to be re-run, and a changelog bullet works exactly as well as
 * whoever reads changelogs. An installation upgraded without it has a role whose
 * privileges match the *previous* release's entity list, and the way that
 * presents is `SQLSTATE[42501]: permission denied for table notice` on the
 * dashboard of every user of every tenant — because the notice widget is on the
 * dashboard (§8.3.1).
 *
 * §8.3.1 prefers that loudness to a page that quietly draws nothing, and it is
 * right to. But loud *at the customer* is still the customer finding out, and
 * this installation already owns the better answer next door: `deploy:check-hosts`
 * exists so that a deploy discovers a too-narrow trusted-host pattern before a
 * browser does. This is the same shape for the same reason, one table over.
 *
 * ## What it asks the database, and why that question rather than a cleverer one
 *
 * `has_table_privilege(role, table, privilege)` — for every table on both of
 * {@see RegistryGrants}'s lists, for each of the seven table privileges.
 *
 * The alternative was reading `pg_class.relacl` and comparing it to the ACL the
 * generated `GRANT`s would have produced. That answers "was this exact statement
 * run", which is a question about history; `has_table_privilege` answers **"can
 * this role do it"**, which is the question a customer's request will ask. The
 * difference is not academic: a privilege reached through `GRANT`ed role
 * membership, or one held by `PUBLIC`, is invisible in the first answer and
 * decisive in the second. A deployment that made its public role a member of the
 * internal one has every write privilege in the database and an ACL that
 * mentions nothing.
 *
 * The same property is why a superuser is reported on its own line and nothing
 * else is computed for it ({@see RegistryPrivilegeReport::$isSuperuser}). A
 * superuser passes every `has_*_privilege` call there is, so the honest report
 * is "this role bypasses privilege checks entirely" rather than eighty rows
 * saying it holds `TRUNCATE` on everything. It is also not a hypothetical
 * finding: pointing the customer-facing instance's `DATABASE_URL` at the
 * administrator's credentials is a one-line mistake in an environment file, it
 * works perfectly, and it silently undoes the whole of §4.4.
 *
 * `MAINTAIN` — PostgreSQL 17's eighth table privilege — is deliberately not
 * probed. It would make this class refuse to run against a 16 cluster for a
 * privilege that grants no ability to read or change a single row, and
 * `POSTGRES_VERSION` is a variable a deployment is allowed to set.
 *
 * ## Excess is a finding, not only absence
 *
 * A role holding `INSERT` on a registry table is a worse discovery than one
 * missing `SELECT`: the second is an outage, the first is §4.4's guarantee
 * quietly not holding while everything looks healthy. The same query answers
 * both, so both are reported — and for the administration surface's own tables
 * ({@see RegistryGrants::withheldTables()}) even `SELECT` is excess, because
 * §4.4's sentence about `operator`, `signup_request` and `tenant_usage` is "no
 * access at all".
 *
 * That is the generalisation of what [XIV-120] and [XIV-123] each asserted for
 * their own two tables in a test. Those tests prove the refusal against the
 * statements this build generates; this proves it against the privileges the
 * cluster is actually holding, on every registry table, at deploy time.
 *
 * ## It checks and does not repair
 *
 * Deliberately, and the decision belongs here rather than to whoever reads the
 * output. Re-running `deploy:registry-grants` is idempotent, so "check" and
 * "fix" are one line apart — but the line is §4.4's own: **a running instance
 * that could grant privileges to itself could be made to grant itself others.**
 * That argument is why `deploy:registry-grants` prints SQL instead of executing
 * it, and a checker that quietly repaired what it found would give back exactly
 * the capability that command declines to have, with less ceremony.
 *
 * There is a second reason that survives even if somebody disagrees with the
 * first. A repair is a `REVOKE ALL` followed by the grants this build believes
 * in, so a check that repaired would *remove* a privilege a database
 * administrator had added on purpose — and it would do it during a deploy, from
 * a script, with the finding scrolling past in a log. Saying "run this" costs
 * one command and keeps the person who owns the database in the loop.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RegistryPrivileges
{
    /**
     * The role a deployment's customer-facing instance connects as.
     *
     * Named here so that every message can print it, exactly as
     * {@see TrustedHosts::VARIABLE} is. Empty is the shipped default and means
     * this installation has not said it runs a second, restricted instance — see
     * {@see configuredRole()} for why that is a pass rather than a failure.
     */
    public const string VARIABLE = 'XIVI_PUBLIC_ROLE';

    /**
     * The subject a schema-level finding is reported against.
     *
     * Findings are keyed by *what the privilege is on*, and that is a table name
     * for all but two of them — so the two exceptions are spelled the way SQL
     * spells them rather than dressed up as tables, and an operator reading the
     * report can tell them apart at a glance.
     */
    private const string SCHEMA = 'schema "public"';

    /**
     * Every privilege PostgreSQL can hold on a table, except `MAINTAIN`.
     *
     * Exactly one of them belongs to the customer-facing role. The list is
     * spelled out rather than derived because there is nowhere to derive it
     * from: the server publishes no catalogue of privilege names, and the set
     * has changed twice in twenty years.
     */
    private const array TABLE_PRIVILEGES = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'TRUNCATE',
        'REFERENCES',
        'TRIGGER',
    ];

    public function __construct(
        private RegistryGrants $grants,
        private Connection $control,
        #[Autowire('%env(string:default::XIVI_PUBLIC_ROLE)%')]
        private string $configuredRole = '',
    ) {
    }

    /**
     * The role this deployment says its customer-facing instance connects as, or
     * an empty string.
     *
     * **Empty is a real answer and is the shipped default**, which is the same
     * shape `XIVI_TRUSTED_DOMAINS` and [XIV-126]'s ping list already have. A
     * single-instance installation — one image, one database user, everything
     * this repository does before §4.4 — has no restricted role to compare
     * anything against, and a deploy that stopped because it could not find one
     * would be a gate that gets run with `|| true` by the end of the week.
     *
     * The cost of that choice is that an installation which *does* run the split
     * deployment has to say so once. That is the right price: the alternative is
     * guessing the role name, and a check that silently audits a role nobody
     * uses passes for ever while proving nothing at all.
     */
    public function configuredRole(): string
    {
        return trim($this->configuredRole);
    }

    /**
     * What `$role` is holding today, against what this build's mapping says it
     * should be holding.
     *
     * Every expectation in here comes from {@see RegistryGrants} — the same
     * object `deploy:registry-grants` generates its SQL from — so the check and
     * the grant cannot disagree about what a registry table is. That is the
     * whole point rather than a tidy detail: a list written out here would be
     * correct on the day it was written and would then start passing an
     * installation that is missing the table nobody remembered, which is
     * precisely the failure being closed.
     */
    public function audit(string $role): RegistryPrivilegeReport
    {
        $role = trim($role);

        $existing = $this->control->fetchAssociative(
            'SELECT rolsuper::int AS superuser FROM pg_catalog.pg_roles WHERE rolname = ?',
            [$role],
        );

        if ($existing === false) {
            return RegistryPrivilegeReport::noSuchRole($role);
        }

        if ((int) $existing['superuser'] === 1) {
            return RegistryPrivilegeReport::superuser($role);
        }

        $readable = $this->grants->readableTables();
        $withheld = $this->grants->withheldTables();

        $held = $this->heldPrivileges($role, [...$readable, ...$withheld]);

        $missing = [];
        $excess = [];
        $absent = [];

        foreach ($readable as $table) {
            if (!\array_key_exists($table, $held)) {
                // The table is not in the database at all. That is not a grant
                // problem and it is not this class's to fix, but it is a finding:
                // this build's mapping has an entity whose table the
                // control-plane schema does not have, so the customer-facing
                // instance is about to meet `relation "…" does not exist` rather
                // than a permission error. In `bin/deploy` it cannot normally
                // happen — the control-plane migration has just run — which is
                // exactly why it is worth saying out loud when it does.
                $absent[] = $table;

                continue;
            }

            if (!\in_array('SELECT', $held[$table], true)) {
                $missing[$table] = ['SELECT'];
            }

            $beyond = array_values(array_diff($held[$table], ['SELECT']));

            if ($beyond !== []) {
                $excess[$table] = $beyond;
            }
        }

        foreach ($withheld as $table) {
            // Absent administration tables are silence rather than a finding.
            // The customer-facing image does not contain the code that reads
            // them, so a schema without one is somebody else's business; what
            // matters here is only that this role cannot touch the ones that do
            // exist.
            if (($held[$table] ?? []) !== []) {
                $excess[$table] = $held[$table];
            }
        }

        $access = $this->control->fetchAssociative(
            <<<'SQL'
                SELECT current_database() AS database,
                       has_database_privilege(:role, current_database(), 'CONNECT')::int AS may_connect,
                       has_schema_privilege(:role, 'public', 'USAGE')::int AS may_use,
                       has_schema_privilege(:role, 'public', 'CREATE')::int AS may_create
                SQL,
            ['role' => $role],
        );

        \assert(\is_array($access));

        // The two privileges without which every `SELECT` above is refused for a
        // reason that has nothing to do with the table it names, and the one
        // whose presence would let this role make a table of its own. `CREATE ON
        // SCHEMA public` is granted to `PUBLIC` on any database created before
        // PostgreSQL 15, which is why RegistryGrants revokes it explicitly and
        // why it is worth checking rather than assuming.
        $database = sprintf('database "%s"', (string) $access['database']);

        if ((int) $access['may_connect'] !== 1) {
            $missing[$database] = ['CONNECT'];
        }

        if ((int) $access['may_use'] !== 1) {
            $missing[self::SCHEMA] = ['USAGE'];
        }

        if ((int) $access['may_create'] === 1) {
            $excess[self::SCHEMA] = ['CREATE'];
        }

        ksort($missing);
        ksort($excess);

        return new RegistryPrivilegeReport(
            role: $role,
            roleExists: true,
            isSuperuser: false,
            missing: $missing,
            excess: $excess,
            absent: $absent,
            readable: $readable,
            withheld: $withheld,
        );
    }

    /**
     * Which of `$tables` exist, and which of {@see TABLE_PRIVILEGES} `$role`
     * holds on each.
     *
     * One query rather than one per table, and the join against `pg_class` does
     * two jobs: it turns the name into an OID, which is the overload of
     * `has_table_privilege` that cannot be confused by quoting, and it drops the
     * tables that do not exist — where passing a missing name as text would
     * raise `undefined_table` and take the whole check down over a schema
     * question.
     *
     * @param list<string> $tables
     *
     * @return array<string, list<string>> the tables that exist, each mapped to the
     *                                     privileges the role actually holds on it
     */
    private function heldPrivileges(string $role, array $tables): array
    {
        $tables = array_values(array_unique($tables));

        if ($tables === []) {
            return [];
        }

        $privileges = implode(', ', array_map(
            static fn (string $privilege): string => "'" . $privilege . "'",
            self::TABLE_PRIVILEGES,
        ));

        $rows = $this->control->fetchAllAssociative(
            sprintf(
                <<<'SQL'
                    SELECT c.relname AS table_name,
                           p.privilege AS privilege,
                           has_table_privilege(:role, c.oid, p.privilege)::int AS granted
                    FROM pg_catalog.pg_class c
                    JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                    CROSS JOIN unnest(ARRAY[%s]::text[]) AS p(privilege)
                    WHERE n.nspname = 'public'
                      AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
                      AND c.relname IN (:tables)
                    SQL,
                $privileges,
            ),
            ['role' => $role, 'tables' => $tables],
            ['tables' => ArrayParameterType::STRING],
        );

        $held = [];

        foreach ($rows as $row) {
            $table = (string) $row['table_name'];

            // Every existing table gets an entry even when it holds nothing, so
            // that the caller can tell "granted no privileges" from "not in this
            // database", which are the two findings that must not be confused.
            $held[$table] ??= [];

            if ((int) $row['granted'] === 1) {
                $held[$table][] = (string) $row['privilege'];
            }
        }

        return $held;
    }
}

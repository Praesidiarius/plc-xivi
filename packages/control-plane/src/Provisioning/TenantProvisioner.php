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

namespace Xivi\ControlPlane\Provisioning;

use App\Deployment\TrustedHosts;
use App\Registry\Entity\Tenant;
use App\Registry\Entity\TenantStatus;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenancy\Migration\TenantMigrator;
use App\Tenancy\Security\PasswordGenerator;
use App\Tenancy\Security\TenantSecretCipher;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Attachment\AttachmentRefused;
use App\Tenant\Attachment\AttachmentStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates a customer: control-plane row, database role, database, schema.
 *
 * This is the "provisioning is a console command, not a filesystem ritual" half
 * of docs/architecture/deployment.md §4 — nothing here writes configuration to disk, so there is no
 * per-domain file to drift.
 *
 * Each tenant gets its **own Postgres role**, and its database revokes CONNECT
 * from PUBLIC. That is what turns §4's isolation from "the application is
 * careful" into something the database enforces: a bug that hands Doctrine the
 * wrong tenant's DSN fails to connect instead of quietly reading another
 * customer's data.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantProvisioner
{
    /**
     * Also the tenant's database and role name, so: valid unquoted Postgres
     * identifier.
     *
     * **Public since XIV-98**, and read from exactly one other place:
     * {@see ProvisioningSlug}, which translates a self-service slug into one of
     * these and has to be able to say whether the result would be accepted. It
     * was private before because nobody else had a question about it; publishing
     * a constant is a smaller change than publishing a second copy of the same
     * regular expression, and a second copy is what the alternative was. Reading
     * it does not reach this service — it is a compile-time constant, so the
     * code boundary §8.12 built between the public intake and this class is
     * untouched.
     */
    public const string SLUG_PATTERN = '/^[a-z][a-z0-9_]{1,55}$/';

    /**
     * `55006 object_in_use` — what a `DROP DATABASE` says when somebody is
     * attached. Matched on the SQLSTATE rather than on the message, because the
     * message ("database … is being accessed by other users") is localised by the
     * server's `lc_messages` and the code is not. The failure XIV-94 was reported
     * from is literally this string.
     */
    private const string OBJECT_IN_USE = '55006';

    /**
     * `42501 insufficient_privilege` — what `pg_terminate_backend()` says when the
     * provisioning credentials may not signal somebody else's backend.
     * {@see disconnectEverySessionOn()} for when that happens and what to grant.
     */
    private const string INSUFFICIENT_PRIVILEGE = '42501';

    /**
     * What a tenant's database and role are called, before the slug.
     *
     * Configurable for one reason (XIV-9): databases and roles are **cluster**
     * objects while the registry naming them is one database, so two test
     * workers with a registry each would both claim `tenant_test_locale` and
     * the second would connect with a password only the first's registry knows.
     * Giving each worker its own prefix namespaces every object it creates
     * without a single test having to remember to.
     *
     * Production leaves it alone.
     */
    public const string OBJECT_PREFIX = 'tenant_';

    public function __construct(
        private EntityManagerInterface $controlPlane,
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
        private TenantMigrator $migrator,
        private TenantDsnParser $dsnParser,
        private TenantSecretCipher $cipher,
        /**
         * A customer's files, so that removing them is part of removing the
         * customer ([XIV-115], §4.1).
         *
         * The only thing here that is not SQL, and it is reached the same way
         * every tenant-scoped service is: switched into, asked, switched out of.
         * Provisioning does not create the directory, because nothing has to:
         * the local adapter makes one on the first write, and a customer who
         * never uploads anything should not have an empty folder standing for
         * them.
         */
        private AttachmentStore $attachments,
        /** DSN with `{database}` and `{user}` placeholders, used when no explicit DSN is given. */
        #[Autowire('%env(TENANT_DSN_TEMPLATE)%')]
        private string $dsnTemplate,
        /**
         * Credentials allowed to CREATE DATABASE, CREATE ROLE and — since XIV-94 —
         * to terminate a tenant's sessions before dropping its database. See `.env`
         * for why the last of those is not implied by the first two on Postgres 16
         * and later, and what a deployment that narrows this role has to grant it.
         */
        #[Autowire('%env(TENANT_ADMIN_DSN)%')]
        private string $adminDsn,
        /** @see OBJECT_PREFIX */
        #[Autowire('%app.tenant_object_prefix%')]
        private string $objectPrefix = self::OBJECT_PREFIX,
        /**
         * The hostnames this installation serves without a tenant (XIV-57): the
         * profiler's, the container's internal name, and — the one that matters
         * — the control plane's.
         *
         * @var list<string>
         */
        #[Autowire('%app.system_hosts%')]
        private array $systemHosts = [],
        /**
         * The hostnames this deployment answers to at all (XIV-93, §4.3).
         *
         * Nullable and defaulting to none so that a caller constructing this by
         * hand is not obliged to know about a deployment concern; autowiring
         * supplies it everywhere the application actually runs, and a null one
         * admits everything, which is what an unconfigured installation does.
         */
        private ?TrustedHosts $trustedHosts = null,
    ) {
    }

    /**
     * @param list<string> $hostnames the first one becomes the primary domain
     *
     * @throws ProvisioningFailed
     */
    public function provision(
        string $slug,
        string $name,
        array $hostnames,
        ?string $dsn = null,
        string $plan = 'standard',
        TenantStatus $status = TenantStatus::Active,
    ): Tenant {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw ProvisioningFailed::invalidSlug($slug);
        }

        if ($hostnames === []) {
            throw ProvisioningFailed::noHostname($slug);
        }

        if ($this->tenants->findOneBySlug($slug) !== null) {
            throw ProvisioningFailed::slugTaken($slug);
        }

        $hostnames = array_map(TenantResolver::normalize(...), $hostnames);
        foreach ($hostnames as $hostname) {
            if ($this->tenants->hostnameIsTaken($hostname)) {
                throw ProvisioningFailed::hostnameTaken($hostname);
            }

            // **A tenant cannot be put on a host that serves no tenant**
            // (XIV-57). `TenantRequestListener` checks `app.system_hosts` before
            // it asks the registry anything, so a row created here would simply
            // never be reached — and for the control plane's own hostname the
            // silence would be worse than useless: the customer would get the
            // platform's sign-in page instead of their own, and nobody would find
            // out from a log. Refused at the moment somebody types it, with the
            // reason, rather than discovered later.
            if (\in_array($hostname, array_map(TenantResolver::normalize(...), $this->systemHosts), true)) {
                throw ProvisioningFailed::hostnameIsReserved($hostname);
            }

            // **And it cannot be a host the deployment does not answer to at
            // all** (XIV-93). The check above is about a hostname this
            // application serves for a different purpose; this one is about a
            // hostname it does not serve at any purpose, because
            // `framework.trusted_hosts` refuses it before routing. Both fail the
            // same way if they are not caught here — a row that exists, a
            // customer who was given an address, and nothing anywhere saying why
            // it is dead — so they are refused in the same place, at the moment
            // somebody types it.
            //
            // An unconfigured installation admits everything and this never
            // fires, which keeps `tenant:provision` in a fresh checkout exactly
            // as it was.
            if ($this->trustedHosts !== null && !$this->trustedHosts->admits($hostname)) {
                throw ProvisioningFailed::hostnameIsNotTrusted($hostname, $this->trustedHosts->domains());
            }
        }

        $objectName = $this->objectPrefix . $slug;
        $password = PasswordGenerator::machine();

        $tenant = new Tenant($slug, $name, $dsn ?? $this->dsnFor($objectName), $plan);
        $tenant->setEncryptedDatabasePassword($this->cipher->encrypt($password));
        foreach ($hostnames as $index => $hostname) {
            $tenant->addDomain($hostname, primary: $index === 0);
        }

        // Persisted before the database exists, so a failure halfway leaves a row
        // in "provisioning" to look at rather than an orphaned database.
        $this->controlPlane->persist($tenant);
        $this->controlPlane->flush();

        $this->createRoleAndDatabase($tenant, $password);
        sodium_memzero($password);

        $this->switcher->runFor($tenant, fn () => $this->migrator->migrateToLatest());

        $tenant->markProvisioned($status);
        $this->controlPlane->flush();

        return $tenant;
    }

    /**
     * Brings an existing tenant's schema up to date. Separate from provisioning
     * because every deploy has to run it for every tenant (docs/architecture/deployment.md §4).
     *
     * @return list<string> executed migration versions
     */
    public function migrate(Tenant $tenant): array
    {
        return $this->switcher->runFor($tenant, fn () => $this->migrator->migrateToLatest());
    }

    /**
     * Removes a tenant completely: database, role, row — in that order.
     *
     * Destructive and irreversible — docs/architecture/deployment.md §4 makes export-on-churn a
     * per-customer operation, so take the dump first.
     *
     * ## The cluster goes first and the registry last (XIV-94)
     *
     * It used to be the other way round, and the reason it is not any more is
     * that the two orderings fail differently rather than equally. Removing the
     * row first and then failing to drop leaves a database and a role that
     * **nothing knows about**: the registry is where every tool in this project
     * starts — `tenant:list`, `tenant:inspect`, the control-plane pages, this
     * command's own lookup — so an orphan out there is invisible to all of them
     * and can only be cleared by somebody who already happens to know the
     * database name. Dropping first and failing leaves the opposite: a row
     * pointing at nothing, which every one of those tools *can* see and which
     * re-running this method removes, since both drops are `IF EXISTS` and step
     * straight over what is already gone. One failure mode is recoverable by
     * typing the same line twice and the other needs `psql` and a memory, so the
     * order is not a preference.
     *
     * ## Disconnecting people is the deliberate part
     *
     * Postgres refuses `DROP DATABASE` while any session is attached, and §4.1
     * settled that a **live** tenant may be removed — `suspended` is explicitly
     * not a prerequisite — so the tenant most likely to be dropped is exactly the
     * one with sessions open to it. Clearing the switcher only settles *our* end
     * of that; see {@see disconnectEverySessionOn()} for the rest, and for why
     * throwing somebody out mid-request is written as a step of its own rather
     * than smuggled in as a keyword on the drop.
     *
     * @throws TenantRemovalFailed with the state it stopped in
     */
    public function deprovision(Tenant $tenant): void
    {
        $slug = $tenant->getSlug();
        $database = $this->dsnParser->databaseName($tenant->getDatabaseDsn());
        $role = $this->dsnParser->userName($tenant->getDatabaseDsn()) ?? $this->objectPrefix . $slug;

        // Our own open connection to that database would block the drop. Done
        // before anything else because it is the one session we can end politely:
        // everything below terminates other people's backends, and terminating
        // our own would be both rude and impossible from the same connection.
        $this->switcher->clear();

        $admin = $this->adminConnection();

        try {
            $this->disconnectEverySessionOn($admin, $slug, $database, $role);

            try {
                // `WITH (FORCE)` is not the mechanism, it is the race guard —
                // see disconnectEverySessionOn() for which is which.
                $admin->executeStatement(sprintf(
                    'DROP DATABASE IF EXISTS %s WITH (FORCE)',
                    $this->quoteName($admin, $database),
                ));
            } catch (DriverException $e) {
                throw $e->getSQLState() === self::OBJECT_IN_USE
                    ? TenantRemovalFailed::sessionsCameBack($slug, $database, $role, $e)
                    : TenantRemovalFailed::databaseSurvived($slug, $database, $role, $e);
            }

            try {
                $admin->executeStatement(sprintf('DROP ROLE IF EXISTS %s', $this->quoteName($admin, $role)));
            } catch (DriverException $e) {
                throw TenantRemovalFailed::roleSurvived($slug, $database, $role, $e);
            }
        } finally {
            $admin->close();
        }

        // **And the files, in the same command** ([XIV-115], §4.1). Not a
        // cleanup job nobody runs: a customer who has been removed has been
        // removed, and an attachment directory outliving the database it belonged
        // to is a copy of somebody's contracts on a disk with nothing left that
        // names it.
        //
        // **Between the role and the registry row**, and both halves of that are
        // deliberate. After the drops, because a removal that deleted a live
        // customer's files and then failed on `DROP DATABASE` would have
        // destroyed data while the tenant was still serving. Before the row,
        // because the directory is derived from the database name in that row
        // (§5.30) and deleting the row first leaves the files unfindable, which
        // is exactly the wreckage XIV-94 turned this ordering around to avoid.
        //
        // The switch opens no connection: the store asks the resolved tenant for
        // its database name and touches nothing else, which is what lets this run
        // against a database that has just been dropped.
        try {
            $this->switcher->runFor($tenant, $this->attachments->removeEverything(...));
        } catch (AttachmentRefused $e) {
            throw TenantRemovalFailed::filesSurvived($slug, $database, $role, $e);
        }

        try {
            $this->controlPlane->remove($tenant);
            $this->controlPlane->flush();
        } catch (\Throwable $e) {
            throw TenantRemovalFailed::registryRowSurvived($slug, $database, $role, $e);
        }
    }

    /**
     * Throws every other session off the tenant's database, on purpose (XIV-94).
     *
     * ## Why this is a step and not a keyword
     *
     * Postgres 13 and later accept `DROP DATABASE … WITH (FORCE)`, which does
     * roughly what the statement below does and then drops, all in one word. It
     * would have been a one-character diff and it is the wrong shape for this,
     * because what that word does is **disconnect a customer's users in the
     * middle of whatever they were doing**. That is the correct behaviour here —
     * §4.1 refuses to make `suspended` a prerequisite precisely so that a live
     * tenant can be removed, and a live tenant is by definition one with sessions
     * open — but it is a decision, and a decision that arrives as a keyword on
     * the end of an unrelated statement is one nobody reads. So the disconnection
     * is written out, named after what it does, and this paragraph is the record
     * that it was chosen rather than inherited.
     *
     * The drop that follows still carries `WITH (FORCE)`, in the belt-and-braces
     * arrangement `bin/ci`'s test-database reclaim already uses (§9.2): this
     * statement is the belt, and handles every session that exists right now;
     * the keyword is the braces, and handles the client that reconnects in the
     * microseconds between the two statements. Neither makes the other
     * redundant, and only one of them is the reason the drop succeeds.
     *
     * ## It cannot terminate itself
     *
     * Two guards, both needed for different reasons. `pid <> pg_backend_pid()`
     * excludes this very connection, which is the guard that would matter if the
     * provisioning DSN ever pointed at a tenant's own database — a
     * misconfiguration, but one whose punishment should not be the command
     * killing itself half way through a drop. `datname = ?` is the one that
     * matters in practice: the admin connection is opened against the maintenance
     * database (`postgres` in every DSN this project ships), so it is not in the
     * result set at all, and neither is the control-plane connection, which lives
     * in a third database again. What *is* in the result set is anything holding
     * the tenant open — including, if somebody arranged it, this process's own
     * tenant connection, which is why {@see deprovision()} clears the switcher
     * before reaching here rather than relying on being terminated by its own
     * command.
     *
     * The one caller that deliberately opens a tenant connection just before a
     * deprovision is `RecordCounter`, which counts what is about to be destroyed
     * for the confirmation. Its docblock worries about being the session that
     * blocks the drop; it closes on the way out and so it is not, and if it ever
     * failed to, this statement would now close it for it. The two agree.
     *
     * ## And it may not be allowed to
     *
     * Terminating another role's backend is not something `CREATE DATABASE` and
     * `CREATE ROLE` rights imply. Postgres allows it to a superuser, to a member
     * of `pg_signal_backend`, or to a role that **inherits** the privileges of
     * the connected role — and on this project's Postgres 18 a plain
     * `CREATEDB CREATEROLE` role was measured failing with
     * `42501 permission denied to terminate process` against a tenant role it had
     * created itself, because a `CREATEROLE` grant carries `ADMIN` without
     * `INHERIT` since Postgres 16. Development and test run as the cluster
     * superuser and never meet it; a production deployment with a narrowed
     * provisioning role can, so the privilege error is caught and turned into the
     * grant that fixes it rather than surfacing as a driver exception. §4.1
     * records the experiment.
     */
    private function disconnectEverySessionOn(Connection $admin, string $slug, string $database, string $role): void
    {
        $sql = 'SELECT pg_terminate_backend(pid) FROM pg_stat_activity '
            . 'WHERE datname = ? AND pid <> pg_backend_pid()';

        try {
            $admin->fetchFirstColumn($sql, [$database]);
        } catch (DriverException $e) {
            if ($e->getSQLState() !== self::INSUFFICIENT_PRIVILEGE) {
                throw TenantRemovalFailed::databaseSurvived($slug, $database, $role, $e);
            }

            // Counted only now, and on purpose: Postgres aborts the statement
            // above at the first backend it may not signal, so the number of
            // sessions is not something the failure itself can tell us. The
            // connection survives the error — it was never in a transaction — so
            // asking is safe, and the count is what makes the message an
            // operator can act on rather than a permission complaint.
            throw TenantRemovalFailed::mayNotDisconnectSessions(
                $slug,
                $database,
                $role,
                $this->sessionsOn($admin, $database),
                $this->dsnParser->userName($this->adminDsn) ?? 'the provisioning role',
                $e,
            );
        }
    }

    /** How many sessions other than ours are attached to $database right now. */
    private function sessionsOn(Connection $admin, string $database): int
    {
        return (int) $admin->fetchOne(
            'SELECT count(*) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
            [$database],
        );
    }

    private function dsnFor(string $objectName): string
    {
        return str_replace(['{database}', '{user}'], $objectName, $this->dsnTemplate);
    }

    /**
     * Creates the role and its database through the admin connection, since you
     * cannot create a database from inside it.
     */
    private function createRoleAndDatabase(Tenant $tenant, #[\SensitiveParameter] string $password): void
    {
        $database = $this->dsnParser->databaseName($tenant->getDatabaseDsn());
        $role = $this->dsnParser->userName($tenant->getDatabaseDsn())
            ?? throw ProvisioningFailed::dsnWithoutUser($tenant->getSlug());

        $admin = $this->adminConnection();

        try {
            if (\in_array($database, $admin->createSchemaManager()->listDatabases(), true)) {
                throw ProvisioningFailed::databaseExists($database);
            }

            $quotedRole = $this->quoteName($admin, $role);

            // DDL takes no bound parameters, so the password is quoted as a literal.
            $admin->executeStatement(sprintf(
                'CREATE ROLE %s LOGIN PASSWORD %s',
                $quotedRole,
                $admin->quote($password),
            ));

            // Owned by the tenant role so its migrations can create tables.
            $admin->executeStatement(sprintf(
                'CREATE DATABASE %s OWNER %s',
                $this->quoteName($admin, $database),
                $quotedRole,
            ));

            // The part that makes a wrong DSN fail closed: without this, any
            // role on the server could connect to any tenant's database.
            // ALL rather than CONNECT, so PUBLIC keeps no TEMP right either.
            $admin->executeStatement(sprintf(
                'REVOKE ALL ON DATABASE %s FROM PUBLIC',
                $this->quoteName($admin, $database),
            ));
            $admin->executeStatement(sprintf(
                'GRANT CONNECT ON DATABASE %s TO %s',
                $this->quoteName($admin, $database),
                $quotedRole,
            ));
        } finally {
            $admin->close();
        }
    }

    private function adminConnection(): Connection
    {
        $params = $this->dsnParser->parse($this->adminDsn);
        unset($params['url']);

        return DriverManager::getConnection($params);
    }

    private function quoteName(Connection $connection, string $identifier): string
    {
        return $connection->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }
}

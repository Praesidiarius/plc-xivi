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

namespace App\ControlPlane\Provisioning;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Entity\TenantStatus;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenancy\Migration\TenantMigrator;
use App\Tenancy\Security\PasswordGenerator;
use App\Tenancy\Security\TenantSecretCipher;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantSwitcher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates a customer: control-plane row, database role, database, schema.
 *
 * This is the "provisioning is a console command, not a filesystem ritual" half
 * of docs/architecture.md §4 — nothing here writes configuration to disk, so there is no
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
    /** Also the tenant's database and role name, so: valid unquoted Postgres identifier. */
    private const string SLUG_PATTERN = '/^[a-z][a-z0-9_]{1,55}$/';

    private const string OBJECT_PREFIX = 'tenant_';

    public function __construct(
        private EntityManagerInterface $controlPlane,
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
        private TenantMigrator $migrator,
        private TenantDsnParser $dsnParser,
        private TenantSecretCipher $cipher,
        /** DSN with `{database}` and `{user}` placeholders, used when no explicit DSN is given. */
        #[Autowire('%env(TENANT_DSN_TEMPLATE)%')]
        private string $dsnTemplate,
        /** Credentials allowed to CREATE DATABASE and CREATE ROLE; provisioning only. */
        #[Autowire('%env(TENANT_ADMIN_DSN)%')]
        private string $adminDsn,
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
        }

        $objectName = self::OBJECT_PREFIX . $slug;
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
     * because every deploy has to run it for every tenant (docs/architecture.md §4).
     *
     * @return list<string> executed migration versions
     */
    public function migrate(Tenant $tenant): array
    {
        return $this->switcher->runFor($tenant, fn () => $this->migrator->migrateToLatest());
    }

    /**
     * Removes a tenant completely: row, database, role.
     *
     * Destructive and irreversible — docs/architecture.md §4 makes export-on-churn a
     * per-customer operation, so take the dump first.
     */
    public function deprovision(Tenant $tenant): void
    {
        $slug = $tenant->getSlug();

        // Our own open connection to that database would block the drop.
        $this->switcher->clear();

        $this->controlPlane->remove($tenant);
        $this->controlPlane->flush();

        $admin = $this->adminConnection();

        try {
            $database = $this->dsnParser->databaseName($tenant->getDatabaseDsn());
            $role = $this->dsnParser->userName($tenant->getDatabaseDsn()) ?? self::OBJECT_PREFIX . $slug;

            $admin->executeStatement(sprintf('DROP DATABASE IF EXISTS %s', $this->quoteName($admin, $database)));
            $admin->executeStatement(sprintf('DROP ROLE IF EXISTS %s', $this->quoteName($admin, $role)));
        } finally {
            $admin->close();
        }
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

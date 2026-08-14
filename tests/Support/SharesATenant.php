<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use Doctrine\DBAL\Connection;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * One tenant per test class instead of one per test.
 *
 * Provisioning is not cheap: creating a database, running the tenant migrations
 * and dropping it again costs a second or three, and doing it around every test
 * method was most of the suite's running time. Records are emptied between
 * tests instead, which is a truncate and takes milliseconds.
 *
 * The obvious alternative — wrapping each test in a transaction and rolling it
 * back — does not work here. `CREATE DATABASE` cannot run inside one, so the
 * expensive part could never be undone; and a bundle that keeps a single static
 * connection would fight TenantDriver, which resolves the tenant's DSN at
 * connect time on purpose. Isolation in this application is a database, not a
 * transaction.
 *
 * **What this does not isolate is definitions.** Records are cleared; a field
 * added or removed by a test stays for the rest of the class. A class that
 * edits metadata should provision per test and not use this.
 */
trait SharesATenant
{
    /** @var array<string, Tenant> slug => tenant, for the current class only */
    private static array $sharedTenants = [];

    /**
     * The class's tenant, provisioned the first time it is asked for.
     *
     * @param list<string> $hostnames
     */
    protected function sharedTenant(string $slug, array $hostnames): Tenant
    {
        if (isset(self::$sharedTenants[$slug])) {
            return self::$sharedTenants[$slug];
        }

        $provisioner = self::sharedService(TenantProvisioner::class);
        $existing = self::sharedService(TenantRepository::class)->findOneBySlug($slug);

        // A previous run that died before its teardown leaves one behind.
        if ($existing instanceof Tenant) {
            $provisioner->deprovision($existing);
        }

        return self::$sharedTenants[$slug] = $provisioner->provision($slug, $slug, $hostnames);
    }

    /**
     * Empty every installed module's records, leaving the definitions alone.
     *
     * RESTART IDENTITY so ids begin at 1 in each test, exactly as they did when
     * every test had its own database — tests that assert on an id keep working.
     */
    protected function clearRecords(Tenant $tenant): void
    {
        self::sharedService(TenantSwitcher::class)->runFor($tenant, static function (): void {
            $tables = [];

            foreach (self::sharedService(MetadataRepository::class)->all() as $module) {
                \assert($module instanceof ModuleDefinition);

                $tables[] = $module->getTableName();
                $tables[] = $module->getHistoryTableName();

                foreach ($module->getCollections() as $collection) {
                    $tables[] = $collection->getTableName();
                }
            }

            if ($tables === []) {
                return;
            }

            // The tenant's connection, not the control plane's: the records
            // being cleared live in the customer's own database.
            $connection = static::getContainer()->get('doctrine.dbal.tenant_connection');
            \assert($connection instanceof Connection);
            $connection->executeStatement(sprintf(
                'TRUNCATE %s RESTART IDENTITY CASCADE',
                implode(', ', array_map($connection->quoteSingleIdentifier(...), $tables)),
            ));
        });
    }

    public static function tearDownAfterClass(): void
    {
        $slugs = array_keys(self::$sharedTenants);
        self::$sharedTenants = [];

        if ($slugs !== []) {
            // Booted fresh, so the entities collected above belong to an entity
            // manager that is gone — they are looked up again here rather than
            // handed to a manager that has never seen them.
            static::bootKernel();

            $tenants = self::sharedService(TenantRepository::class);
            $provisioner = self::sharedService(TenantProvisioner::class);

            foreach ($slugs as $slug) {
                $tenant = $tenants->findOneBySlug($slug);

                if ($tenant instanceof Tenant) {
                    $provisioner->deprovision($tenant);
                }
            }
        }

        parent::tearDownAfterClass();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function sharedService(string $id): object
    {
        $service = static::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}

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

namespace App\Tests\Support;

use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;

/**
 * One tenant per test class, kept clean by rolling each test back.
 *
 * Provisioning is not cheap — creating a database, creating a role and running
 * the tenant migrations costs a second or three — and doing it around every test
 * method was most of the suite's running time. So the database is made once for
 * the class, and isolation between tests comes from DAMA's transaction instead:
 * everything a test writes is rolled back before the next one starts. Records,
 * definitions, users, history — a test cannot poison another test with any of
 * them, which a truncate could not promise for the definitions.
 *
 * The database itself is created *outside* that transaction, because
 * `CREATE DATABASE` cannot run inside one. Hence the switch below: DAMA is
 * turned off for the duration of provisioning and turned back on afterwards.
 *
 * **The tenant is not dropped when the class finishes.** DAMA keeps its
 * connection open until the process ends, and Postgres will not drop a database
 * somebody is connected to. So every run ends with its databases still there,
 * and the reclaim happens on the way *in* rather than on the way out — twice
 * over, at two different scopes:
 *
 *   * `bin/ci` drops every test database matching this checkout's prefix before
 *     the suite starts, killing any session that holds one (XIV-78). That is the
 *     one that bounds the disk, because what is left over is namespaced per
 *     paratest worker and a class does not land on the same worker twice — so
 *     reclaiming only by slug let the set grow to classes × workers and fill the
 *     test tmpfs. See the comment in `bin/ci`.
 *   * The `deprovision()` below still reclaims by slug, which is what keeps a
 *     plain `composer test` — and a run that died halfway — self-healing without
 *     going through `bin/ci`. It costs nothing to keep: this trait re-provisions
 *     whatever it finds either way, so nothing was ever being reused.
 *
 * The two overlap on purpose and cannot disagree. After `bin/ci`'s reclaim the
 * registry row may outlive its database — the control plane is a separate
 * database and is not dropped with them — and `deprovision()` is `DROP … IF
 * EXISTS` on both the database and the role, so that case is a no-op rather than
 * an error.
 *
 * A test that needs to drop a tenant of its own — the cross-tenant ones in
 * tests/Functional/Tenancy — should carry #[SkipDatabaseRollback] instead, which
 * makes DAMA leave its connections alone entirely.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
trait SharesATenant
{
    /** @var array<string, Tenant> slug => tenant, for the current class only */
    private static array $sharedTenants = [];

    /** @var array<string, true> every slug provisioned in this process, across classes */
    private static array $provisioned = [];

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

        // Real work on a real database: none of this can be rolled back, and the
        // migrations have to still be there when the transaction ends.
        return self::$sharedTenants[$slug] = self::withoutRollback(static function () use ($slug, $hostnames): Tenant {
            $tenants = self::sharedService(TenantRepository::class);
            $provisioner = self::sharedService(TenantProvisioner::class);
            $existing = $tenants->findOneBySlug($slug);

            // Two classes asking for the same slug. Reuse it rather than make it
            // again: DAMA still holds a connection to that database, so dropping
            // it would fail — and every test in both classes is rolled back
            // anyway, so there is nothing in it to make again.
            if (isset(self::$provisioned[$slug])) {
                \assert($existing instanceof Tenant);

                return $existing;
            }

            // Left behind by the previous run, or by one that died halfway.
            if ($existing instanceof Tenant) {
                $provisioner->deprovision($existing);
            }

            self::$provisioned[$slug] = true;

            return $provisioner->provision($slug, $slug, $hostnames);
        });
    }

    /**
     * Runs $work with DAMA's static connections disabled, so that what it writes
     * is committed rather than rolled back with the test.
     *
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    protected static function withoutRollback(callable $work): mixed
    {
        $keeping = StaticDriver::isKeepStaticConnections();
        StaticDriver::setKeepStaticConnections(false);

        try {
            return $work();
        } finally {
            StaticDriver::setKeepStaticConnections($keeping);
        }
    }

    public static function tearDownAfterClass(): void
    {
        // The databases stay; only this class's handle on them goes, so that the
        // next class does not reuse an entity belonging to a dead kernel.
        self::$sharedTenants = [];

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

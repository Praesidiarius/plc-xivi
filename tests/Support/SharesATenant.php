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
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tests\Support\Dbal\TenantConnectionKeyDriver;
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
 * connection open until the process ends, and a drop with somebody attached is
 * not a thing to reach for: before [XIV-94] Postgres refused it outright, and
 * since [XIV-94] `deprovision()` *terminates* what it finds attached — which is
 * worse here, not better, because the connection it would kill is DAMA's and the
 * classes still to run in this process are the ones holding it. So every run ends
 * with its databases still there, and the reclaim happens on the way *in* rather
 * than on the way out — twice over, at two different scopes:
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
 * ## Asking for a tenant of your own
 *
 * **The slug is the request.** A class that passes a slug nobody else passes
 * gets a database nobody else touches, and that is every class in the suite
 * today. Nothing is shared between classes and nothing is pooled, so there is no
 * opt-out to remember and no annotation to forget: two classes share a tenant
 * only by both naming the same slug, which the browser classes do on purpose and
 * everybody else avoids by convention. {@see ClassTenantIsolationFirstTest}
 * and its second half are what turn that convention into a red test.
 *
 * ## Why this is still per class and not per suite (XIV-171, XIV-150)
 *
 * Both tickets proposed making this cheaper, and the measurement of 2026-08-21
 * says there is nothing here worth making cheaper. Serially and without
 * coverage, which is the shape CI runs in, the whole suite is 620 s. Of that:
 *
 *   * **provisioning is 22.9 s**: 226 tenants at a mean of 101 ms, of which 19 ms
 *     is the database and the role and the rest is the two migrations XIV-151's
 *     squash left;
 *   * **module installation is 50.6 s**: 2,020 calls at 25 ms, and it runs per
 *     *test* rather than per class, because DDL inside a transaction rolls back
 *     with everything else it wrote.
 *
 * So:
 *
 *   * **Cloning a template database** (XIV-150) has a ceiling of 23 s, or under
 *     4%, and cannot reach all of it: the clone still copies the schema's files
 *     and the role is a cluster object a clone does not bring. Against that it
 *     buys a template that lies whenever a migration is added, a clone that fails
 *     whenever anything is connected, and the cluster-wide role collision XIV-120
 *     already lost a day to. Refused on the numbers, not on the idea.
 *   * **Sharing one tenant across the classes that only read** buys the same 23 s
 *     and pays for it in the one property this suite exists to prove. The pair of
 *     tests above fails the moment it is tried.
 *   * **Installing modules once per class, outside the rollback**, buys 51 s and
 *     is the same trade one level down. A class whose tests edit definitions,
 *     the metadata editor ones, the type conversions, the module upgrade, would
 *     leak them into their siblings, and the failure lands on whichever test runs
 *     next rather than on the one that caused it. That is XIV-61's registry
 *     incident with the nouns changed.
 *
 * What actually costs the suite its time is none of this. 85% of the serial run
 * is tests/Functional/Engine, and 78% of *that* is what the tests do after
 * `setUp()` returns: 5,685 requests in the version of that directory this was
 * measured on, of which the record form answered 1,718 renders and 846 saves at
 * around 100 ms each. Those are round trips a browser also makes, since a live
 * component re-renders when its model changes, so they are not waste. XIV-171 has
 * the two ways of collapsing them that were measured and refused, and what each
 * one quietly broke.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
trait SharesATenant
{
    /**
     * slug => tenant, for the current class only. "For the current class" is
     * not a choice this trait gets to make: a static property on a trait is
     * copied into every class that uses it, so each class has its own array.
     * Here that accident is the wanted behaviour, because the entities are
     * bound to the class's own kernel and `tearDownAfterClass()` clears them.
     * The process-wide half of the bookkeeping is {@see ProvisionedSlugs},
     * which is a class for exactly the opposite reason; see its docblock for
     * the bug the difference caused (XIV-148).
     *
     * @var array<string, Tenant>
     */
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

        // Real work on a real database: none of this can be rolled back, and the
        // migrations have to still be there when the transaction ends.
        return self::$sharedTenants[$slug] = self::withoutRollback(static function () use ($slug, $hostnames): Tenant {
            $tenants = self::sharedService(TenantRepository::class);
            $provisioner = self::sharedService(TenantProvisioner::class);
            $existing = $tenants->findOneBySlug($slug);

            // Two classes asking for the same slug. Reuse it rather than make
            // it again: DAMA still holds a connection to that database, and a
            // deprovision would now terminate it out from under the class that is
            // still using it ([XIV-94]) rather than failing where somebody would
            // see it. Every test in both classes is rolled back anyway, so there
            // is nothing in there to make again. This question has to be asked
            // process-wide, which is why it is asked of a class and not of a
            // static on this trait: the six browser classes share one slug,
            // relied on this guard, and got a fresh copy of an empty array each
            // instead (XIV-148). SharedSlugReuseSecondTest is the proof it now
            // holds.
            if (ProvisionedSlugs::has($slug)) {
                \assert($existing instanceof Tenant);

                return $existing;
            }

            // Left behind by the previous run, or by one that died halfway.
            if ($existing instanceof Tenant) {
                self::refuseToTerminateASharedConnection($slug, $existing);
                $provisioner->deprovision($existing);
            }

            ProvisionedSlugs::add($slug);

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

    /**
     * The backstop under the reuse guard above: a deprovision that would reach
     * a DAMA-cached connection is refused before it runs, not survived after.
     *
     * The reuse guard makes this unreachable for slugs that went through
     * `sharedTenant()`, so what is left for it to catch is the path nobody has
     * written yet: a leftover registry row whose database something in this
     * process has already touched on the ordinary static path, a command that
     * walks every tenant say, before any class claimed the slug here. Without
     * this check that deprovision would terminate the cached connection
     * ([XIV-94], deployment brief §4.1), and the failure would not land on the
     * test that caused it: DAMA rolls back and reopens its transactions across
     * *every* cached connection around *every* test, so one dead connection in
     * the cache surfaces as a "terminating connection due to administrator
     * command" runner warning, or a cascade of errors, in tests that did
     * nothing wrong. That is exactly the shape XIV-148 was reported in.
     * Failing here instead puts the offender's own name on the failure.
     */
    private static function refuseToTerminateASharedConnection(string $slug, Tenant $existing): void
    {
        $database = self::sharedService(TenantDsnParser::class)->databaseName($existing->getDatabaseDsn());

        if (!TenantConnectionKeyDriver::holdsStaticConnectionTo($database)) {
            return;
        }

        throw new \LogicException(sprintf(
            'Refusing to deprovision the leftover tenant "%s": DAMA holds a static connection to '
            . 'its database "%s", so deprovisioning would terminate that connection and every '
            . 'test after this one would fail with "terminating connection due to administrator '
            . 'command" (XIV-148). Something in this process connected to that database before '
            . 'this class claimed the slug, most likely a test that walks every tenant in the '
            . 'registry. Either scope that walk, or claim the slug through sharedTenant() first.',
            $slug,
            $database,
        ));
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

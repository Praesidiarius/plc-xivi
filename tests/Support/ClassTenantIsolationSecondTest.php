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
use App\Tenancy\TenantSwitcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * The other half of {@see ClassTenantIsolationFirstTest} (XIV-171).
 *
 * One class committed a contact into its own tenant. This one asks for a tenant
 * of its own and must not be able to see it. Two assertions, because a change
 * that shared tenants between classes would break them in different places:
 *
 *  * **a database of its own**, named by this class's slug and not by the other
 *    one's, which is what fails first if `SharesATenant` ever hands two classes
 *    the same tenant;
 *  * **and nothing of the other class's in it**, which is what fails if the
 *    sharing is arranged some other way: one database behind two slugs, say,
 *    or a reset between classes that misses a table.
 *
 * **The second assertion is corroborated from inside the other tenant rather
 * than trusted.** The same reader, the same query, run against the tenant next
 * door, finds the contact; run against this class's, finds nothing. A check
 * that only said "nothing here" would pass just as happily if the contact had
 * never been written, if the module were missing, or if the query were wrong,
 * which is the shape of test this project refuses (see the privilege tests).
 *
 * The corroboration only speaks when the other class ran in this process, which
 * {@see ProvisionedSlugs} is the only honest way to ask. Under paratest the two
 * land on different workers and this half is skipped; the database half still
 * runs, and the serial run, which is the one CI makes because the coverage leg
 * cannot be parallel (XIV-170), exercises both.
 *
 * @author Nathanael Kammermann <nathanael.kammermann@gmail.com>
 */
final class ClassTenantIsolationSecondTest extends KernelTestCase
{
    use SharesATenant;

    public const string SLUG = 'test_class_isolation_second';
    public const string HOST = 'class-isolation-second.localhost';

    public function testItGetsADatabaseOfItsOwn(): void
    {
        self::bootKernel();

        $database = $this->databaseOf($this->sharedTenant(self::SLUG, [self::HOST]));

        self::assertSame($this->objectPrefix() . self::SLUG, $database);

        if (ClassTenantIsolationFirstTest::$database !== null) {
            self::assertNotSame(
                ClassTenantIsolationFirstTest::$database,
                $database,
                'two classes asking for two slugs are two customers',
            );
        }
    }

    public function testTheOtherClassesContactIsNotInThisTenant(): void
    {
        self::bootKernel();

        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        // This tenant's own contact module, so that "no contacts" is an empty
        // table rather than a missing one.
        self::service(TenantSwitcher::class)->runFor($tenant, static function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            );
        });

        self::assertSame([], ClassTenantIsolationFirstTest::firstNamesIn($tenant), 'nothing another class wrote is readable here');

        // Only when the other class really ran here. `ProvisionedSlugs` is the
        // process asked directly, which a registry row is not: a row can outlive
        // the database it names (a `bin/compose restart database-test` empties
        // the tenant server and leaves the control plane standing), and reading
        // through one of those would fail on a connection rather than on the
        // thing this test is about.
        if (!ProvisionedSlugs::has(ClassTenantIsolationFirstTest::SLUG)) {
            return;
        }

        $next = self::service(TenantRepository::class)->findOneBySlug(ClassTenantIsolationFirstTest::SLUG);
        self::assertInstanceOf(Tenant::class, $next, 'the slug this process provisioned is in the registry');

        self::assertSame(
            [ClassTenantIsolationFirstTest::FIRST_NAME],
            ClassTenantIsolationFirstTest::firstNamesIn($next),
            'and the same reader finds it one tenant along, so the empty answer above is about separation',
        );
    }

    private function databaseOf(Tenant $tenant): string
    {
        return (string) self::service(TenantSwitcher::class)->runFor($tenant, static function (): string {
            $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
            \assert($connection instanceof Connection);

            return (string) $connection->fetchOne('SELECT current_database()');
        });
    }

    /** Read rather than written down; see TenantIsolationTest for why. */
    private function objectPrefix(): string
    {
        $prefix = self::getContainer()->getParameter('app.tenant_object_prefix');
        \assert(\is_string($prefix));

        return $prefix;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}

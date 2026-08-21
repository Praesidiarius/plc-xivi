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
use App\Tenancy\TenantSwitcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * The bait for {@see ClassTenantIsolationSecondTest} (XIV-171).
 *
 * `RollbackIsolationTest` proves that one test cannot see what the *previous
 * test in its own class* wrote. Nothing proved the other axis: that one class
 * cannot see what another class wrote. It held by construction, a slug naming a
 * database, and "by construction" is exactly the sentence that stops being
 * true when somebody makes the classes share a tenant to save the provisioning
 * time. That is the first direction XIV-171 lists and the one it warns hardest
 * about, so it is worth a red test rather than an argument.
 *
 * **The record is committed rather than written**, inside `withoutRollback()`,
 * and that is the whole design. A row the rollback takes away is invisible to
 * the next class whether the tenants are shared or not, so looking for one
 * would pass either way. This row is still in this database when the second
 * class runs, which makes not finding it a fact about separation.
 *
 * It outlives the run, and that is fine: the slug is this pair's own, nothing
 * else reads it, `bin/ci` drops the database on the way into the next run
 * (XIV-78) and `SharesATenant` deprovisions whatever it finds under a slug it
 * has not claimed in this process.
 *
 * @author Nathanael Kammermann <nathanael.kammermann@gmail.com>
 */
final class ClassTenantIsolationFirstTest extends KernelTestCase
{
    use SharesATenant;

    public const string SLUG = 'test_class_isolation_first';
    public const string HOST = 'class-isolation-first.localhost';

    /** What the committed contact is called, so the second class knows what it is not allowed to find. */
    public const string FIRST_NAME = 'Ada';

    /**
     * This class's database, for the second class to compare its own against.
     * Null when the two ran in different processes, which is paratest and is
     * the second class's signal that there is nothing to compare.
     */
    public static ?string $database = null;

    public function testItCommitsAContactNoOtherClassMaySee(): void
    {
        self::bootKernel();

        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::$database = $this->databaseOf($tenant);

        // The trait handed this class the database its own slug names, which is
        // the property the second class re-asks from the other side.
        self::assertSame($this->objectPrefix() . self::SLUG, self::$database);

        self::withoutRollback(function () use ($tenant): void {
            self::service(TenantSwitcher::class)->runFor($tenant, function (): void {
                self::service(ModuleInstaller::class)->install(
                    self::service(ModuleRegistry::class)->get(ContactModule::KEY),
                );

                self::service(RecordWriter::class)->save(
                    self::service(MetadataRepository::class)->get(ContactModule::KEY),
                    new Record([
                        'kind' => ContactModule::PERSON,
                        'first_name' => self::FIRST_NAME,
                        'last_name' => 'Lovelace',
                    ]),
                );
            });
        });

        self::assertSame(
            [self::FIRST_NAME],
            self::firstNamesIn($tenant),
            'the contact is committed and readable, which is what makes the second class not finding it mean anything',
        );
    }

    /**
     * Every contact in a tenant, by first name.
     *
     * Shared with the second class, and one reader for both sides is the point:
     * "not here" and "there" are comparable only because nothing about the
     * question changed between them.
     *
     * @return list<string>
     */
    public static function firstNamesIn(Tenant $tenant): array
    {
        /** @var list<string> $names */
        $names = self::service(TenantSwitcher::class)->runFor($tenant, static fn (): array => array_map(
            static fn (Record $record): string => (string) $record->get('first_name'),
            self::service(RecordRepository::class)->findBy(
                self::service(MetadataRepository::class)->get(ContactModule::KEY),
                new RecordQuery(),
                RecordAccess::unrestricted(),
            ),
        ));

        return $names;
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

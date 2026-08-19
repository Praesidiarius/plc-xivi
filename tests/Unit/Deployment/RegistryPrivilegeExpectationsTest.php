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

namespace App\Tests\Unit\Deployment;

use App\Deployment\RegistryGrants;
use App\Deployment\RegistryPrivilegeReport;
use App\Deployment\RegistryPrivileges;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\TestCase;

/**
 * **That adding an entity to `App\Registry\Entity\` cannot make the check and
 * the grant disagree** (XIV-143, docs/architecture.md §4.4).
 *
 * ## Why this needs an entity that does not exist
 *
 * {@see \App\Tests\Functional\Deployment\CheckRegistryGrantsTest} proves against
 * a real cluster that what `deploy:check-grants` reports as missing is exactly
 * what {@see RegistryGrants::readableTables()} names. That is the strong half,
 * and it has one blind spot: both sides of it are today's seven tables, so a
 * checker that had quietly acquired a hardcoded list of today's seven tables
 * would pass it — and would then keep passing on the day somebody adds the
 * eighth, which is the exact failure this whole ticket exists to close.
 *
 * So this invents `App\Registry\Entity\Imaginary`, puts it in the mapping the
 * grant generator reads, and asks the checker what it wants to know about. The
 * table has to appear in the query it sends and in the finding it produces, with
 * nothing in `App\Deployment` changed to accommodate it. A literal list anywhere
 * between the mapping and the report fails this and passes everything else.
 *
 * The administration surface gets an invented table too, because §4.4 withholds
 * those on *every* privilege and that list is derived by the same walk —
 * {@see RegistryGrants::withheldTables()} — from the same metadata. An entity
 * filed in the wrong namespace is [XIV-123]'s near miss, and it moves a table
 * from one of these lists to the other.
 *
 * ## The cluster is a fake, and only its answers are
 *
 * The connection is a mock that returns what PostgreSQL would, so what is under
 * test here is the derivation and the comparison rather than the SQL. The SQL is
 * exercised for real, against a real role, in the functional class next door;
 * this one runs in a millisecond and can invent a schema, which that one cannot.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RegistryPrivilegeExpectationsTest extends TestCase
{
    /** The table of an entity that does not exist, and that nothing in `src/` has heard of. */
    private const string INVENTED_REGISTRY_TABLE = 'imaginary_registry_table';

    /** And one belonging to the administration surface, which §4.4 withholds entirely. */
    private const string INVENTED_ADMINISTRATION_TABLE = 'imaginary_administration_table';

    /** @var list<string> what the checker asked the database about, captured from the query's parameters */
    private array $asked = [];

    /**
     * **The ticket's second acceptance criterion.** A registry entity this build
     * has never seen is checked for, and reported when its `SELECT` is not there.
     */
    public function testATableAddedToTheRegistryMappingIsCheckedForSelect(): void
    {
        // Everything today's mapping asks for is granted; the new table is the
        // one the deployment has not run `deploy:registry-grants` for yet.
        $report = $this->audit([
            'tenant' => ['SELECT'],
            'doctrine_migration_versions' => ['SELECT'],
            self::INVENTED_REGISTRY_TABLE => [],
            self::INVENTED_ADMINISTRATION_TABLE => [],
        ]);

        self::assertContains(self::INVENTED_REGISTRY_TABLE, $this->asked);
        self::assertSame([self::INVENTED_REGISTRY_TABLE => ['SELECT']], $report->missing);
        self::assertFalse($report->isSatisfied());
    }

    /**
     * And the same table, granted, is not a finding — without which the test
     * above would also pass for a checker that reported every table it was told
     * about.
     */
    public function testTheSameTableGrantedIsNotAFinding(): void
    {
        $report = $this->audit([
            'tenant' => ['SELECT'],
            'doctrine_migration_versions' => ['SELECT'],
            self::INVENTED_REGISTRY_TABLE => ['SELECT'],
            self::INVENTED_ADMINISTRATION_TABLE => [],
        ]);

        self::assertTrue($report->isSatisfied());
        self::assertSame([], $report->missing);
        self::assertSame([], $report->excess);
    }

    /**
     * A privilege beyond `SELECT` on the invented registry table is excess, which
     * is the direction [XIV-120] and [XIV-123] each asserted for two tables of
     * their own and this asserts for whatever the mapping grows next.
     */
    public function testAWriteOnANewRegistryTableIsExcess(): void
    {
        $report = $this->audit([
            'tenant' => ['SELECT'],
            'doctrine_migration_versions' => ['SELECT'],
            self::INVENTED_REGISTRY_TABLE => ['SELECT', 'UPDATE'],
            self::INVENTED_ADMINISTRATION_TABLE => [],
        ]);

        self::assertSame([self::INVENTED_REGISTRY_TABLE => ['UPDATE']], $report->excess);
    }

    /**
     * And an entity in the administration surface's namespace is withheld on
     * every privilege, `SELECT` included — the other half of the same walk over
     * the same metadata.
     */
    public function testReadingANewAdministrationTableIsExcess(): void
    {
        $report = $this->audit([
            'tenant' => ['SELECT'],
            'doctrine_migration_versions' => ['SELECT'],
            self::INVENTED_REGISTRY_TABLE => ['SELECT'],
            self::INVENTED_ADMINISTRATION_TABLE => ['SELECT'],
        ]);

        self::assertContains(self::INVENTED_ADMINISTRATION_TABLE, $this->asked);
        self::assertSame([self::INVENTED_ADMINISTRATION_TABLE => ['SELECT']], $report->excess);
    }

    /**
     * A mapped registry table the database does not have is its own finding.
     *
     * Not a permission problem and not silence either: the customer-facing
     * instance is about to meet `relation "…" does not exist`, which is the same
     * outage arriving through a different error code.
     */
    public function testATableTheDatabaseDoesNotHaveIsAFinding(): void
    {
        $report = $this->audit([
            'tenant' => ['SELECT'],
            'doctrine_migration_versions' => ['SELECT'],
            self::INVENTED_ADMINISTRATION_TABLE => [],
        ]);

        self::assertSame([self::INVENTED_REGISTRY_TABLE], $report->absent);
        self::assertFalse($report->isSatisfied());
    }

    /**
     * The audit of a cluster in which `$granted` is the whole truth.
     *
     * @param array<string, list<string>> $granted table => the privileges the role holds on it. A
     *                                             table absent from this map is absent from the
     *                                             database
     */
    private function audit(array $granted): RegistryPrivilegeReport
    {
        $privileges = new RegistryPrivileges(
            new RegistryGrants($this->mapping(), $this->cluster($granted)),
            $this->cluster($granted),
        );

        return $privileges->audit('somebody');
    }

    /**
     * An entity manager whose mapping is two real-looking tables and two invented
     * ones, in the two namespaces §4.4 tells apart.
     */
    private function mapping(): EntityManagerInterface
    {
        $metadata = [];

        foreach ([
            'App\Registry\Entity\Tenant' => 'tenant',
            'App\Registry\Entity\Imaginary' => self::INVENTED_REGISTRY_TABLE,
            'Xivi\ControlPlane\Entity\Imaginary' => self::INVENTED_ADMINISTRATION_TABLE,
        ] as $class => $table) {
            // Two of the three classes deliberately do not exist — that is the
            // entity this build has not been written yet — so PHPStan cannot see
            // a `class-string` here and is told. `RegistryGrants` reads the name
            // and the table name and never reflects on either, which is what
            // makes an imaginary entity a usable fixture at all.
            /** @var class-string $class */
            $one = new ClassMetadata($class);
            $one->setPrimaryTable(['name' => $table]);

            $metadata[] = $one;
        }

        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('getAllMetadata')->willReturn($metadata);

        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getMetadataFactory')->willReturn($factory);

        return $manager;
    }

    /**
     * A PostgreSQL that holds `$granted` and answers the three questions
     * {@see RegistryPrivileges} asks it.
     *
     * @param array<string, list<string>> $granted
     */
    private function cluster(array $granted): Connection
    {
        $connection = $this->createStub(Connection::class);

        $connection->method('getDatabase')->willReturn('app');

        $connection->method('fetchAssociative')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'pg_roles')
                ? ['superuser' => 0]
                : ['database' => 'app', 'may_connect' => 1, 'may_use' => 1, 'may_create' => 0],
        );

        $connection->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params) use ($granted): array {
                \assert(\is_array($params['tables']));

                // What the checker wanted to know about, which is the assertion
                // that a table cannot be dropped from the query and still be
                // reported correctly by accident.
                $this->asked = array_values($params['tables']);

                $rows = [];

                foreach ($params['tables'] as $table) {
                    if (!\array_key_exists((string) $table, $granted)) {
                        // Not in the database: PostgreSQL returns no row for it,
                        // because the query joins pg_class rather than naming it.
                        continue;
                    }

                    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
                        $rows[] = [
                            'table_name' => $table,
                            'privilege' => $privilege,
                            'granted' => \in_array($privilege, $granted[(string) $table], true) ? 1 : 0,
                        ];
                    }
                }

                return $rows;
            },
        );

        return $connection;
    }
}

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

namespace App\Tests\Functional\Engine;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Demo\DemoDataGenerator;
use Xivi\Core\Demo\DemoLedger;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Validation\RecordValidator;

/**
 * Demo data generated from a module's own definitions.
 *
 * The claim under test is not "rows appeared". It is that the generator knows
 * nothing about contacts and still produces records the module itself would
 * accept — and that everything it made can be taken back out again.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DemoDataTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_demo';

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->sharedTenant(self::SLUG, ['demo.localhost']);

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));
    }

    public function testItGeneratesAsManyRecordsAsAsked(): void
    {
        self::assertSame(30, $this->generate(30));
        self::assertCount(30, $this->records());
    }

    /**
     * The claim the whole design rests on: nothing in the generator knows what a
     * contact is, and every record still passes the validation those same
     * definitions build.
     */
    public function testEveryGeneratedRecordPassesTheModulesOwnValidation(): void
    {
        $this->generate(40);

        $this->switcher->runFor($this->tenant, function (): void {
            $validator = self::service(RecordValidator::class);
            $module = self::module();

            foreach (self::service(RecordRepository::class)->findBy($module, new RecordQuery(perPage: 100), RecordAccess::unrestricted()) as $record) {
                $violations = $validator->validate($module, $record->data, $record->id);

                self::assertCount(0, $violations, sprintf(
                    'record %d: %s',
                    (int) $record->id,
                    implode(', ', array_map(static fn ($v): string => $v->getPropertyPath() . ' ' . $v->getMessage(), iterator_to_array($violations))),
                ));
            }
        });
    }

    /**
     * The variant field is a choice, so sampling it produces both kinds without
     * the generator having heard the words "person" or "company" (§5.5).
     */
    public function testBothVariantsAreGenerated(): void
    {
        $this->generate(40);

        $kinds = [];
        foreach ($this->records() as $record) {
            $kinds[(string) $record->data['kind']] = true;
        }

        self::assertArrayHasKey(ContactModule::PERSON, $kinds);
        self::assertArrayHasKey(ContactModule::COMPANY, $kinds);
    }

    /** A company has no first name and a person no company name, as ever (§5.5). */
    public function testAVariantsOwnFieldsAreTheOnlyOnesFilled(): void
    {
        $this->generate(40);

        foreach ($this->records() as $record) {
            if ($record->data['kind'] === ContactModule::COMPANY) {
                self::assertNull($record->data['first_name'], 'a company was given a first name');
                self::assertNotNull($record->data['company_name']);
            } else {
                self::assertNull($record->data['company_name'], 'a person was given a company name');
            }
        }
    }

    /**
     * The distribution is the point. Every record having exactly one address
     * would hide both the empty case and the crowded one.
     */
    public function testCollectionsGetASpreadNotAFixedNumber(): void
    {
        $this->generate(40);

        $counts = [];
        $this->switcher->runFor($this->tenant, function () use (&$counts): void {
            $collection = self::module()->getCollection('addresses');
            \assert($collection !== null);
            $records = self::service(RecordRepository::class);

            foreach ($records->findBy(self::module(), new RecordQuery(perPage: 100), RecordAccess::unrestricted()) as $record) {
                $counts[] = \count($records->findChildren($collection, (int) $record->id));
            }
        });

        self::assertContains(0, $counts, 'no record was left without an address');
        self::assertContains(1, $counts);
        self::assertTrue(max($counts) > 1, 'no record got more than one address');
    }

    /** Unique fields have to survive volume, not just a handful. */
    public function testAUniqueFieldDoesNotCollide(): void
    {
        $this->generate(120);

        $emails = [];
        foreach ($this->records(200) as $record) {
            $email = $record->data['email'];

            if ($email !== null) {
                $emails[] = $email;
            }
        }

        self::assertSame($emails, array_values(array_unique($emails)));
    }

    /** What makes "it broke on record 4,312" something somebody else can see. */
    public function testTheSameSeedProducesTheSameRecords(): void
    {
        $this->generate(10, seed: 99);
        $first = array_map(static fn (Record $r): mixed => $r->data['email'], $this->records());

        $this->purge();

        $this->generate(10, seed: 99);
        $second = array_map(static fn (Record $r): mixed => $r->data['email'], $this->records());

        self::assertSame($first, $second);
    }

    public function testADifferentSeedProducesDifferentRecords(): void
    {
        $this->generate(10, seed: 1);
        $first = array_map(static fn (Record $r): mixed => $r->data['email'], $this->records());

        $this->purge();

        $this->generate(10, seed: 2);

        self::assertNotSame($first, array_map(static fn (Record $r): mixed => $r->data['email'], $this->records()));
    }

    /** Generated records are records, so they have a history like any other (§5.2). */
    public function testGeneratedRecordsHaveTheirHistory(): void
    {
        $this->generate(5);

        $this->switcher->runFor($this->tenant, function (): void {
            $record = self::service(RecordRepository::class)->findBy(self::module(), new RecordQuery(), RecordAccess::unrestricted())[0];

            self::assertCount(1, self::service(HistoryRepository::class)->findFor(self::module(), (int) $record->id));
        });
    }

    /**
     * The reason the ledger exists. Cleanup deletes what a generator made and
     * nothing else — a record somebody typed into the same module survives it.
     */
    public function testClearingRemovesOnlyWhatWasGenerated(): void
    {
        $mine = $this->switcher->runFor($this->tenant, fn (): Record => self::service(RecordWriter::class)->save(
            self::module(),
            new Record(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace']),
        ));

        $this->generate(20);
        self::assertCount(21, $this->records(50));

        self::assertSame(20, $this->purge());

        $left = $this->records(50);
        self::assertCount(1, $left);
        self::assertSame($mine->id, $left[0]->id);
    }

    /** Their addresses and history go with them, rather than being orphaned. */
    public function testClearingTakesCollectionsAndHistoryWithIt(): void
    {
        $this->generate(20);
        $this->purge();

        $this->switcher->runFor($this->tenant, function (): void {
            $connection = self::tenantConnection();

            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM contact_address'));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM contact_history'));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM demo_record'));
        });
    }

    private function generate(int $amount, ?int $seed = null): int
    {
        return $this->switcher->runFor($this->tenant, fn (): int => new DemoDataGenerator(
            self::tenantConnection(),
            self::service(RecordWriter::class),
            self::service(FieldTypeRegistry::class),
            self::service(DemoLedger::class),
            // A small batch, so the paging is walked without writing hundreds of
            // records to prove it.
            batch: 7,
        )->generate(self::module(), $amount, $seed));
    }

    private function purge(): int
    {
        return $this->switcher->runFor(
            $this->tenant,
            fn (): int => self::service(DemoLedger::class)->purge(self::module()),
        );
    }

    /** @return list<Record> */
    private function records(int $perPage = 100): array
    {
        return $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)
            ->findBy(self::module(), new RecordQuery(perPage: $perPage), RecordAccess::unrestricted()));
    }

    private static function module(): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(ContactModule::KEY);
    }

    /**
     * The customer's database, not the control plane.
     *
     * `Connection::class` autowires the default connection, which is the control
     * plane — the same trap the importer fell into. Named explicitly here so the
     * test cannot quietly assert against the wrong database.
     */
    private static function tenantConnection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        \assert($connection instanceof Connection);

        return $connection;
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

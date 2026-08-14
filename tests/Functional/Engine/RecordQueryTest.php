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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Query\Direction;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Query\Sort;
use Xivi\Core\Query\UnsupportedQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * The query layer (§7.3), against real rows.
 *
 * Nothing here names a column or writes a predicate: every question is asked in
 * terms of the customer's own definitions, which is what the compiler exists to
 * make possible without concatenating SQL.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordQueryTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_query';

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);

        // One tenant for the class; each test is rolled back (see SharesATenant).
        $this->tenant = $this->sharedTenant(self::SLUG, ['query.localhost']);

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));
    }

    public function testFilteringOnAFieldOfTheRecord(): void
    {
        $this->contact('Ada', 'Lovelace');
        $this->contact('Grace', 'Hopper');

        $found = $this->matching(new RecordQuery([new Filter('last_name', Operator::Contains, 'hopp')]));

        self::assertSame(['Grace'], $this->firstNames($found));
    }

    /** Contains is case-insensitive, because a filter box is not a password field. */
    public function testFilteringIgnoresCase(): void
    {
        $this->contact('Ada', 'Lovelace');

        self::assertCount(1, $this->matching(new RecordQuery([new Filter('last_name', Operator::Contains, 'LOVE')])));
    }

    /**
     * The wildcards LIKE understands are escaped, or a filter for a literal "%"
     * would quietly match every record and look like a working search.
     */
    public function testWildcardsInAFilterAreLiteral(): void
    {
        $this->contact('Ada', 'Lovelace');
        $this->contact('Grace', '100%');

        $found = $this->matching(new RecordQuery([new Filter('last_name', Operator::Contains, '%')]));

        self::assertSame(['Grace'], $this->firstNames($found));
    }

    /**
     * The condition §7.3 exists for. A contact with two matching addresses is
     * one contact: a plain JOIN would return it twice and every count on the
     * page would be wrong.
     */
    public function testFilteringOnACollectionReturnsTheParentOnce(): void
    {
        $this->contact('Ada', 'Lovelace', [
            ['id' => null, 'data' => ['street' => 'Baker Street 1', 'city' => 'Zürich']],
            ['id' => null, 'data' => ['street' => 'Bahnhofstrasse 5', 'city' => 'Zürich']],
        ]);
        $this->contact('Grace', 'Hopper', [
            ['id' => null, 'data' => ['street' => 'Kramgasse 2', 'city' => 'Bern']],
        ]);

        $query = new RecordQuery([new Filter('city', Operator::Equals, 'Zürich', 'addresses')]);

        self::assertSame(['Ada'], $this->firstNames($this->matching($query)));
        // And the count agrees with the page, which is the same failure seen from
        // the other side.
        self::assertSame(1, $this->countMatching($query));
    }

    /** A removed address must stop keeping its contact in that city's results. */
    public function testADeletedChildNoLongerMatches(): void
    {
        $contact = $this->contact('Ada', 'Lovelace', [
            ['id' => null, 'data' => ['street' => 'Baker Street 1', 'city' => 'Zürich']],
        ]);

        $this->switcher->runFor($this->tenant, function () use ($contact): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            self::service(RecordWriter::class)->save($module, $contact, ['addresses' => []]);
        });

        self::assertCount(0, $this->matching(new RecordQuery([
            new Filter('city', Operator::Equals, 'Zürich', 'addresses'),
        ])));
    }

    public function testADeletedRecordIsNotFound(): void
    {
        $contact = $this->contact('Ada', 'Lovelace');

        $this->switcher->runFor($this->tenant, function () use ($contact): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            self::service(RecordWriter::class)->delete($module, $contact);
        });

        self::assertCount(0, $this->matching(new RecordQuery()));
    }

    public function testSortingByAField(): void
    {
        $this->contact('Grace', 'Hopper');
        $this->contact('Ada', 'Lovelace');
        $this->contact('Barbara', 'Liskov');

        $ascending = $this->matching(new RecordQuery(sorts: [new Sort('first_name')]));
        self::assertSame(['Ada', 'Barbara', 'Grace'], $this->firstNames($ascending));

        $descending = $this->matching(new RecordQuery(sorts: [new Sort('first_name', Direction::Descending)]));
        self::assertSame(['Grace', 'Barbara', 'Ada'], $this->firstNames($descending));
    }

    /** Missing is missing: it belongs at the end whichever way the sort runs. */
    public function testEmptyValuesSortLastInBothDirections(): void
    {
        $this->contact('Ada', 'Lovelace', [], '1815-12-10');
        $this->contact('Grace', 'Hopper');

        foreach ([Direction::Ascending, Direction::Descending] as $direction) {
            $sorted = $this->matching(new RecordQuery(sorts: [new Sort('birthday', $direction)]));

            self::assertSame('Grace', $this->firstNames($sorted)[1], $direction->value . ' puts the empty one last');
        }
    }

    /**
     * Sorting by a collection has no answer when a record has two of them, so
     * the compiler refuses instead of picking one.
     */
    public function testSortingByACollectionIsRefused(): void
    {
        $this->expectException(UnsupportedQuery::class);
        $this->expectExceptionMessageMatches('/two of them/');

        $this->matching(new RecordQuery(sorts: [new Sort('addresses.city')]));
    }

    /** A comparison the type does not accept is an error, not a silent no-op. */
    public function testAnOperatorTheTypeRejectsIsRefused(): void
    {
        $this->expectException(UnsupportedQuery::class);

        $this->matching(new RecordQuery([new Filter('birthday', Operator::Contains, '12')]));
    }

    public function testAnUnknownFieldIsRefused(): void
    {
        $this->expectException(UnsupportedQuery::class);

        $this->matching(new RecordQuery([new Filter('salary', Operator::Equals, '1')]));
    }

    public function testAnUnknownCollectionIsRefused(): void
    {
        $this->expectException(UnsupportedQuery::class);

        $this->matching(new RecordQuery([new Filter('city', Operator::Equals, 'Bern', 'warehouses')]));
    }

    /**
     * Dates are stored as ISO-8601 precisely so they compare as text, so "born
     * before 1900" is a real comparison rather than a string accident.
     */
    public function testComparingDates(): void
    {
        $this->contact('Ada', 'Lovelace', [], '1815-12-10');
        $this->contact('Grace', 'Hopper', [], '1906-12-09');

        $found = $this->matching(new RecordQuery([new Filter('birthday', Operator::LessThan, '1900-01-01')]));

        self::assertSame(['Ada'], $this->firstNames($found));
    }

    /**
     * A field the *customer* added, of a type that has to be cast to compare —
     * without the cast 9 would be "greater than" 10, which is the bug this
     * exists to prevent. It also exercises the engine's actual claim: a field
     * added as a definition row is queryable with no code changed.
     */
    public function testComparingANumberTheCustomerAdded(): void
    {
        $this->addIntegerField('headcount');

        $this->contact('Ada', 'Lovelace', [], null, ['headcount' => 9]);
        $this->contact('Grace', 'Hopper', [], null, ['headcount' => 10]);

        $found = $this->matching(new RecordQuery([new Filter('headcount', Operator::GreaterThan, 9)]));
        self::assertSame(['Grace'], $this->firstNames($found));

        $sorted = $this->matching(new RecordQuery(sorts: [new Sort('headcount', Direction::Descending)]));
        self::assertSame(['Grace', 'Ada'], $this->firstNames($sorted));
    }

    /** "Not Zürich" includes the records with no city at all. */
    public function testNotEqualsIncludesRecordsWithNoValue(): void
    {
        $this->contact('Ada', 'Lovelace', [], '1815-12-10');
        $this->contact('Grace', 'Hopper');

        $found = $this->matching(new RecordQuery([new Filter('birthday', Operator::NotEquals, '1815-12-10')]));

        self::assertSame(['Grace'], $this->firstNames($found));
    }

    public function testEmptyAndFilled(): void
    {
        $this->contact('Ada', 'Lovelace', [], '1815-12-10');
        $this->contact('Grace', 'Hopper');

        self::assertSame(['Grace'], $this->firstNames($this->matching(
            new RecordQuery([new Filter('birthday', Operator::IsEmpty)]),
        )));
        self::assertSame(['Ada'], $this->firstNames($this->matching(
            new RecordQuery([new Filter('birthday', Operator::IsNotEmpty)]),
        )));
    }

    /**
     * Paging is only correct with a total order. Three records sharing a sort
     * value would otherwise be free to swap places between pages, showing one
     * twice and another never — so the compiler always ends on the id.
     */
    public function testPagingIsStableWhenRecordsShareASortValue(): void
    {
        foreach (['Ada', 'Grace', 'Barbara', 'Katherine'] as $name) {
            $this->contact($name, 'Same');
        }

        $query = new RecordQuery(sorts: [new Sort('last_name')], perPage: 2);

        $first = $this->firstNames($this->matching($query));
        $second = $this->firstNames($this->matching($query->withPage(2)));

        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertSame([], array_intersect($first, $second), 'no record appears on both pages');
        self::assertCount(4, array_unique([...$first, ...$second]));
        self::assertSame(4, $this->countMatching($query));
    }

    public function testFiltersCombineWithAnd(): void
    {
        $this->contact('Ada', 'Lovelace', [['id' => null, 'data' => ['street' => 'A 1', 'city' => 'Zürich']]]);
        $this->contact('Grace', 'Lovelace', [['id' => null, 'data' => ['street' => 'B 2', 'city' => 'Bern']]]);

        $found = $this->matching(new RecordQuery([
            new Filter('last_name', Operator::Equals, 'Lovelace'),
            new Filter('city', Operator::Equals, 'Bern', 'addresses'),
        ]));

        self::assertSame(['Grace'], $this->firstNames($found));
    }

    /**
     * @param list<array{id: int|null, data: array<string, mixed>}> $addresses
     * @param array<string, mixed>                                  $extra
     */
    private function contact(
        string $first,
        string $last,
        array $addresses = [],
        ?string $birthday = null,
        array $extra = [],
    ): Record {
        return $this->switcher->runFor($this->tenant, function () use ($first, $last, $addresses, $birthday, $extra): Record {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            return self::service(RecordWriter::class)->save(
                $module,
                new Record([...$extra, 'first_name' => $first, 'last_name' => $last, 'birthday' => $birthday]),
                $addresses === [] ? [] : ['addresses' => $addresses],
            );
        });
    }

    /** A field this customer added, which the engine has to treat like any other. */
    private function addIntegerField(string $key): void
    {
        $this->switcher->runFor($this->tenant, function () use ($key): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $manager = self::getContainer()->get('doctrine.orm.tenant_entity_manager');
            \assert($manager instanceof EntityManagerInterface);

            new FieldDefinition(shape: $module, key: $key, label: ucfirst($key), type: 'integer', position: 90);

            $manager->persist($module);
            $manager->flush();
        });
    }

    /** @return list<Record> */
    private function matching(RecordQuery $query): array
    {
        return $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)->findBy(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            $query,
        ));
    }

    private function countMatching(RecordQuery $query): int
    {
        return $this->switcher->runFor($this->tenant, fn (): int => self::service(RecordRepository::class)->countBy(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            $query,
        ));
    }

    /**
     * @param list<Record> $records
     *
     * @return list<string>
     */
    private function firstNames(array $records): array
    {
        return array_map(static fn (Record $r): string => (string) $r->get('first_name'), $records);
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

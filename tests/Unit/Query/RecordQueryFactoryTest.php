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

namespace App\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Query\Direction;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQueryFactory;

/**
 * Reading a query out of a URL.
 *
 * Plain arrays, no kernel and no database: the factory takes query parameters
 * rather than a Request precisely so that this is a unit test. Everything here
 * is input somebody could type into the address bar, including the input nobody
 * would type on purpose.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordQueryFactoryTest extends TestCase
{
    private RecordQueryFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RecordQueryFactory();
    }

    public function testAnEmptyQueryStringIsTheDefaultQuery(): void
    {
        $query = $this->factory->fromQueryParameters([]);

        self::assertSame([], $query->filters);
        self::assertSame([], $query->sorts);
        self::assertSame(1, $query->page);
        self::assertFalse($query->isFiltered());
    }

    public function testReadingAFilter(): void
    {
        $query = $this->factory->fromQueryParameters([
            'filter' => [['path' => 'last_name', 'op' => 'contains', 'value' => 'Lovelace']],
        ]);

        self::assertCount(1, $query->filters);
        self::assertSame('last_name', $query->filters[0]->field);
        self::assertNull($query->filters[0]->collection);
        self::assertSame(Operator::Contains, $query->filters[0]->operator);
        self::assertSame('Lovelace', $query->filters[0]->value);
    }

    /** A dotted path is a field on a collection, which the compiler semi-joins. */
    public function testReadingAFilterOnACollection(): void
    {
        $query = $this->factory->fromQueryParameters([
            'filter' => [['path' => 'addresses.city', 'op' => 'eq', 'value' => 'Bern']],
        ]);

        self::assertSame('addresses', $query->filters[0]->collection);
        self::assertSame('city', $query->filters[0]->field);
        self::assertSame('addresses.city', $query->filters[0]->path());
    }

    /** The blank row the page always renders is not a filter for the empty string. */
    public function testAnEmptyRowIsNotAFilter(): void
    {
        $query = $this->factory->fromQueryParameters([
            'filter' => [
                ['path' => '', 'op' => 'contains', 'value' => ''],
                ['path' => 'last_name', 'op' => 'contains', 'value' => ''],
            ],
        ]);

        self::assertSame([], $query->filters);
    }

    /** Unless the comparison is about presence, where having no value is the point. */
    public function testEmptyAndFilledNeedNoValue(): void
    {
        $query = $this->factory->fromQueryParameters([
            'filter' => [['path' => 'birthday', 'op' => 'empty', 'value' => '']],
        ]);

        self::assertCount(1, $query->filters);
        self::assertSame(Operator::IsEmpty, $query->filters[0]->operator);
    }

    public function testReadingASort(): void
    {
        $query = $this->factory->fromQueryParameters(['sort' => 'first_name', 'dir' => 'desc']);

        self::assertCount(1, $query->sorts);
        self::assertSame('first_name', $query->sorts[0]->field);
        self::assertSame(Direction::Descending, $query->sorts[0]->direction);
    }

    /** The field is the part somebody meant; an unreadable direction is ascending. */
    public function testAnUnreadableDirectionFallsBackToAscending(): void
    {
        $query = $this->factory->fromQueryParameters(['sort' => 'first_name', 'dir' => 'sideways']);

        self::assertSame(Direction::Ascending, $query->sorts[0]->direction);
    }

    public function testPagesBelowOneArePageOne(): void
    {
        foreach (['0', '-3', 'nonsense', ''] as $page) {
            self::assertSame(1, $this->factory->fromQueryParameters(['page' => $page])->page, $page);
        }

        self::assertSame(4, $this->factory->fromQueryParameters(['page' => '4'])->page);
        self::assertSame(0, $this->factory->fromQueryParameters([])->offset());
    }

    /**
     * A URL is typed, pasted and truncated, and PHP will happily hand over an
     * array where a string was expected. None of it should be a 500 — the
     * compiler is what refuses things, and it only ever sees what survives here.
     */
    public function testNonsenseIsDroppedRatherThanFatal(): void
    {
        $query = $this->factory->fromQueryParameters([
            'filter' => 'not-an-array',
            'sort' => ['also' => 'not a string'],
            'dir' => ['nested'],
            'page' => ['1'],
        ]);

        self::assertSame([], $query->filters);
        self::assertSame([], $query->sorts);
        self::assertSame(1, $query->page);
    }

    public function testAComparisonThisBuildDoesNotHaveIsDropped(): void
    {
        $query = $this->factory->fromQueryParameters([
            'filter' => [
                ['path' => 'last_name', 'op' => 'sounds-like', 'value' => 'Lovelace'],
                ['path' => 'first_name', 'op' => 'eq', 'value' => 'Ada'],
            ],
        ]);

        // The unreadable one goes; the one beside it still works.
        self::assertCount(1, $query->filters);
        self::assertSame('first_name', $query->filters[0]->field);
    }

    public function testValuesAreTrimmed(): void
    {
        $query = $this->factory->fromQueryParameters([
            'filter' => [['path' => ' last_name ', 'op' => 'eq', 'value' => '  Lovelace  ']],
        ]);

        self::assertSame('last_name', $query->filters[0]->field);
        self::assertSame('Lovelace', $query->filters[0]->value);
    }
}

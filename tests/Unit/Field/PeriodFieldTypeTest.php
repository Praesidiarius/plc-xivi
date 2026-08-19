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

namespace App\Tests\Unit\Field;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\DateRangeFieldType;
use Xivi\Core\Field\Type\DateTimeRangeFieldType;
use Xivi\Core\Field\Type\PeriodFieldType;
use Xivi\Core\Period\ExclusiveWithin;
use Xivi\Core\Period\Period;
use Xivi\Core\Period\PeriodPrecision;
use Xivi\Core\Time\ReaderTimezone;

/**
 * What a period *is*, once it is stored (XIV-136).
 *
 * A unit test because none of this needs a database: the whole claim here is that
 * a pair of dates becomes one canonical string and comes back as one value. The
 * claim that **the database refuses an overlap** is not testable here and is not
 * tested here — that is {@see \App\Tests\Functional\Engine\PeriodOverlapRaceTest}
 * and {@see \App\Tests\Functional\Engine\PeriodFieldTest}, which go through
 * Postgres. Asserting `toStorage()` and declaring the constraint proved would be
 * testing the method rather than the rule.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PeriodFieldTypeTest extends TestCase
{
    /** Every shape a period arrives in, and the one string it becomes. */
    public function testEveryWayOfWritingAPeriodStoresTheSameValue(): void
    {
        $type = $this->days();
        $field = $this->field();

        foreach ([
            '2026-08-01/2026-08-05',
            ' 2026-08-01/2026-08-05 ',
            ['from' => '2026-08-01', 'until' => '2026-08-05'],
            ['from' => new \DateTimeImmutable('2026-08-01'), 'until' => new \DateTimeImmutable('2026-08-05')],
            new Period(new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05')),
        ] as $written) {
            self::assertSame('2026-08-01/2026-08-05', $type->toStorage($written, $field));
        }
    }

    /**
     * A lone date is the period of that day, which is what makes "which of these
     * overlap today" a URL.
     *
     * At both precisions, and the datetime one is the interesting half: the day
     * runs from midnight UTC to midnight UTC, because that is what the stored
     * value is measured in (§8.4.4).
     */
    public function testALoneDateIsThatWholeDay(): void
    {
        self::assertSame('2026-08-19/2026-08-20', $this->days()->toStorage('2026-08-19', $this->field()));
        self::assertSame(
            '2026-08-19T00:00:00Z/2026-08-20T00:00:00Z',
            $this->moments()->toStorage('2026-08-19', $this->field()),
        );
    }

    /**
     * **The three states of an end date**, which is the whole reason the form has
     * a third control.
     *
     * A caller that says nothing about the flag means an open period — the only
     * sensible reading of a pair with one half. A form that says the box was
     * *not* ticked produces a value that cannot be stored, so the validator can
     * ask which was meant rather than the engine deciding.
     */
    public function testAnEndThatIsNotThereIsThreeDifferentThings(): void
    {
        $type = $this->days();
        $field = $this->field();

        self::assertSame('2026-08-01/..', $type->toStorage(['from' => '2026-08-01'], $field), 'no flag: open');
        self::assertSame(
            '2026-08-01/..',
            $type->toStorage(['from' => '2026-08-01', 'until' => null, 'open_ended' => true], $field),
            'ticked: open, deliberately',
        );
        self::assertSame(
            '2026-08-01/',
            $type->toStorage(['from' => '2026-08-01', 'until' => null, 'open_ended' => false], $field),
            'not ticked: unstorable on purpose, so ValidPeriod can ask',
        );
        self::assertNull($type->toStorage(['from' => null, 'until' => null, 'open_ended' => true], $field), 'nothing at all');
    }

    /** A period with an end and no start is not silently dropped either. */
    public function testAnEndWithNoStartIsHandedOverToBeRefused(): void
    {
        self::assertSame(
            '../2026-08-05',
            $this->days()->toStorage(['until' => '2026-08-05'], $this->field()),
        );
    }

    /**
     * **The bound, stated in PHP.**.
     *
     * The same 5th the database is asked about in `PeriodFieldTest`, asserted
     * here against {@see Period::overlaps()} — so that the rule has one written
     * statement in this codebase besides the SQL, and so that a failure says
     * which day and which direction rather than "constraint violated".
     */
    public function testTheEndBoundIsExclusiveOnTheBoundaryDay(): void
    {
        $first = new Period(new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05'));
        $next = new Period(new \DateTimeImmutable('2026-08-05'), new \DateTimeImmutable('2026-08-09'));
        $across = new Period(new \DateTimeImmutable('2026-08-04'), new \DateTimeImmutable('2026-08-06'));

        self::assertFalse(
            $first->overlaps($next),
            'until is the day the period stops, so a stay until the 5th leaves the room free on the 5th',
        );
        self::assertTrue($across->overlaps($first), 'and a stay that starts on the 4th holds a day the first one holds');
        self::assertTrue($first->overlaps($across), 'in both directions, because overlapping is symmetric');
    }

    /** An open end runs over everything after it, and nothing before it. */
    public function testAnOpenEndedPeriodCoversEverythingAfterItsStart(): void
    {
        $open = new Period(new \DateTimeImmutable('2026-08-01'));

        self::assertTrue($open->isOpenEnded());
        self::assertTrue($open->overlaps(new Period(new \DateTimeImmutable('2031-01-01'), new \DateTimeImmutable('2031-02-01'))));
        self::assertFalse($open->overlaps(new Period(new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2020-02-01'))));
    }

    /**
     * **Generated demo data cannot collide with its own constraint.**.
     *
     * The hazard this had to be built against: the generator writes in batches
     * inside a transaction, so one overlapping pair takes the whole batch — and
     * `tenant:reset` destroys before it builds. Two hundred sequences, every pair
     * compared, which is a stronger statement than any number of generated
     * records in a functional test and costs nothing.
     *
     * The field is marked exclusive, because that is what turns the open-ended
     * sample off: a period with no end covers every later week and would refuse
     * everything generated after it.
     */
    public function testGeneratedSamplesNeverOverlapEachOther(): void
    {
        foreach ([$this->days(), $this->moments()] as $type) {
            $field = $this->field(exclusiveWithin: 'room', required: true);
            $periods = [];

            for ($sequence = 1; $sequence <= 200; ++$sequence) {
                $stored = $type->sample($field, $sequence);
                self::assertIsString($stored, 'a required field is always filled in');

                $period = $type->precision()->read($stored);
                self::assertInstanceOf(Period::class, $period, $stored);
                self::assertFalse($period->isOpenEnded(), 'an exclusive field is never given an open end');

                foreach ($periods as $earlier) {
                    self::assertFalse($period->overlaps($earlier), sprintf(
                        'sample %d overlaps an earlier one, which would break tenant:reset part way',
                        $sequence,
                    ));
                }

                $periods[] = $period;
            }
        }
    }

    /** And a field with no constraint does get open-ended ones, because pages have to render them. */
    public function testAFieldWithNoConstraintSeesOpenEndedSamples(): void
    {
        $type = $this->days();
        $field = $this->field(required: true);
        $open = 0;

        for ($sequence = 1; $sequence <= 200; ++$sequence) {
            $stored = (string) $type->sample($field, $sequence);

            if (str_ends_with($stored, PeriodPrecision::OPEN)) {
                ++$open;
            }
        }

        self::assertGreaterThan(0, $open);
    }

    /**
     * The one place a type reaches into SQL, and the shape everything else rests
     * on: a range, from the function the tenant's migration installed.
     */
    public function testItComparesAsARange(): void
    {
        self::assertSame("xivi_date_range(data->>'stay')", $this->days()->comparableSql("data->>'stay'"));
        self::assertSame("xivi_datetime_range(data->>'slot')", $this->moments()->comparableSql("data->>'slot'"));
    }

    /** Anything that is not a period is handed back for the validator to name. */
    public function testSomethingThatIsNotAPeriodIsHandedOverRatherThanDropped(): void
    {
        self::assertSame('next summer', $this->days()->toStorage('next summer', $this->field()));
        self::assertNull($this->days()->toStorage('', $this->field()));
        self::assertNull($this->days()->fromStorage('next summer', $this->field()));
    }

    private function days(): PeriodFieldType
    {
        return new DateRangeFieldType($this->reader(), new IdentityTranslator());
    }

    private function moments(): PeriodFieldType
    {
        return new DateTimeRangeFieldType($this->reader(), new IdentityTranslator());
    }

    private function reader(): ReaderTimezone
    {
        return new class implements ReaderTimezone {
            public function zone(): \DateTimeZone
            {
                return new \DateTimeZone('UTC');
            }
        };
    }

    /** A real definition rather than a mock: the type reads its options. */
    private function field(?string $exclusiveWithin = null, bool $required = false): FieldDefinition
    {
        $field = new FieldDefinition(
            new ModuleDefinition('booking', 'Bookings', 'booking'),
            'stay',
            'Stay',
            DateRangeFieldType::KEY,
            required: $required,
        );

        if ($exclusiveWithin !== null) {
            $field->setOptions([ExclusiveWithin::OPTION => $exclusiveWithin]);
        }

        return $field;
    }
}

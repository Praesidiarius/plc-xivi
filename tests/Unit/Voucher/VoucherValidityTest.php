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

namespace App\Tests\Unit\Voucher;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Query\Operator;
use Xivi\Core\Record\Record;
use Xivi\Voucher\Validity\VoucherValidity;
use Xivi\Voucher\VoucherModule;

/**
 * Expiry is a read, and this is what reading it means (XIV-103).
 *
 * A unit test, and for the same reason `NumberFormatTest` is one: the subject is
 * the calendar, and the only honest way to check "and the day after it stops" is
 * to hand it that day. Nothing here needs a kernel, a tenant or a record that
 * has ever been saved — which is itself the claim being made, because a stored
 * expiry state would have needed all three and a job besides.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VoucherValidityTest extends TestCase
{
    private VoucherValidity $validity;

    protected function setUp(): void
    {
        $this->validity = new VoucherValidity();
    }

    /**
     * The ordinary voucher: no dates at all, good for ever.
     *
     * This is the most common way a voucher is created and the one an
     * implementation is most likely to get wrong, because "is today before the
     * end date" has no obvious answer when there is no end date. It has to be
     * yes.
     */
    public function testAVoucherWithNoDatesIsAlwaysValid(): void
    {
        $voucher = new Record([VoucherModule::CODE => 'GIVE-10']);

        self::assertTrue($this->validity->isValidOn($voucher, self::day('2026-08-18')));
        self::assertFalse($this->validity->hasExpired($voucher, self::day('2999-01-01')));
        self::assertFalse($this->validity->hasNotStarted($voucher, self::day('1999-01-01')));
    }

    /** Inside its window, which is the case everything else is measured against. */
    public function testAVoucherInsideItsDatesIsValid(): void
    {
        $voucher = self::between('2026-08-01', '2026-08-31');

        self::assertTrue($this->validity->isValidOn($voucher, self::day('2026-08-18')));
    }

    /**
     * The boundaries are inclusive at both ends.
     *
     * A voucher good until the 31st is good on the 31st. Telling somebody their
     * code has expired on the morning of its last day is the same mistake §5.16
     * refuses to make about an invoice that falls due today, and it is the one
     * an off-by-one produces.
     */
    public function testTheFirstAndLastDaysAreBothInsideTheWindow(): void
    {
        $voucher = self::between('2026-08-01', '2026-08-31');

        self::assertTrue($this->validity->isValidOn($voucher, self::day('2026-08-01')), 'its first day');
        self::assertTrue($this->validity->isValidOn($voucher, self::day('2026-08-31')), 'and its last');
    }

    /** And one day past either end it is out. */
    public function testADayEitherSideOfTheWindowIsOutsideIt(): void
    {
        $voucher = self::between('2026-08-01', '2026-08-31');

        self::assertTrue($this->validity->hasNotStarted($voucher, self::day('2026-07-31')));
        self::assertTrue($this->validity->hasExpired($voucher, self::day('2026-09-01')));
        self::assertFalse($this->validity->isValidOn($voucher, self::day('2026-07-31')));
        self::assertFalse($this->validity->isValidOn($voucher, self::day('2026-09-01')));
    }

    /**
     * A Christmas voucher printed in October.
     *
     * The half a feature that only knew about expiry would get wrong, and it
     * would get it wrong by accepting the code for two months.
     */
    public function testAVoucherThatHasNotStartedIsNotValidEither(): void
    {
        $voucher = new Record([VoucherModule::VALID_FROM => '2026-12-01']);

        self::assertFalse($this->validity->isValidOn($voucher, self::day('2026-10-15')));
        self::assertTrue($this->validity->isValidOn($voucher, self::day('2026-12-01')));
    }

    /**
     * The dates read the same whether the record came out of the database or is
     * still in memory from a save.
     *
     * A loaded record carries the immutable date its field type builds; one
     * halfway through a save carries the string that was typed. Both are the same
     * day, and this rule is asked on both paths.
     */
    public function testADateReadsTheSameAsAnObjectOrAsAString(): void
    {
        $typed = new Record([VoucherModule::VALID_UNTIL => '2026-08-01']);
        $loaded = new Record([VoucherModule::VALID_UNTIL => new \DateTimeImmutable('2026-08-01')]);

        self::assertTrue($this->validity->hasExpired($typed, self::day('2026-08-02')));
        self::assertTrue($this->validity->hasExpired($loaded, self::day('2026-08-02')));
    }

    /**
     * Nothing about validity is stored, so the same record answers differently on
     * two different days without anything having written to it.
     *
     * That sentence is the whole design, and this is the assertion that says it:
     * one record, no save in between, two answers.
     */
    public function testTheSameRecordExpiresWithoutBeingWrittenTo(): void
    {
        $voucher = self::between('2026-08-01', '2026-08-31');

        self::assertTrue($this->validity->isValidOn($voucher, self::day('2026-08-31')));
        self::assertFalse($this->validity->isValidOn($voucher, self::day('2026-09-01')));
        self::assertSame(
            ['valid_from' => '2026-08-01', 'valid_until' => '2026-08-31'],
            $voucher->data,
            'and nothing touched the record',
        );
    }

    /**
     * The same rule as query conditions, for a list rather than for a record in
     * hand.
     *
     * One condition and one only, which is worth asserting because the temptation
     * is to add `valid_until IS NOT NULL` beside it: a row with nothing in the
     * column cannot be less than a date, so the second condition would be a
     * restatement that can drift.
     */
    public function testTheExpiryRuleIsAlsoAvailableAsAQueryCondition(): void
    {
        $filters = $this->validity->expiredFilters(self::day('2026-09-01'));

        self::assertCount(1, $filters);
        self::assertSame(VoucherModule::VALID_UNTIL, $filters[0]->field);
        self::assertSame(Operator::LessThan, $filters[0]->operator);
        self::assertSame('2026-09-01', $filters[0]->value);
    }

    /**
     * And the mirror of it, which is what a list of usable vouchers wants
     * beside the first (XIV-175).
     *
     * Same shape, same one-condition argument, the other end of the calendar. A
     * picker that had only the expiry half would offer a Christmas voucher
     * printed in October for two months, and the save would be the first thing
     * to say so.
     */
    public function testTheNotStartedRuleIsAlsoAvailableAsAQueryCondition(): void
    {
        $filters = $this->validity->notStartedFilters(self::day('2026-09-01'));

        self::assertCount(1, $filters);
        self::assertSame(VoucherModule::VALID_FROM, $filters[0]->field);
        self::assertSame(Operator::GreaterThan, $filters[0]->operator);
        self::assertSame('2026-09-01', $filters[0]->value);
    }

    /**
     * The two conditions are the same boundaries the record-in-hand reading
     * draws.
     *
     * The one property that makes a narrowed picker and the refusal at the write
     * agree: a voucher good *until today* is good today, and one starting today
     * has started, in both readings. `LessThan` and `GreaterThan` are strict for
     * that reason, and this asserts it against {@see VoucherValidity::isValidOn}
     * rather than restating the operators, because what must not drift is the
     * pair of answers rather than the pair of spellings.
     */
    public function testTheConditionsDrawTheSameBoundariesAsTheRecordReading(): void
    {
        $today = self::day('2026-09-01');

        self::assertTrue(
            $this->validity->isValidOn(self::between('2026-09-01', '2026-09-01'), $today),
            'its first and last day are both today',
        );
        self::assertSame('2026-09-01', $this->validity->expiredFilters($today)[0]->value);
        self::assertSame('2026-09-01', $this->validity->notStartedFilters($today)[0]->value);
    }

    private static function between(string $from, string $until): Record
    {
        return new Record([
            VoucherModule::VALID_FROM => $from,
            VoucherModule::VALID_UNTIL => $until,
        ]);
    }

    /** Mid-afternoon on purpose: a comparison here is between days, not instants. */
    private static function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' 15:42:07');
    }
}

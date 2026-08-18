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

namespace Xivi\Voucher\Validity;

use Xivi\Core\Field\Type\DateFieldType;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Record\Record;
use Xivi\Voucher\VoucherModule;

/**
 * Whether a voucher is in date, worked out when somebody asks (XIV-103).
 *
 * ### Expired is a read, not a state
 *
 * This is [XIV-67]'s argument about overdue invoices, and it applies here
 * without a word changed — see {@see \Xivi\Core\Payment\Overdue}, which is the
 * class this one is shaped like.
 *
 * The tempting version is a status field beside "active" with something moving
 * vouchers into "expired". It should not exist, for a reason that is structural
 * rather than aesthetic: **nothing performs expiry; the calendar does.** A stored
 * flag would need a job to mutate a customer's records on a schedule, with no
 * human act behind it and no worker process here to run it (§8.7, XIV-59) — and
 * it would be a flag that is *wrong* between midnight and whenever the job next
 * ran, which is the window in which somebody redeems the voucher it was supposed
 * to have closed.
 *
 * So expiry is `valid_until < today`, evaluated on read. Cheap, always correct,
 * needs no job, and cannot drift out of step with the calendar. Nothing is
 * stored, so tightening the definition later migrates nothing.
 *
 * ### An empty date is not a boundary
 *
 * Both directions, and never the other way round. No `valid_from` means the
 * voucher has always been good; no `valid_until` means it never stops. Reading an
 * absent date as a passed one would expire every voucher a customer created
 * without filling the field in — the single most common way to create one — and
 * would do it silently, at the till.
 *
 * ### Two ways to ask, and one of them is missing a half
 *
 * {@see isValidOn()} answers about a record already in hand, which is what a page
 * drawing one wants and what [XIV-104] will ask before redeeming.
 * {@see expiredFilters()} hands the same rule to the query layer (§5.3), which is
 * what a *list* of them wants.
 *
 * **There is deliberately no `validFilters()`**, and the reason is a limitation
 * rather than a preference. "Currently valid" is
 * `(from IS NULL OR from <= today) AND (until IS NULL OR until >= today)` — a
 * conjunction of two disjunctions — and §7 question 3 records that `OR` between
 * conditions is one of the two things the query layer still cannot express.
 * Expired and not-yet-started are each a single condition and compile fine, which
 * is why those two exist and the useful third does not. Faking it by ANDing
 * `until >= today` alone would quietly drop every voucher with no end date, which
 * is most of them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class VoucherValidity
{
    /**
     * Whether this voucher is inside its dates on the given day.
     *
     * Never throws and never queries: two values off a record that has already
     * been loaded, so a list may call it once per row without turning into a page
     * of round trips.
     *
     * `$today` exists so that a test can stand somewhere other than now, which
     * for a feature whose whole subject is the calendar is not a convenience but
     * the only way to test it at all. The default is the same
     * `new \DateTimeImmutable` every other dated thing in this engine takes.
     */
    public function isValidOn(Record $voucher, ?\DateTimeImmutable $today = null): bool
    {
        return !$this->hasExpired($voucher, $today) && !$this->hasNotStarted($voucher, $today);
    }

    /** Its last day is behind us. Strictly before: a voucher good until today is good today. */
    public function hasExpired(Record $voucher, ?\DateTimeImmutable $today = null): bool
    {
        $until = self::dayIn($voucher->get(VoucherModule::VALID_UNTIL));

        return $until !== null && $until < self::dayOf($today);
    }

    /**
     * Its first day is still ahead of us.
     *
     * The mirror of expiry and just as necessary: a Christmas voucher printed in
     * October is not valid, and a feature that only knew about the far end would
     * accept it for two months.
     */
    public function hasNotStarted(Record $voucher, ?\DateTimeImmutable $today = null): bool
    {
        $from = self::dayIn($voucher->get(VoucherModule::VALID_FROM));

        return $from !== null && $from > self::dayOf($today);
    }

    /**
     * The expiry half of the rule, as query conditions.
     *
     * A list rather than a single filter so the shape matches
     * {@see \Xivi\Core\Payment\Overdue::filters()} and so a caller ANDs it with
     * whatever else it has (§7.3) without a special case for the one-element
     * case.
     *
     * `IsEmpty` needs no counterpart here: a row with nothing in the column
     * cannot be less than a date, so a voucher that never expires is excluded by
     * the comparison itself rather than by a second condition somebody has to
     * remember.
     *
     * @return list<Filter>
     */
    public function expiredFilters(?\DateTimeImmutable $today = null): array
    {
        return [new Filter(
            VoucherModule::VALID_UNTIL,
            Operator::LessThan,
            self::dayOf($today)->format(DateFieldType::FORMAT),
        )];
    }

    /**
     * A stored date as a day, whichever form it arrived in.
     *
     * A loaded record carries the immutable date its field type builds; a record
     * still in memory from a save may carry the string that was typed. Both are
     * the same day and this is asked on both paths — the same two-shaped read
     * {@see \Xivi\Core\Payment\Overdue} needs, for the same reason.
     */
    private static function dayIn(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(DateFieldType::FORMAT);
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . DateFieldType::FORMAT, $value);

        return $date === false ? null : $date;
    }

    /** Midnight, so a comparison is between two days rather than two instants. */
    private static function dayOf(?\DateTimeImmutable $today): \DateTimeImmutable
    {
        return ($today ?? new \DateTimeImmutable())->setTime(0, 0);
    }
}

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

namespace Xivi\Core\Period;

/**
 * A stretch of time, as one value (XIV-136).
 *
 * A stay, a tenancy, a room assignment, a booking, a hire, a secondment: two
 * dates that only mean anything together. Before this the engine could hold them
 * only as two `date` fields, which is two values with a relationship nothing in
 * the engine knew about — so "who is in room 3 today" had to be written by
 * whoever wanted to ask it, slightly differently each time, and "these two
 * cannot overlap" could not be expressed at all.
 *
 * ### The end bound is exclusive, everywhere, for both precisions
 *
 * `[from, until)`. **`until` is the first moment that is *outside* the period**,
 * not the last one inside it. A booking from the 1st to the 5th occupies the
 * nights of the 1st to the 4th and the room is free again *on* the 5th; the next
 * booking may start on the 5th and the two do not overlap.
 *
 * This is a decision rather than an option, and the three reasons compound:
 *
 *  * **It is the only bound that means the same thing at both precisions.** A
 *    date range has a last day; a datetime range has no last instant, because
 *    time between two moments is continuous and "11:00 minus the smallest thing"
 *    is not a value. An inclusive end is therefore undefined for
 *    {@see PeriodPrecision::DateTime}, and a rule that flips at one precision is
 *    a rule nobody can hold in their head.
 *  * **Postgres already agrees.** `daterange` canonicalises every literal to
 *    `[)` whatever it was written as, so storing an inclusive end would mean the
 *    value in the JSON and the value in the constraint's index disagree by a day
 *    — for ever, and in every debugging session anybody has about it later.
 *  * **The arithmetic comes out.** Nights, hours and days are `until - from`
 *    with no ±1 anywhere, and two adjacent periods meet exactly: no overlap and
 *    no gap.
 *
 * What it costs is that a tenancy whose last day is the 5th is entered as ending
 * on the **6th**, which is genuinely surprising the first time. That is paid for
 * where it is felt rather than argued away: {@see \Xivi\Core\Form\PeriodType}
 * says on the field itself what the second box means, and
 * `PeriodBoundaryTest` tests that day in both directions with a failure message
 * that says which bound it is asserting.
 *
 * ### Open at the end, never at the start
 *
 * A tenancy with no agreed end is an ordinary thing and `[from, ∞)` expresses it
 * exactly. A period with no *beginning* is not: every one of the things this
 * holds starts on a day somebody can name, and "unbounded below" would be a
 * value overlapping every past period ever recorded, arrived at by leaving a box
 * empty. So `from` is what makes a period a period, and a value with an end and
 * no start is refused ({@see \Xivi\Core\Validation\ValidPeriodValidator}).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Period
{
    public function __construct(
        public ?\DateTimeImmutable $from = null,
        /** Null beside a `from` means open-ended; null beside nothing means no value at all. */
        public ?\DateTimeImmutable $until = null,
    ) {
    }

    /** No value: the field is empty, which an optional one is allowed to be. */
    public function isEmpty(): bool
    {
        return $this->from === null && $this->until === null;
    }

    /**
     * `[from, ∞)` — it started and nobody has said when it ends.
     *
     * Distinct from {@see self::isEmpty()} on purpose: an empty field and a
     * period that runs for ever are opposite answers, and the interface has to
     * make somebody choose between them rather than reading one blank box as
     * both ({@see \Xivi\Core\Form\PeriodType}).
     */
    public function isOpenEnded(): bool
    {
        return $this->from !== null && $this->until === null;
    }

    /**
     * Whether two periods share a moment, under the half-open rule above.
     *
     * **Not what enforces anything.** The database refuses an overlap
     * ({@see \Xivi\Core\Record\OverlapExclusion}) because a check in PHP is a
     * read followed by a write with a race in the gap — XIV-109's whole finding,
     * one level harder. This is here so the rule can be *stated* in one place and
     * asserted against the database's answer, which is what the boundary test
     * does: if the two ever disagree about the 5th, one of them is wrong and the
     * test says which day it was asked about.
     */
    public function overlaps(self $other): bool
    {
        if ($this->from === null || $other->from === null) {
            return false;
        }

        return ($this->until === null || $other->from < $this->until)
            && ($other->until === null || $this->from < $other->until);
    }
}

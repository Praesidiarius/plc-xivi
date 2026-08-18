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

namespace Xivi\Core\Metadata;

use Xivi\Core\Numbering\NumbersFound;

/**
 * What turning numbering on would do, in the words a page needs to say it
 * (XIV-91).
 *
 * This object exists because of one rule: **a destructive step names its scale
 * before it happens.** §4.1 argues it for removing a tenant and the tone is the
 * one to match here — say what is about to happen, say how much of it there is,
 * and default to no. A backfill writes numbers into records that already exist,
 * once, with no undo; a page that offered it as a checkbox and reported
 * afterwards would be telling somebody something they can no longer act on.
 *
 * So the same computation answers twice, and that is the design rather than a
 * convenience. {@see NumberingChange::plan()} builds this and renders the
 * confirmation from it; {@see NumberingChange::start()} builds it again inside
 * the transaction that does the work and returns what actually happened. The
 * numbers can differ — somebody may have added a record in between — and the
 * second one is the truth, which is why the flash a customer sees afterwards is
 * drawn from the second and not from the page they agreed on.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class NumberingPlan
{
    /**
     * @param NumbersFound $found  what the column already holds
     * @param int          $from   the counter value the oldest unnumbered record gets
     * @param ?string      $first  what that record will be called, or null when
     *                             nothing is waiting for a number
     * @param ?string      $last   what the newest one will be called — the two
     *                             together are how somebody checks the width is
     *                             wide enough before rather than after
     * @param string       $next   what the record created *after* all of this
     *                             will be called
     * @param string       $period the counter this draws from: a year, or empty
     *                             for one that never restarts
     */
    public function __construct(
        public NumbersFound $found,
        public int $from,
        public ?string $first,
        public ?string $last,
        public string $next,
        public string $period,
    ) {
    }

    /**
     * How many records this writes to, which is the number the confirmation is
     * really about.
     *
     * Named rather than reached through `found` because it is the one figure on
     * that page that describes an *action*: everything else says what is there,
     * and this says what will be changed.
     */
    public function writes(): int
    {
        return $this->found->blank;
    }
}

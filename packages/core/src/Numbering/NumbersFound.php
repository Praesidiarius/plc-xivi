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

namespace Xivi\Core\Numbering;

/**
 * What is already in a column that is about to become numbered (XIV-91).
 *
 * The answer to the two questions XIV-27 deferred, measured rather than assumed.
 * Turning numbering on for a field a customer has been typing into for three
 * years is not one change but two: some rows have nothing in them and will be
 * given numbers, and some have something in them that the counter must never
 * hand out again. Both are facts about *records*, both are countable, and
 * neither can be guessed from a definition — which is why they are gathered into
 * an object and put on a page before anything is written.
 *
 * Every number here is about **live** records. A soft-deleted row keeps its
 * value (§5) and is not backfilled, because a deleted document is not a document
 * waiting for a number; its old value is still counted among the ones the
 * counter must stay clear of, though, since undeleting it must not produce a
 * duplicate.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class NumbersFound
{
    /**
     * @param int  $blank      live records with nothing in the field — what a backfill would write to
     * @param int  $held       live records with something in it, which a backfill never overwrites
     * @param int  $recognised how many of those look like something this pattern
     *                         could have produced, and are therefore numbers the
     *                         counter has to stay above
     * @param ?int $highest    the largest counter value found among them, or null
     *                         when the column holds no number of this shape at all
     */
    public function __construct(
        public int $blank,
        public int $held,
        public int $recognised,
        public ?int $highest,
    ) {
    }

    /**
     * The lowest number the counter may still give out without repeating one.
     *
     * A floor rather than a setting: it says where the counter must be *at
     * least*, and a counter already past it is not moved. That distinction is
     * why {@see NumberAllocator::atLeast()} exists as its own statement — a
     * customer's own wind-forward is a value, this is a bound.
     */
    public function floor(): int
    {
        return $this->highest === null ? NumberAllocator::FIRST : $this->highest + 1;
    }

    /**
     * Values in the column this pattern could never produce.
     *
     * `Referenz 12` beside `RE-2026-0007`, and the honest thing to say about it
     * is nothing: a number rendered from this pattern cannot come out looking
     * like that, so the counter cannot duplicate it, so nothing needs to be done
     * about it. The count is shown anyway, because "42 of your 60 references
     * look like this pattern" is how somebody finds out they typed the pattern
     * slightly wrong — an off-by-one in the padding turns this number into 0 and
     * says so before the counter is floored at 1.
     */
    public function unrecognised(): int
    {
        return $this->held - $this->recognised;
    }
}

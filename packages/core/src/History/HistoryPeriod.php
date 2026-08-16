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

namespace Xivi\Core\History;

/**
 * How long ago something happened, in the sizes people actually ask in (XIV-3).
 *
 * A timeline of five hundred entries is unreadable as one list and perfectly
 * readable as "today, this week, this month, this year, and everything before
 * that" — so the periods are coarse and get coarser going back, which is how
 * memory of a record works too.
 *
 * The boundaries are calendar boundaries, not multiples of 24 hours: something
 * that happened at 23:50 yesterday belongs under yesterday even when it is ten
 * minutes old, because that is the date somebody will look for it under.
 *
 * **Whose calendar, though** (XIV-83). A calendar boundary is a fact about a
 * clock on a wall, and until this had a zone it was drawing them in UTC — so an
 * entry written at 00:30 in Zurich landed under *yesterday* for the person who
 * had just made it, and the "23:50 belongs under yesterday" argument above was
 * being applied to somebody else's 23:50. That is the sharp end of storing UTC
 * without ever displaying local: an hour's error in a label is cosmetic, and the
 * same hour crossing a grouping boundary moves the entry.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum HistoryPeriod: string
{
    case Today = 'today';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
    case Older = 'older';

    /**
     * Which band something falls in, as read from `$now`'s own zone.
     *
     * **The zone comes in on `$now` rather than as an argument of its own**, and
     * that is the whole of the mechanism: `setTime()` below means midnight *where
     * `$now` is*, so handing this a `$now` in `Europe/Zurich` draws Zurich's
     * boundaries and handing it a UTC one draws UTC's. `$when` needs no
     * conversion at all — comparing two `DateTimeImmutable`s compares instants,
     * not wall clocks, so an entry stored in UTC lands on the right side of a
     * Zurich midnight without being touched. `HistorySection::of()` is where the
     * zone is applied, so there is one place that decides it rather than one per
     * caller.
     */
    public static function of(\DateTimeImmutable $when, \DateTimeImmutable $now): self
    {
        $startOfToday = $now->setTime(0, 0);

        return match (true) {
            $when >= $startOfToday => self::Today,
            $when >= $startOfToday->modify('-7 days') => self::Week,
            $when >= $startOfToday->modify('-1 month') => self::Month,
            $when >= $startOfToday->modify('-1 year') => self::Year,
            default => self::Older,
        };
    }

    /**
     * Whether a section this old opens closed.
     *
     * The recent ones are what somebody came for; a year of edits from before
     * they started is context, and context that costs a click is cheaper than
     * context that costs a scroll. The ticket asked for exactly this line to be
     * drawn at a month.
     */
    public function isFoldedByDefault(): bool
    {
        return match ($this) {
            self::Today, self::Week => false,
            self::Month, self::Year, self::Older => true,
        };
    }

    /** A key in the `xivi` domain — the engine's own vocabulary (XIV-8). */
    public function labelKey(): string
    {
        return 'history.period.' . $this->value;
    }
}

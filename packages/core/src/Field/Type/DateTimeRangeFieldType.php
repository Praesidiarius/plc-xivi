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

namespace Xivi\Core\Field\Type;

use Xivi\Core\Period\PeriodPrecision;

/**
 * A period measured in moments: a meeting room, a machine, a shift (XIV-136).
 *
 * The other half of what "a period" turns out to mean. A meeting room is booked
 * 09:00–11:00 and the next meeting starts at 11:00, so two of these overlap when
 * they share an **instant** rather than a day — which is the thing
 * {@see DateRangeFieldType} cannot express, since both meetings are on the same
 * Tuesday and a date period would call that a collision.
 *
 * **Stored in UTC and read in the reader's zone** (§8.4.4). That is the whole
 * difference from the date version and it is where this goes wrong if it goes
 * wrong: a booking that runs `22:00Z–23:30Z` is Tuesday night in Greenwich and
 * Wednesday morning in Zurich, so the zone decides not only the clock but the
 * *day* it is filed under. The zone comes from {@see \Xivi\Core\Time\ReaderTimezone},
 * which is [XIV-83]'s chain arriving in core rather than a second answer to the
 * same question.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DateTimeRangeFieldType extends PeriodFieldType
{
    /** Stored in every `field_definition` row that uses it. */
    public const string KEY = 'datetime_range';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Period (times)';
    }

    public function precision(): PeriodPrecision
    {
        return PeriodPrecision::DateTime;
    }
}

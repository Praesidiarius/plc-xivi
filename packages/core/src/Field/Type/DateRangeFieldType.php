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
 * A period measured in whole days: a stay, a tenancy, an assignment (XIV-136).
 *
 * The one a care home and a hotel are actually made of. A room is occupied for
 * nights rather than for hours, so two stays overlap when they share a **day**,
 * and a stay from the 1st to the 5th leaves the room free on the 5th — see
 * {@see \Xivi\Core\Period\Period} for why the end is the day it stops rather than
 * the last day inside.
 *
 * Zoneless, like {@see DateFieldType} and for the same reason: a stay from the
 * 1st to the 5th is those days wherever it is read from, and nothing about it
 * moves because somebody opened the page in Auckland.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DateRangeFieldType extends PeriodFieldType
{
    /** Stored in every `field_definition` row that uses it. */
    public const string KEY = 'date_range';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Period (days)';
    }

    public function precision(): PeriodPrecision
    {
        return PeriodPrecision::Date;
    }
}

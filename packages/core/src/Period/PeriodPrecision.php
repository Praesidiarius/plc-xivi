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

use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;

/**
 * Whether a period is measured in days or in moments — and what "overlap" then
 * means (XIV-136).
 *
 * A hotel room is booked by the night and a meeting room from 09:00 to 11:00.
 * Both are periods and the rule that two of them cannot share one is the same
 * rule, but they are not the same kind of value and the difference is not
 * cosmetic:
 *
 *  * **A day has no zone and a moment has nothing else** (§8.4.4). A stay from
 *    the 1st to the 5th is those days wherever it is read; a booking at 09:00 is
 *    an instant, stored in UTC and read in the reader's zone, and the same
 *    instant is a different wall clock in Zurich and in Auckland. That is why
 *    {@see DateRangeFieldType} formats without a zone and
 *    {@see DateTimeRangeFieldType} asks who is reading.
 *  * **Overlap is therefore a different question.** Two date periods overlap
 *    when they share a *day*; two datetime periods overlap when they share an
 *    *instant*. A meeting 09:00–11:00 and one 11:00–12:00 do not overlap and are
 *    the same calendar day, which under a date period would collide. Postgres
 *    says both exactly — `daterange` over days and `tsrange` over moments — and
 *    which one a field gets is what this enum decides.
 *
 * ### Why two field types rather than one with a setting
 *
 * The engine's own seam settled it. {@see \Xivi\Core\Field\FieldType::comparableSql()}
 * is handed an accessor and nothing else — no field, no options — because that
 * is what stops the query compiler growing a switch on type. A precision kept as
 * a per-field *option* would be invisible exactly there, at the one point where
 * the SQL has to know whether it is building a `daterange` or a `tsrange`.
 *
 * Making it the type instead answers a second question for free: §5.4 refuses to
 * change a field's type because stored values may not survive one, and a
 * precision somebody could flip on a populated field is a stored value not
 * surviving. `2026-08-01/2026-08-05` and
 * `2026-08-01T09:00:00Z/2026-08-01T11:00:00Z` are not each other, and an
 * exclusion constraint built over one of them would be indexing the other.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum PeriodPrecision: string
{
    /** Whole days: a stay, a tenancy, an assignment. */
    case Date = 'date';

    /** Moments: a meeting room, a machine, a shift. */
    case DateTime = 'datetime';

    /**
     * What separates the two ends of one stored value.
     *
     * ISO 8601 writes an interval `start/end` and so does this. The alternative
     * was a JSON object with two keys, and the slash wins for a reason that is
     * structural rather than stylistic: **a stored value stays a scalar**. The
     * spreadsheet export writes a cell rather than an array, the history diff
     * compares two strings rather than two arrays, the importer reads one column,
     * `data ->> 'stay'` is the whole value, and every one of those went on
     * working without being told a period exists.
     */
    public const string SEPARATOR = '/';

    /**
     * What stands where an end date would be, when there is deliberately none.
     *
     * ISO 8601-2's own spelling for an open end, and — more to the point — a
     * value that is *there*. An empty half would be indistinguishable from a
     * truncated one, and "the end is missing" and "there is no end" are the two
     * things §5.4 is most concerned should not look alike.
     */
    public const string OPEN = '..';

    /**
     * The stored spelling of one endpoint.
     *
     * ISO-8601, which sorts and compares as text — the property that lets a
     * period live in JSONB as a plain string, exactly as {@see \Xivi\Core\Field\Type\DateFieldType}
     * argues for a date. `Z` rather than an offset, because everything the engine
     * stores is UTC (§8.4.4) and an offset in storage is a second way of writing
     * the same instant.
     */
    public function format(): string
    {
        return match ($this) {
            self::Date => 'Y-m-d',
            self::DateTime => 'Y-m-d\TH:i:s\Z',
        };
    }

    /** One endpoint, as a regular expression — the fixed width the SQL side relies on. */
    public function pattern(): string
    {
        return match ($this) {
            self::Date => '\d{4}-\d{2}-\d{2}',
            self::DateTime => '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z',
        };
    }

    /**
     * The Postgres function that turns a stored value into a range.
     *
     * Installed per tenant by a migration and defined once in {@see PeriodSql},
     * which is where the argument for a function rather than an inline expression
     * lives.
     */
    public function rangeFunction(): string
    {
        return match ($this) {
            self::Date => 'xivi_date_range',
            self::DateTime => 'xivi_datetime_range',
        };
    }

    /**
     * The form control for one end of it.
     *
     * @return class-string<\Symfony\Component\Form\FormTypeInterface<mixed>>
     */
    public function formType(): string
    {
        return match ($this) {
            self::Date => DateType::class,
            self::DateTime => DateTimeType::class,
        };
    }

    /**
     * One endpoint, read back into a moment — or null if it is not one.
     *
     * Always UTC, and `!` so that a date with no time is midnight rather than
     * "midnight for the date part and now for the rest", which is
     * `createFromFormat`'s default and the source of a whole genre of bug.
     */
    public function parse(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/^' . $this->pattern() . '$/', $value) !== 1) {
            return null;
        }

        $moment = \DateTimeImmutable::createFromFormat(
            '!' . $this->format(),
            $value,
            new \DateTimeZone('UTC'),
        );

        return $moment === false ? null : $moment;
    }

    /**
     * A stored value, back into the pair it names — or null if it is not one.
     *
     * **Strict, and deliberately not forgiving.** The same string is read here,
     * by Postgres in {@see PeriodSql}, and by the constraint's index; a reader
     * that accepted `2026-8-1/2026-8-5` here would accept a value the index reads
     * as nothing at all, and the two would disagree about whether a record has a
     * period. So there is one spelling, {@see self::write()} is the only thing
     * that produces it, and anything else is a value on its way to being refused
     * by {@see \Xivi\Core\Validation\ValidPeriodValidator}.
     */
    public function read(string $value): ?Period
    {
        $parts = explode(self::SEPARATOR, $value);

        if (\count($parts) !== 2) {
            return null;
        }

        [$start, $end] = $parts;
        $from = $this->parse($start);

        if ($from === null) {
            return null;
        }

        if ($end === self::OPEN) {
            return new Period($from);
        }

        $until = $this->parse($end);

        return $until === null ? null : new Period($from, $until);
    }

    /**
     * The pair, as one stored string.
     *
     * A period with an end and no start comes back as `../<end>`, which is not a
     * value this engine will store: it goes to the validator, which refuses it
     * saying a period needs a start. Returning null instead would be the same
     * mistake {@see \Xivi\Core\Field\Type\PhoneFieldType::toStorage()} refuses to
     * make with a mistyped number — the record would save, the box somebody
     * filled in would be empty afterwards, and nothing anywhere would have said
     * no.
     */
    public function stored(Period $period): ?string
    {
        if ($period->isEmpty()) {
            return null;
        }

        if ($period->from === null) {
            \assert($period->until !== null);

            return self::OPEN . self::SEPARATOR . $this->write($period->until);
        }

        return $this->write($period->from)
            . self::SEPARATOR
            . ($period->until === null ? self::OPEN : $this->write($period->until));
    }

    /**
     * A moment, as this precision stores it.
     *
     * **Converted to UTC for a datetime and emphatically not for a date**, which
     * is the same split §8.4.4 makes and the same one
     * {@see \Xivi\Core\Field\Type\DateFieldType} makes by having no zone at all.
     * An instant is a point on the world's clock and UTC is where this engine
     * keeps those; a *day* is not an instant, and converting one would move a
     * birthday — or the start of a tenancy — across midnight for anybody whose
     * `\DateTime` happened to carry a zone east of Greenwich.
     */
    public function write(\DateTimeInterface $moment): string
    {
        $moment = \DateTimeImmutable::createFromInterface($moment);

        return match ($this) {
            self::Date => $moment->format($this->format()),
            self::DateTime => $moment->setTimezone(new \DateTimeZone('UTC'))->format($this->format()),
        };
    }
}

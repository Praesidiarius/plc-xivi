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

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\ExcludesOverlaps;
use Xivi\Core\Form\PeriodType;
use Xivi\Core\Period\ExclusiveWithin;
use Xivi\Core\Period\Period;
use Xivi\Core\Period\PeriodPrecision;
use Xivi\Core\Query\Operator;
use Xivi\Core\Time\ReaderTimezone;
use Xivi\Core\Validation\ValidPeriod;

/**
 * A period of time, held as one value (XIV-136).
 *
 * Everything both halves of this feature share; what the two concrete types add
 * is a {@see PeriodPrecision} and nothing else. See {@see Period} for the bound
 * and why it is exclusive, {@see PeriodPrecision} for why the precision is a type
 * rather than an option, and {@see \Xivi\Core\Record\OverlapExclusion} for the
 * half of this that lives in the database.
 *
 * ### Stored as one string, which is what kept the rest of the engine still
 *
 * `2026-08-01/2026-08-05`. The obvious alternative was a JSON object with two
 * keys and it was rejected on evidence rather than taste: a stored value that
 * stops being a scalar is a change to the spreadsheet export (which writes
 * cells), to the history diff (which compares stored values), to the importer
 * (which reads one column per field), to `IS NULL` filtering and to
 * `data ->> 'stay'` itself. As one ISO-8601 interval, none of those learned
 * anything — and it sorts by start date as text, which is the property
 * {@see DateFieldType} keeps a date in ISO for in the first place.
 *
 * ### `toStorage()` is the seam, and a lone date is a day
 *
 * The three callers that run every value through it before doing anything —
 * {@see \Xivi\Core\Validation\RecordValidator}, {@see \Xivi\Core\Record\RecordRepository}
 * and {@see \Xivi\Core\Query\QueryCompiler} — are why `2026-08-19` typed into a
 * filter box asks "which of these overlap that day" without the query compiler
 * knowing what a period is. A single date is read as the period of that one day,
 * at both precisions, because a day is the smallest thing anybody names in a
 * URL; a single *instant* is not read as anything, because a period of no length
 * overlaps nothing and would be a filter that silently found no records.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
abstract class PeriodFieldType implements ExcludesOverlaps
{
    /** What separates the two ends when a period is *shown*, as opposed to stored. */
    private const string DASH = ' – ';

    public function __construct(
        private readonly ReaderTimezone $reader,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** Days or moments — the one thing the two concrete types differ in. */
    abstract public function precision(): PeriodPrecision;

    public function constraints(FieldDefinition $field): array
    {
        // Validated as the stored form, like a date: by the time constraints run
        // toStorage() has turned whatever was submitted into the one spelling, or
        // left it alone for ValidPeriod to name.
        return [
            new Assert\Type('string'),
            new ValidPeriod(precision: $this->precision()),
        ];
    }

    /**
     * Whatever was submitted, imported, filtered by or handed over, as the one
     * stored spelling.
     *
     * Four shapes arrive here and each has a reason. A {@see Period} comes from
     * the form ({@see PeriodType} maps its three controls into one). An array
     * comes from anything assembling a record by hand — a module, a test, a
     * future API — and is accepted because `['from' => …, 'until' => …]` is the
     * obvious way to write one and refusing it would buy nothing. A string comes
     * from the importer, from a filter box and from a record read back out of
     * storage. Anything else is handed back untouched for the reason
     * {@see IntegerFieldType} gives about `"12abc"`: inventing a plausible value
     * nobody typed is worse than being refused.
     */
    public function toStorage(mixed $value, FieldDefinition $field): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (\is_array($value)) {
            return $this->fromParts($value);
        }

        if ($value instanceof Period) {
            return $this->precision()->stored($value);
        }

        if (!\is_string($value)) {
            return $value;
        }

        $value = trim($value);
        $period = $this->precision()->read($value);

        if ($period !== null) {
            // Already canonical — the common case, since this runs again on
            // every value read back out of storage.
            return $this->precision()->stored($period);
        }

        return $this->oneDay($value) ?? $value;
    }

    /** The stored string, as the pair — or null, which is a field nobody filled in. */
    public function fromStorage(mixed $value, FieldDefinition $field): ?Period
    {
        return \is_string($value) ? $this->precision()->read($value) : null;
    }

    public function formType(): string
    {
        return PeriodType::class;
    }

    /**
     * The two boxes and the checkbox that says the end is deliberate.
     *
     * The precision travels as an option rather than being read off the type,
     * because {@see PeriodType} is one form type serving both and a form that
     * asked the registry what kind of field it was drawing would be the branch
     * this design spent two classes avoiding.
     */
    public function formOptions(FieldDefinition $field): array
    {
        return [
            'precision' => $this->precision(),
            'view_timezone' => $this->reader->zone(),
            'translation_domain' => 'xivi',
        ];
    }

    /**
     * The period, written the way this reader reads one (XIV-50, [XIV-83]).
     *
     * Three shapes, and each is a decision:
     *
     *  * **`1.8.2026 – 5.8.2026`** for the ordinary case. The `until` is shown as
     *    it is stored — the day the period stops — rather than being turned back
     *    into "the last day inside", because two spellings of one value is how a
     *    boundary bug hides. The form is where that is explained
     *    ({@see PeriodType}), once, next to the box being typed into.
     *  * **`ab 1.8.2026`** — translated — for an open end, rather than a dash into
     *    nothing. A period that runs on is a sentence, and an em dash followed by
     *    a blank reads as a value somebody forgot to fill in, which is exactly the
     *    confusion open-endedness has to be told apart from.
     *  * **`1.8.2026, 09:00 – 11:00`** when a datetime period begins and ends on
     *    one day *in the reader's zone*. Which day that is comes from
     *    {@see ReaderTimezone}, so a booking stored `22:00Z–23:30Z` reads in
     *    Zurich as the next day's `00:00 – 01:30` and collapses there and not in
     *    Greenwich. Repeating the date would be correct and unreadable; getting
     *    the *zone* wrong would be neither.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        if (!$value instanceof Period || $value->isEmpty()) {
            return '';
        }

        \assert($value->from !== null);
        $zone = $this->precision() === PeriodPrecision::DateTime
            ? $this->reader->zone()
            : new \DateTimeZone('UTC');

        $from = $value->from->setTimezone($zone);

        if ($value->until === null) {
            return $this->translator->trans(
                'period.open_ended',
                ['%from%' => $this->moments($from, withDate: true)],
                'xivi',
            );
        }

        $until = $value->until->setTimezone($zone);
        $sameDay = $from->format('Y-m-d') === $until->format('Y-m-d');

        return $this->moments($from, withDate: true)
            . self::DASH
            . $this->moments($until, withDate: !$sameDay);
    }

    /**
     * Overlap, and whether there is anything here at all.
     *
     * **Nothing else, and the omissions are the interesting part.** `Equals` on a
     * period is asking whether two stays are the identical stay, which nobody has
     * ever wanted to know; `GreaterThan` would have to mean "starts after" or
     * "ends after" and the compiler has no way to say which. What people actually
     * ask of a period is "is this thing free then" — one question, one operator,
     * answered in the database ({@see Operator::Overlaps}).
     */
    public function operators(): array
    {
        return [
            Operator::Overlaps,
            Operator::IsEmpty,
            Operator::IsNotEmpty,
        ];
    }

    /**
     * The stored string, as a Postgres range — the whole of {@see ExcludesOverlaps}'
     * added contract.
     *
     * `STRICT` on the function is what makes a row with no period come back NULL
     * rather than an unbounded range, and the regular expression inside it is
     * what makes a malformed one come back NULL rather than raising and taking
     * the whole list with it ({@see \Xivi\Core\Period\PeriodSql}).
     */
    public function comparableSql(string $accessor): string
    {
        return sprintf('%s(%s)', $this->precision()->rangeFunction(), $accessor);
    }

    /**
     * A plausible period, and — this is the load-bearing part — one that cannot
     * collide with the ones on either side of it (§5.17, XIV-24).
     *
     * **A generator that draws overlapping periods breaks `tenant:reset` part
     * way**, because the demo data is written in batches inside a transaction and
     * an exclusion constraint refuses the whole batch. `PhoneFieldType` has the
     * same obligation for a different reason — fifty thousand records drawn from
     * one example number collide on a unique index — and the answer is the same
     * one: **use the sequence**. Each record gets its own week (or its own pair of
     * hours), so no two generated periods share a moment whatever scope they land
     * in, and the constraint is never the thing that decides whether a demo tenant
     * builds.
     *
     * **Never open-ended on an exclusive field**, for the same reason: a period
     * with no end covers every later week, so one of them would refuse every
     * record generated after it. On a field with no constraint they turn up about
     * one time in seventeen, because a tenancy nobody has ended is a case the
     * pages have to be seen rendering.
     */
    public function sample(FieldDefinition $field, int $sequence): ?string
    {
        if (!$field->isRequired() && mt_rand(1, 10) === 1) {
            return null;
        }

        $precision = $this->precision();
        $start = $precision === PeriodPrecision::DateTime
            ? new \DateTimeImmutable(sprintf('today 08:00 +%d hours', $sequence * 3), new \DateTimeZone('UTC'))
            : new \DateTimeImmutable(sprintf('today +%d days', $sequence * 7), new \DateTimeZone('UTC'));

        if (ExclusiveWithin::of($field) === null && $sequence % 17 === 0) {
            return $precision->stored(new Period($start));
        }

        $length = $precision === PeriodPrecision::DateTime
            ? sprintf('+%d hours', mt_rand(1, 2))
            : sprintf('+%d days', mt_rand(1, 6));

        return $precision->stored(new Period($start, $start->modify($length)));
    }

    /**
     * Half a row for days, two thirds for moments.
     *
     * Two date boxes and a checkbox do not fit where one date does, and a
     * datetime box is half as wide again as a date one. Both leave room for
     * something beside them, which is what a period usually has: the room, the
     * resident, the machine.
     */
    public function defaultWidth(): int
    {
        return $this->precision() === PeriodPrecision::DateTime ? 8 : 6;
    }

    /**
     * One end of the period, in the locale's own short forms.
     *
     * `self::FORMAT`-style constants are deliberately absent, for the reason
     * {@see DateFieldType::display()} sets out at length: the stored spelling is
     * ISO because ISO sorts, and localising by reaching for it would localise
     * what goes in the database. The year is widened out of the short pattern for
     * the same reason it is there — `15.08.26` is a date somebody has to think
     * about.
     */
    private function moments(\DateTimeImmutable $moment, bool $withDate): string
    {
        $time = $this->precision() === PeriodPrecision::DateTime
            ? \IntlDateFormatter::SHORT
            : \IntlDateFormatter::NONE;

        $formatter = new \IntlDateFormatter(
            \Locale::getDefault(),
            $withDate ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE,
            $time,
            $moment->getTimezone(),
        );

        if ($withDate) {
            $formatter->setPattern(preg_replace('/y+/', 'yyyy', (string) $formatter->getPattern()) ?? '');
        }

        return $formatter->format($moment) ?: $moment->format($this->precision()->format());
    }

    /**
     * The form's three controls — or any caller's two keys — as one stored value.
     *
     * **The one place "no end, and nobody said so" is turned into a refusal.**
     * {@see PeriodType} maps its controls to an array rather than a
     * {@see Period} precisely because a `Period` cannot hold that state: both the
     * deliberate open end and the forgotten one are `until === null`. Here the
     * two part company, and the second becomes `2026-08-01/` — a value that is
     * not storable and is not silently dropped either, so
     * {@see \Xivi\Core\Validation\ValidPeriodValidator} can say *fill it in or
     * tick the box* rather than the engine choosing one of them.
     *
     * A caller that does not mention the flag at all — a module, a test, an
     * importer building `['from' => …]` — means an open period, which is the only
     * sensible reading of a pair with one half. It is the *form* that has a third
     * state, because a form has a person in front of it.
     *
     * @param array<string, mixed> $parts
     */
    private function fromParts(array $parts): ?string
    {
        $from = self::moment($parts[PeriodType::FROM] ?? null);
        $until = self::moment($parts[PeriodType::UNTIL] ?? null);
        $open = \array_key_exists(PeriodType::OPEN_ENDED, $parts)
            ? (bool) $parts[PeriodType::OPEN_ENDED]
            : true;

        if ($from === null && $until === null) {
            // An empty field, whatever the checkbox says. Ticking "no end date"
            // and typing nothing at all is not a period that runs for ever; it is
            // a field somebody left alone.
            return null;
        }

        if ($from !== null && $until === null && !$open) {
            return $this->precision()->write($from) . PeriodPrecision::SEPARATOR;
        }

        return $this->precision()->stored(new Period($from, $until));
    }

    /**
     * A lone date, as the period of that one day.
     *
     * What makes `?filter[0][op]=overlaps&filter[0][value]=2026-08-19` — "which of
     * these overlap today" — a URL somebody can type, and what a date input in the
     * filter bar submits without anything having to translate it.
     */
    private function oneDay(string $value): ?string
    {
        $day = PeriodPrecision::Date->parse($value);

        if ($day === null) {
            return null;
        }

        return $this->precision()->stored(new Period($day, $day->modify('+1 day')));
    }

    /** A form's, an array's or a caller's idea of a moment, as one this can store. */
    private static function moment(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC'));
        } catch (\Exception) {
            // Unreadable rather than fatal: what comes back is a period missing
            // that end, which the validator refuses by name.
            return null;
        }
    }
}

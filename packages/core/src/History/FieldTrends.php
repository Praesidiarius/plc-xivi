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

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\LinksToRecord;
use Xivi\Core\Record\Record;

/**
 * A record's numeric fields, as lines rather than as a list of edits (XIV-121).
 *
 * ## The finding this is built on
 *
 * The ticket opened by asking whether history already answers "what was this
 * article's price in March", because the answer decides whether this is a query
 * or a storage design. **It does.** §5.2 stores the values and not merely the
 * fact of a change — `{"price": {"label": "Price", "from": "100.00", "to":
 * "120.00"}}` — the `created` entry records every field the record was born with
 * as a change from null, `RecordWriter` is the only supported way to write a
 * record so there is no path that skips it, and nothing prunes the table: §5.2
 * lists retention as still to decide, and until somebody decides it the chain
 * from creation to today is unbroken. So there is no series to store. Storing
 * one would have meant a second copy of facts already recorded, kept in step by
 * hand, and the first time the two disagreed nobody would know which was right.
 *
 * ## Why this is not hard-coded to `price`
 *
 * A price over time is the trend somebody asked for; it is not a special kind of
 * fact. A quantity, a stock level, a rate and a discount are the same question
 * with a different label on the axis, and building "plot the history of *this
 * numeric field*" costs the same as building "plot the price" — one parameter
 * instead of one constant. So this takes a module and a record and answers for
 * every field it can, and the article module contains not one line about charts.
 *
 * ## What counts as numeric, and why nothing is declared
 *
 * **A field is plottable when its recorded values are numbers.** Not when a
 * blueprint says so, and not by a switch on field type — §5 has one rule about
 * that and it is that the engine must not grow one. Two consequences worth
 * having: a field type added later is plottable the day it stores numbers,
 * without anybody remembering to declare it; and a *customer's own* field —
 * §6.1's whole point is that the definitions are theirs — gets a trend without a
 * deploy.
 *
 * **The one exclusion is a reference**, and it is asked rather than assumed: a
 * reference stores the id of another record, which is a number that means
 * nothing on an axis, and a field type that names another record already says so
 * by implementing {@see LinksToRecord} (XIV-42). That is a capability the engine
 * can ask about, which is the difference between this and the `instanceof` chain
 * §5.4 spent a ticket removing from the field editor.
 *
 * **What is deliberately not excluded** is a `choice` field somebody has filled
 * with bare numbers. It would be offered, and its line would be a plot of
 * exactly what changed, which is not wrong so much as pointless. Over-including
 * is cheap here because the reader picks which field is drawn: a candidate
 * nobody wants costs one entry in a list, where a *missing* candidate costs a
 * ticket. Guessing that direction wrong is the more expensive mistake.
 *
 * ## What one read costs
 *
 * One query for the record, whatever its shape: every field's line is built from
 * the same rows in one pass, because a query per numeric field would make a page
 * cost what a customer's catalogue happens to be shaped like. It is capped at
 * {@see self::MOST} entries and the cap bites at the far past, which is stated on
 * the trend rather than silently absorbed — see {@see FieldTrend::$truncated}.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class FieldTrends
{
    /**
     * How much of a record's timeline one page of charts will read.
     *
     * **A bound on a table with no bound of its own.** A record's history is the
     * one part of it that grows without limit (§5.2), and unlike the timeline
     * card — which shows the newest five whatever the total — a trend genuinely
     * wants the whole thing, because the old end is where the shape is. So the
     * limit is set where it stops being a reading rather than where it stops
     * being cheap: five hundred changes drawn across a card is a solid block of
     * ink, and nobody reads the four hundredth step. A record past it is a record
     * whose chart says so.
     */
    public const int MOST = 500;

    public function __construct(
        private HistoryRepository $history,
        private FieldTypeRegistry $fieldTypes,
    ) {
    }

    /**
     * Every field of this record that has a line worth drawing, in the module's
     * own field order.
     *
     * Keyed by field key because the caller's next question is always "the one
     * called this", and ordered by position because the order fields are edited
     * and read in is the order the customer arranged, and a second opinion about
     * it would be a second thing to keep in step.
     *
     * @return array<string, FieldTrend>
     */
    public function forRecord(ModuleDefinition $module, Record $record, ?\DateTimeImmutable $now = null): array
    {
        if ($record->id === null) {
            return [];
        }

        // **Before the query, not after it.** A contact has no numeric field at
        // all, so asking its history what it holds would be a read of a growing
        // table to find out something the definitions already know.
        $candidates = $this->candidates($module);

        if ($candidates === []) {
            return [];
        }

        $now ??= new \DateTimeImmutable();

        // One more than the cap, which is how the caller finds out it hit it
        // without a second COUNT over the same rows: if the extra row came back,
        // there is at least one more entry beyond the window.
        $rows = $this->history->fieldChangesFor($module, $record->id, self::MOST + 1);
        $truncated = \count($rows) > self::MOST;

        if ($truncated) {
            // The oldest, since the rows arrive oldest first and it is the far
            // past the cap is meant to drop.
            array_shift($rows);
        }

        $trends = [];

        foreach ($candidates as $field) {
            $trend = $this->trendOf($field, $rows, $record, $now, $truncated);

            if ($trend !== null) {
                $trends[$field->getKey()] = $trend;
            }
        }

        return $trends;
    }

    /**
     * Which of a module's fields could carry a line at all, before any record's
     * values are looked at.
     *
     * **Public because "is there anything to draw here" is worth asking without
     * paying for the answer.** The card that draws these is mounted on every
     * record page in the installation, and on a module with no numbers on it the
     * honest answer costs one read of definitions that are already cached for
     * the request (XIV-53) and no round trip at all. A caller that skipped this
     * and let {@see self::forRecord()} return an empty array would get the same
     * result, having first fetched a record it had no use for.
     *
     * @return list<FieldDefinition>
     */
    public function candidates(ModuleDefinition $module): array
    {
        $candidates = [];

        foreach ($module->getFields() as $field) {
            if ($this->isPlottable($field)) {
                $candidates[] = $field;
            }
        }

        return $candidates;
    }

    /**
     * Whether a field could carry a line at all, before its values are looked at.
     *
     * Only the reference exclusion, for the reason in the class docblock. A type
     * this build does not know — a definition row left behind by a package that
     * has been removed — is not plottable rather than fatal: this is a card
     * beside a record, and a chart is not worth taking a record page down for.
     * {@see FieldTypeRegistry::get()} is deliberately fatal for the paths where
     * the answer decides how stored data is interpreted; this is not one of them.
     */
    private function isPlottable(FieldDefinition $field): bool
    {
        if (!$this->fieldTypes->has($field->getType())) {
            return false;
        }

        return !$this->fieldTypes->get($field->getType()) instanceof LinksToRecord;
    }

    /**
     * One field's line, or null when there is nothing numeric to draw.
     *
     * ### The shape of the series
     *
     * A point says "from this moment, this value" ({@see TrendPoint}), so the
     * events are the recorded `to`s and the segments between them are flat. Two
     * points are added that are not events, and both are there because leaving
     * them out makes the drawing say something false:
     *
     *  * **The value before the first recorded change**, when there is one. It
     *    is drawn at the record's creation, because that is the earliest moment
     *    the value provably held — an entry saying `from: "100.00"` is an entry
     *    saying it had been 100.00 for some time. Skipped when the read was
     *    truncated: there the first entry seen is *not* the first there was, so
     *    stretching its `from` back to the record's birth would be an invention.
     *  * **The current value, at the moment this is read.** A line that stopped
     *    at the last change leaves the reader to infer from an absence that the
     *    last value is still in force. It comes from the record rather than from
     *    the last entry, because the record is the authority on what is true now
     *    and the two can only disagree if something wrote around
     *    `RecordWriter` — in which case the record is still the one to believe.
     *
     * ### Emptying a numeric field
     *
     * A field cleared to nothing has no place on a numeric axis, so a change with
     * a non-numeric `to` contributes a point at the *old* value: it held that
     * value right up to the instant it was emptied, which is exactly true, and
     * the line then stops there rather than running on to today. It still counts
     * as a change. If the field is later filled again the line resumes, and the
     * gap in between is drawn as though the old value had held across it — the
     * one simplification here, accepted because the alternative is a broken line
     * with a hole in it that reads as a rendering fault, and because a numeric
     * field that is emptied and refilled is a rarer thing than one that is not.
     *
     * @param list<array{occurredAt: \DateTimeImmutable, fields: array<string, mixed>}> $rows
     */
    private function trendOf(
        FieldDefinition $field,
        array $rows,
        Record $record,
        \DateTimeImmutable $now,
        bool $truncated,
    ): ?FieldTrend {
        $key = $field->getKey();
        $current = $record->get($key);

        $points = [];
        $changes = 0;
        $held = null;
        $first = true;

        foreach ($rows as $row) {
            $change = $row['fields'][$key] ?? null;

            if (!\is_array($change)) {
                continue;
            }

            $from = $change['from'] ?? null;
            $to = $change['to'] ?? null;

            if ($from !== null) {
                ++$changes;
            }

            // The run before the first recorded change, given width by drawing
            // it from the record's creation. See the docblock above for why this
            // is skipped on a truncated read.
            if ($first && !$truncated && is_numeric($from)) {
                $points[] = new TrendPoint($this->birthOf($record, $row['occurredAt']), (float) $from);
            }

            $first = false;

            if (is_numeric($to)) {
                $points[] = new TrendPoint($row['occurredAt'], (float) $to);
                $held = (float) $to;

                continue;
            }

            // Emptied. It held its last value up to this instant and nothing
            // after it, which is what this point and the absence of a further
            // one say together.
            if ($held !== null) {
                $points[] = new TrendPoint($row['occurredAt'], $held);
                $held = null;
            }
        }

        if ($points === []) {
            // Nothing in the window mentions this field. It still has a line if
            // it has a value — a record nobody has ever edited is the ordinary
            // case, and "this has been 100.00 since it was made" is a better
            // answer than no card at all.
            return is_numeric($current)
                ? new FieldTrend($field, [
                    new TrendPoint($record->createdAt ?? $now, (float) $current),
                    new TrendPoint($now, (float) $current),
                ], 0, $truncated)
                : null;
        }

        if ($held !== null && is_numeric($current)) {
            $points[] = new TrendPoint($now, (float) $current);
        }

        return new FieldTrend($field, $points, $changes, $truncated);
    }

    /**
     * The earliest moment a value can honestly be drawn from.
     *
     * The record's own creation, except when the timeline disagrees with it. A
     * backfilling import writes entries with older timestamps than the row it
     * writes them about (§5.2 says so about ordering, and the same fact bites
     * here), and a line that started *after* its own first event would draw
     * backwards.
     */
    private function birthOf(Record $record, \DateTimeImmutable $firstChange): \DateTimeImmutable
    {
        $created = $record->createdAt;

        return $created !== null && $created < $firstChange ? $created : $firstChange;
    }
}

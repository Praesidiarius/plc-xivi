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

/**
 * What one numeric field of one record has been worth, over time (XIV-121).
 *
 * **Read out of history rather than out of a series table**, which is the whole
 * finding of the ticket and is worth stating on the class that carries the
 * result. §5.2 records the values themselves — `{"price": {"from": "100.00",
 * "to": "120.00"}}` — and not merely the fact that something changed, and the
 * `created` entry carries every field the record was born with as a change from
 * null. So the chain of `from`/`to` is continuous from the day a record was made
 * to the day it is read, nothing prunes it, and "what was this article's price in
 * March" is a question the database can already answer. A second table holding a
 * price series would have been a second copy of facts that are already recorded,
 * with the usual consequence: two answers, and a day spent working out which one
 * is right.
 *
 * **The last point is now, and it is not a recorded event.** A line that stopped
 * at the last change would read as though the price ended there — the reader has
 * to work out, from the absence of anything after it, that the last value is
 * still the current one. Carrying the last value forward to the moment the page
 * is drawn says it instead. It is honest because it is not a claim about the
 * past: the value has not changed since, or there would be an entry saying so.
 *
 * **A record whose field never changed still has a trend, and it is flat.** That
 * is the degenerate case the ticket asks about, and drawing it is a better answer
 * than hiding it: "this has been 100.00 since the day it was made" is a real
 * answer to "what was this in March", where an empty box is a question about
 * whether the feature is broken. {@see self::isFlat()} exists so the template can
 * *say* it in words as well as draw it, because a horizontal line and a chart
 * that failed to load look alike at a glance.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class FieldTrend
{
    /**
     * @param non-empty-list<TrendPoint> $points    in ascending time order, always at least
     *                                              two: the value's first known moment and
     *                                              the moment this was read
     * @param int                        $changes   how many recorded changes are behind it,
     *                                              which is one less than the number of
     *                                              *events* in it and is the number worth
     *                                              printing — "changed 4 times" rather than
     *                                              "5 points"
     * @param bool                       $truncated whether older entries exist that this
     *                                              did not read. The line then begins at the
     *                                              oldest value it could see rather than at
     *                                              the record's creation, and a card that
     *                                              did not say so would be claiming the
     *                                              record was born at that price
     */
    public function __construct(
        public FieldDefinition $field,
        /** @var non-empty-list<TrendPoint> */
        public array $points,
        public int $changes,
        public bool $truncated = false,
    ) {
    }

    /** Whether nothing was ever changed here: one value, held throughout. */
    public function isFlat(): bool
    {
        return $this->changes === 0;
    }

    public function first(): TrendPoint
    {
        return $this->points[0];
    }

    public function last(): TrendPoint
    {
        return $this->points[\count($this->points) - 1];
    }

    /**
     * The lowest and highest it has been.
     *
     * Here rather than in the template because a template that computed them
     * would compute them twice, and because "the range this has moved through" is
     * a fact about the series rather than about how it is drawn — a chart wants
     * it for an axis, and a sentence beside the chart wants it for a sentence.
     *
     * @return array{float, float}
     */
    public function extent(): array
    {
        $values = array_map(static fn (TrendPoint $point): float => $point->value, $this->points);

        return [min($values), max($values)];
    }
}

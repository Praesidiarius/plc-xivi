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

namespace Xivi\Core\Record;

/**
 * What a save is about to write, while it can still be added to (XIV-16).
 *
 * Handed to each {@see ValueDeriver} inside the save's transaction and before
 * anything has been written or compared, so what a deriver puts here is what
 * lands in the table, what the history entry describes, and what the next reader
 * sees. There is no second pass and no cache to invalidate.
 *
 * **Rows are here as well as fields**, because a derived value is not only a
 * header thing: an order line's total is derived, and so is a subtotal line,
 * which is derived from the rows *above* it. They arrive in the order they will
 * be stored in (XIV-21), which is what makes "the lines since the previous
 * subtotal" a thing that can be computed at all.
 *
 * **A collection missing from `rows` is one this save is not touching.** The
 * distinction matters: an empty array means "no rows", which deletes what is
 * there, and a save that only edits the header must not do that. A deriver
 * filling in a collection nobody submitted — a tax breakdown, say — adds the key
 * and the writer then owns those rows.
 *
 * Mutable, unlike most things here, and that is the point: it is the one object
 * in a save whose job is to be added to.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class Derivation
{
    /**
     * @param array<string, mixed>                                                 $fields the record's own values
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $rows   keyed by collection
     */
    public function __construct(
        public array $fields = [],
        public array $rows = [],
    ) {
    }

    /**
     * The rows of one collection, or none — so a deriver reading a collection
     * this save left alone gets an empty list rather than an undefined index.
     *
     * @return list<array{id: int|null, data: array<string, mixed>}>
     */
    public function rowsOf(string $collection): array
    {
        return $this->rows[$collection] ?? [];
    }

    /** @param list<array{id: int|null, data: array<string, mixed>}> $rows */
    public function setRows(string $collection, array $rows): void
    {
        $this->rows[$collection] = $rows;
    }
}

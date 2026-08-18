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

namespace Xivi\Core\Lifecycle;

use Xivi\Core\Record\Record;

/**
 * What a {@see TransitionGuard} is handed: the record, and its rows if it asks
 * for them (XIV-110).
 *
 * **The rows are the reason this class exists at all.** The record on its own
 * would have been enough for a guard about a header field, and a guard about a
 * header field is not the one anybody needed — "an order needs at least one
 * line" is a question about a collection, and a collection is not in the
 * record's `data`. Handing the guard a `Record` and letting it go and find the
 * rows itself would mean every module's guard knowing about the metadata
 * repository and the record repository, which is the engine's business and not a
 * declaration's.
 *
 * **Lazy, and memoised, and both halves are load-bearing.** Lazy, because a
 * transition with no guard and a guard that only reads a header field must cost
 * nothing: the query is not made until somebody calls {@see self::rows()}.
 * Memoised, because a record page asks the same predicate about the same record
 * more than once — once to decide whether to draw the button, once if the POST
 * arrives — and within a single ask, a lifecycle with three guarded transitions
 * would otherwise run the same `SELECT` three times. One object per ask, one
 * query per collection asked about.
 *
 * **What that costs, stated rather than assumed** (§5.1, XIV-54). The list page
 * is where this would have been expensive, and the list page does not ask: only
 * the record page consults a lifecycle at all, and it consults it about the one
 * record it is showing. So the whole bill for a guard that reads rows is one
 * query on a page that is already loading those same rows to draw them — not one
 * per row of a list, which is the shape §5.1 and XIV-54 are both about. If a
 * list page ever wants to draw transition buttons per row, this is the note that
 * says what has to change first: the rows would have to be primed for the page
 * the way {@see \Xivi\Core\Record\RecordPrimer} primes references, and a guard
 * evaluated inside a `LIMIT` is the wrong side of §8.4's line in any case.
 *
 * The record page already holds the rows it drew, and could have handed them
 * over instead. It deliberately does not: a second way in is a second thing to
 * keep true, and a guard that behaves differently depending on whether its
 * caller remembered to prime it is a bug waiting for a quiet afternoon. One
 * query is the price of one answer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class GuardedRecord
{
    /** @var array<string, list<Record>> */
    private array $loaded = [];

    /**
     * @param \Closure(string): list<Record> $rows what one of the record's collections holds
     */
    public function __construct(
        public readonly Record $record,
        private readonly \Closure $rows,
    ) {
    }

    /** A value of the record itself, the same way anything else reads one. */
    public function get(string $field): mixed
    {
        return $this->record->get($field);
    }

    /**
     * The rows of one of the record's collections, read once.
     *
     * An unsaved record has none, which is the truthful answer rather than a
     * special case: nothing has been written, so there is nothing belonging to
     * it. In practice a transition is always taken against a record that exists
     * — it has its own route and its own id — and this is here so that a guard
     * unit-tested against a fresh {@see Record} gets an empty list instead of a
     * query with a null parent.
     *
     * A collection the module does not declare is also empty rather than an
     * error. The customer's definitions are the truth (§6.1), and a module whose
     * blueprint names a collection the tenant has since renamed should refuse
     * nothing on those grounds — the guard's own condition is what decides,
     * against what is actually there.
     *
     * @return list<Record>
     */
    public function rows(string $collection): array
    {
        return $this->loaded[$collection] ??= ($this->rows)($collection);
    }
}

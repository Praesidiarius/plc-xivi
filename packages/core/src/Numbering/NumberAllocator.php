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

use Doctrine\DBAL\Connection;

/**
 * The next number out of one counter (XIV-15).
 *
 * **One statement, and the database does the hard part.** Read-then-increment in
 * PHP is the textbook race: two requests read 41, both write 42, and two
 * invoices carry the same number — which is the one bug in this feature that
 * cannot be cleaned up afterwards, because both documents may already have been
 * sent.
 *
 * `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` is atomic. The first caller
 * for a counter inserts it; every caller after that collides with the unique
 * index, is turned into an update of that row, and waits on the row lock the
 * database took. Nothing here needs a `SELECT FOR UPDATE`, a retry loop or an
 * advisory lock, and there is no window between reading and writing for anything
 * to happen in.
 *
 * **Inside the caller's transaction**, which is the deliberate half of the
 * design: {@see \Xivi\Core\Record\RecordWriter} allocates while saving, so a
 * save that fails gives its number back. The cost is that the row stays locked
 * until that transaction ends, so two people creating an order at the same
 * moment take turns. For a table written once per document that is the right way
 * round — correctness bought with a lock nobody will ever notice.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class NumberAllocator
{
    /**
     * What a counter nobody has drawn from yet will give out.
     *
     * A counter is a row that does not exist until the first allocation inserts
     * it, so "the counter is at 1" and "there is no counter" are the same
     * statement about the world, and the reader of a numbering page should not
     * have to know which one they are looking at.
     */
    public const int FIRST = 1;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string $shape  the module the record belongs to
     * @param string $field  the field being numbered — two numbered fields on one
     *                       module count separately
     * @param string $period the year, or the empty string for a sequence that
     *                       never resets ({@see NumberFormat::period()})
     */
    public function next(string $shape, string $field, string $period): int
    {
        // Two on insert and minus one on the way out, so that the first number a
        // counter ever gives is 1 and the column always holds what the *next*
        // caller will get. Writing it the other way round needs a second
        // statement to initialise the row.
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO number_sequence (shape_key, field_key, period, next_value)
                VALUES (:shape, :field, :period, 2)
                ON CONFLICT (shape_key, field_key, period)
                DO UPDATE SET next_value = number_sequence.next_value + 1
                RETURNING next_value - 1
                SQL,
            ['shape' => $shape, 'field' => $field, 'period' => $period],
        );
    }

    /**
     * What this counter will give out next, without taking it (XIV-27).
     *
     * The numbering editor needs this to show what the next number will look
     * like, and showing somebody a preview must not cost them a number — a page
     * that allocated as it rendered would leave a gap in the books every time an
     * administrator opened it and changed their mind.
     *
     * A plain read, so it is a snapshot and not a promise: somebody saving a
     * record in the next second takes the number this just showed. That is the
     * right level of honesty for a preview, and it is why the guard below is
     * written as one statement in the database rather than as a check against
     * whatever this returned.
     */
    public function peek(string $shape, string $field, string $period): int
    {
        $next = $this->connection->fetchOne(
            'SELECT next_value FROM number_sequence WHERE shape_key = :shape AND field_key = :field AND period = :period',
            ['shape' => $shape, 'field' => $field, 'period' => $period],
        );

        return $next === false ? self::FIRST : (int) $next;
    }

    /**
     * Move a counter forward to where a customer's old system left off (XIV-27).
     *
     * The reason this exists: somebody migrating from another system arrives
     * mid-sequence, and their next invoice has to be 1043 rather than 1. Without
     * it, numbering is a feature that can only be adopted by a business on its
     * first day of trading.
     *
     * **It is also the one control in this feature that can produce a duplicate
     * number**, which is a legal problem rather than a cosmetic one — two
     * invoices carrying 1042 is not something an apology fixes. So it only ever
     * moves *forward*: a value at or below one already given out is refused, and
     * setting it to exactly where the counter already stands is the no-op it
     * reads as.
     *
     * **The refusal is the statement, not a check in front of one.** Reading the
     * counter in PHP and then writing it back is the same read-then-write race
     * the allocation above was written to avoid, and it would lose in the way
     * that matters: a save landing in between would consume the number this
     * decided was still free. `ON CONFLICT DO UPDATE ... WHERE` puts the
     * condition inside the single statement that takes the row lock, so an
     * allocation either happened before this — and is therefore accounted for —
     * or waits for it. No rows come back when the condition fails, which is how
     * the caller learns it was refused; there is nothing to interpret and no
     * window to interpret it in.
     *
     * Note what this deliberately cannot see: values a person typed into the
     * field by hand before it was numbered. The counter knows what the counter
     * gave out and nothing else, which is one of the reasons making an already
     * populated field numbered is a separate question (§5.10).
     *
     * @param int $next the number the counter should give out next
     *
     * @throws CounterRefused when that number, or one below it, is already on a record
     */
    public function restartAt(string $shape, string $field, string $period, int $next): void
    {
        if ($next < self::FIRST) {
            throw CounterRefused::cannotGoBack($period, $this->peek($shape, $field, $period), $next);
        }

        $set = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO number_sequence (shape_key, field_key, period, next_value)
                VALUES (:shape, :field, :period, :next)
                ON CONFLICT (shape_key, field_key, period)
                DO UPDATE SET next_value = :next
                WHERE number_sequence.next_value <= :next
                RETURNING next_value
                SQL,
            ['shape' => $shape, 'field' => $field, 'period' => $period, 'next' => $next],
        );

        if ($set === false) {
            // The read is only for the message, and only on the path that is
            // already failing: it says what the counter is at, which is the one
            // thing somebody who has just been refused needs in order to type a
            // number that will be accepted.
            throw CounterRefused::cannotGoBack($period, $this->peek($shape, $field, $period), $next);
        }
    }
}

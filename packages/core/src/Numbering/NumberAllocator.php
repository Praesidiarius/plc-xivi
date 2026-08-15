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
}

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

namespace Xivi\Voucher\Redemption;

use Doctrine\DBAL\Connection;

/**
 * How many times a voucher has been used, and the statement that will not let it
 * be used once too often (XIV-103).
 *
 * ### A redemption is an allocation
 *
 * That sentence is the whole design. Taking the last use of a voucher is the
 * same act as taking the next invoice number: a shared counter moves, exactly
 * one caller may have each value, and two callers arriving in the same
 * millisecond must not both be told yes. §5.10 solved it once for document
 * numbers and this is the same solution with a ceiling on it — see
 * {@see \Xivi\Core\Numbering\NumberAllocator}, which this class is deliberately
 * shaped like.
 *
 * The bug being designed out is the textbook one. Read the count, compare it to
 * the limit in PHP, write the count back: under Postgres' READ COMMITTED — the
 * default, and what this application runs on — two requests both read 4 against
 * a limit of 5, both find room, and both write 5. A voucher good for five orders
 * has been used six times, and the sixth is a discount somebody was given for
 * nothing. It needs no unusual conditions; two people checking out at the same
 * moment is the entire reproduction, and
 * `tests/Functional/Engine/VoucherRedemptionRaceTest.php` performs it rather
 * than arguing about it.
 *
 * **The condition is inside the statement that takes the row lock.** There is no
 * `SELECT FOR UPDATE`, no advisory lock, no retry loop, and — critically — no
 * window between the check and the write for a second request to be in. The
 * first caller for a voucher inserts the row; every caller after that collides
 * with the unique index, is turned into an update, and waits on the lock
 * Postgres has already taken. When the limit is reached the `WHERE` fails, no
 * row comes back, and that absence *is* the refusal.
 *
 * ### Where the count lives, and why it is not on the record
 *
 * Not a field in the voucher's JSONB payload, and this is the decision worth
 * reading. A record is written by {@see \Xivi\Core\Record\RecordWriter} as **one
 * unit of work** (§5.2): the whole `data` document is replaced by a single
 * `UPDATE … SET data = :data`, with a history entry beside it. Two redemptions
 * of one voucher through that path are two whole-document writes, so the second
 * overwrites the first's count with a number it read before the first happened —
 * the same race, now wearing the engine's clothes, and with no `WHERE` available
 * to put the limit in because the statement is about a document rather than
 * about a counter.
 *
 * Three further things follow from the counter being its own table, and all
 * three are reasons rather than consequences:
 *
 * - **It can carry the guard.** `ON CONFLICT … DO UPDATE … WHERE` needs a
 *   conflict target and a column to compare, which is to say it needs a row that
 *   is *about the count*. A JSONB document has neither.
 * - **It is not the customer's field.** A redemption count is engine bookkeeping,
 *   like `position` on a collection row (§5.1) and like `number_sequence`. Nobody
 *   should be able to rename it, delete it in the metadata editor, type over it
 *   in a form, or import a spreadsheet that sets it to zero — and every one of
 *   those is possible for a field, because a field is the customer's.
 * - **It does not stamp the voucher as edited.** Redeeming a voucher is not a
 *   change to the voucher. Through the record writer it would bump `updated_at`
 *   and write a history entry every time somebody checked out, which is XIV-91's
 *   argument about the numbering backfill applied to a hotter path.
 *
 * Reusing `number_sequence` itself — shape `voucher`, field `redemptions`, period
 * = the record id — was considered and rejected. The table is already in every
 * tenant with the right index, and the ergonomics are almost free, but its column
 * is called `next_value` and its rows mean "what this counter will give out
 * next". A row there meaning "how many times this has been used" would be legible
 * only to whoever wrote it, and `period` would be holding a record id in a column
 * documented as a year. One table, one meaning.
 *
 * ### Inside the caller's transaction
 *
 * Like {@see \Xivi\Core\Numbering\NumberAllocator}, and for the same reason: a
 * checkout that fails after redeeming gives the redemption back, because the row
 * lock and the increment both belong to the transaction that failed. Nothing has
 * to remember to undo anything, which is what makes it correct rather than
 * merely tidy. The cost is that the row stays locked until that transaction
 * ends, so two orders redeeming the same voucher at the same moment take turns —
 * for a row touched once per checkout that is the right way round.
 *
 * **Applying a voucher to an order is [XIV-104] and is not here.** This class is
 * the counter and its rule; what a redemption *does* to a document is a separate
 * ticket, and the seam between them is one method call.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class VoucherRedemptions
{
    /**
     * What "unlimited" is, and it is not a number.
     *
     * Null, in the record's field and in this method's argument, all the way down
     * to `CAST(:limit AS INT) IS NULL` in the SQL. The alternative — 0, or -1, or
     * a very large number — is the one this codebase should never take: a
     * sentinel is a value arithmetic will happily compare against, so
     * `count < 999999999` is *true* for reasons that have nothing to do with the
     * customer having asked for an unlimited voucher, and the day somebody sells
     * a billion of something the sentinel becomes a limit nobody set. Absence is
     * not a number, cannot be compared by accident, and forces every reader of
     * the rule to write the branch out — which is exactly what the `IS NULL` in
     * the statement below is.
     */
    public const ?int UNLIMITED = null;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Take one use of a voucher, or be refused.
     *
     * @param int      $voucherId the voucher record's own id, which is what a
     *                            counter row is keyed by. Not the code: a code is a
     *                            name the customer may edit, and a counter that
     *                            followed a rename would either lose its history or
     *                            adopt somebody else's
     * @param int|null $limit     how many redemptions this voucher allows in total,
     *                            or {@see UNLIMITED}. Read off the record by the
     *                            caller and passed *into* the statement rather than
     *                            compared outside it — see below for why that is not
     *                            the race it looks like
     *
     * @return int which redemption this one is, counting from one
     *
     * @throws VoucherExhausted when the limit is already reached
     */
    public function redeem(int $voucherId, ?int $limit): int
    {
        /*
         * **Two branches, both guarded, in one statement.**
         *
         * The `SELECT … WHERE` in place of `VALUES` is not decoration. Written
         * with `VALUES`, the insert branch is unconditional, so a voucher whose
         * limit is zero — a real thing to type into a form, and the state a
         * voucher is in while somebody is deciding — would be refused on every
         * redemption *except the first*, because the first has no row to conflict
         * with and therefore never reaches the `WHERE`. One statement with one
         * rule beats two rules that agree until they meet the edge.
         *
         * The casts are there because a null parameter arrives with no type and
         * Postgres cannot decide what `NULL < redeemed_count` is meant to be. DBAL
         * repeats a named parameter as many times as it appears, exactly as
         * `NumberAllocator::reserve()` relies on for `:count`.
         *
         * `RETURNING redeemed_count` hands back the value this caller owns: 1 on
         * the insert branch and the incremented count on the update branch. When
         * the guard fails no row is inserted and no row is updated, so nothing
         * comes back — which is how the refusal is learned, with nothing to
         * interpret and no window to interpret it in.
         */
        $taken = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO voucher_redemption (voucher_id, redeemed_count)
                SELECT CAST(:voucher AS INT), 1
                WHERE CAST(:limit AS INT) IS NULL OR CAST(:limit AS INT) >= 1
                ON CONFLICT (voucher_id)
                DO UPDATE SET redeemed_count = voucher_redemption.redeemed_count + 1
                WHERE CAST(:limit AS INT) IS NULL
                   OR voucher_redemption.redeemed_count < CAST(:limit AS INT)
                RETURNING redeemed_count
                SQL,
            ['voucher' => $voucherId, 'limit' => $limit],
        );

        if ($taken === false) {
            /*
             * The read is only for the message and only on the path that has
             * already failed — the same shape `NumberAllocator::restartAt()`
             * uses. It says how many times the voucher has been used, which is
             * the one thing somebody who has just been refused wants to know, and
             * it cannot make the refusal wrong: the guard already happened, in
             * the statement, and this is reporting on it rather than deciding it.
             */
            throw VoucherExhausted::after($voucherId, $this->countFor($voucherId), $limit);
        }

        return (int) $taken;
    }

    /**
     * Give a use back, because the document that took it stopped naming the
     * voucher (XIV-104).
     *
     * ### Why this exists at all, since it is not symmetrical with the guard
     *
     * [XIV-103] built one statement and said it should stay the only way a use is
     * *taken*, which it is: both callers of this feature go through
     * {@see redeem()}. Releasing is a different act and needs a statement of its
     * own, and the question worth answering is whether it should happen rather
     * than what it looks like.
     *
     * It should, and the argument is what the count is *for*. It answers "how
     * many times has this voucher been used", and a use is a document that
     * carries it — so a draft order that named `GIVE-10`, was saved, and then had
     * the voucher taken off again is not a use of it. Leaving the count up would
     * burn a single-use voucher on somebody's mistake, with no way for anybody in
     * the building to put it right: the count is engine bookkeeping and is
     * deliberately not a field a customer can edit (§5.19).
     *
     * So the invariant is stated once here and is worth holding onto: **the count
     * is the number of documents that carry the voucher.** Naming it adds one,
     * un-naming it takes one away, and deleting the document that named it takes
     * one away. A cancelled order is deliberately *not* one of those: it still
     * carries the voucher, it is a record of what happened (§5.8), and the
     * lifecycle has locked it so nobody can take the voucher off it either.
     *
     * ### The guard, which is smaller than the other one and for a smaller reason
     *
     * `redeemed_count > 0` and nothing else. There is no ceiling to check on the
     * way down and no `ON CONFLICT` to arrange: the row either exists, in which
     * case Postgres locks it exactly as it does for a redemption and two callers
     * take turns, or it does not, in which case there is nothing to give back and
     * no row is touched. The floor is there because a count below zero is a
     * number that could only be read as a lie about the future, and because a
     * caller releasing twice — a save that both changed the voucher and was
     * retried, say — must not be able to *create* uses.
     *
     * **Inside the caller's transaction**, like {@see redeem()}: a save that fails
     * after releasing keeps the use, because the row lock and the decrement both
     * belong to the transaction that failed.
     */
    public function release(int $voucherId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE voucher_redemption
                SET redeemed_count = redeemed_count - 1
                WHERE voucher_id = :voucher
                  AND redeemed_count > 0
                SQL,
            ['voucher' => $voucherId],
        );
    }

    /**
     * How many times this voucher has been used, without using it.
     *
     * A plain read, so it is a snapshot and not a promise: somebody redeeming in
     * the next millisecond changes it. That is the right level of honesty for a
     * number drawn on a page, and it is precisely why the guard above is a
     * `WHERE` inside a statement rather than a comparison against whatever this
     * returned.
     *
     * Zero for a voucher nobody has redeemed, because a counter row does not
     * exist until the first redemption inserts it — "never used" and "no row" are
     * the same statement about the world, and a caller should not have to know
     * which one it is looking at. {@see \Xivi\Core\Numbering\NumberAllocator::FIRST}
     * makes the same promise from the other end.
     */
    public function countFor(int $voucherId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COALESCE(MAX(redeemed_count), 0) FROM voucher_redemption WHERE voucher_id = :voucher',
            ['voucher' => $voucherId],
        );
    }

    /**
     * Whether a redemption would be accepted right now.
     *
     * **Advisory, and labelled as such.** It is for drawing a page — a voucher
     * that says "3 of 5 used" and a voucher that says "fully redeemed" are
     * different things to look at — and it is a read followed by nothing, so by
     * the time a caller acts on it the answer may have changed. Nothing may
     * decide with this. {@see redeem()} is the decision, and it is the only one.
     *
     * It exists because the alternative is worse: without it a page would either
     * show nothing, or work the same rule out for itself in a second place.
     */
    public function hasRoom(int $voucherId, ?int $limit): bool
    {
        return $limit === self::UNLIMITED || $this->countFor($voucherId) < $limit;
    }
}

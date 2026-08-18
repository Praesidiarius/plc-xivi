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

namespace Xivi\Core\Metadata;

use Doctrine\DBAL\Connection;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Numbering\CounterRefused;
use Xivi\Core\Numbering\ExistingNumbers;
use Xivi\Core\Numbering\NumberAllocator;
use Xivi\Core\Numbering\NumberBackfill;
use Xivi\Core\Numbering\NumberFormat;

/**
 * Making a field numbered, and stopping (XIV-91).
 *
 * ### Why this is one object and not three calls in a controller
 *
 * Turning numbering on is four writes that are only correct together: the
 * counter is floored above the numbers already sitting in the column, the
 * definition gains its pattern, the field becomes derived so nobody can type
 * over the engine, and every record with no number gets one. Any prefix of that
 * list, left standing on its own, is a bug with a name — a floored counter and
 * no pattern is a counter nobody can explain; a pattern and no backfill is the
 * "oldest contact becomes 0001 by being opened on a Tuesday" failure §5.10
 * describes; a backfill and no `derived` is a numbered field somebody can still
 * type a duplicate into. So they share a transaction, and the transaction lives
 * here rather than in whatever screen happens to be calling.
 *
 * **In this order, and the order is load-bearing.** The floor goes first,
 * because it is the only step that can refuse and a refusal must leave the
 * definition exactly as it was — the same argument XIV-27 made for writing the
 * counter before the pattern. The backfill goes last, because it draws from the
 * counter and must draw from the floored one.
 *
 * ### The duplicate this closes, and the sliver that used to be left
 *
 * A counter starting at 1 knows nothing about `RE-2026-0007` sitting in the
 * column, and XIV-27's guard cannot help: it compares against the counter and
 * the collision is in the records. So the column is read
 * ({@see ExistingNumbers}) and the counter floored above everything the pattern
 * could have produced. That closes it for every value that is in the column when
 * this runs, and `derived` closes it going forward, because from here on nothing
 * writes that field but the engine.
 *
 * What it did **not** close, for one release, was the sliver between the scan
 * and the commit: the field is not derived yet while this transaction runs, so a
 * save landing on another connection at that moment could still put a hand-typed
 * value in beside the freshly-applied floor. It was administrator-only and
 * milliseconds long, and it was written down here rather than papered over,
 * because the honest fix was a unique index on the column and that was §7.2's
 * territory rather than XIV-91's.
 *
 * **XIV-109 built it, and this is now five writes rather than four.** The field
 * is marked unique first, which builds that index — and the build takes a table
 * lock that no other connection can insert past until this commits. The scan is
 * therefore not a read that can be raced any more; it is a read of a table
 * nobody may write to. See {@see self::start()} for the argument in full, and
 * §5.10 for why a numbered field being a unique field is a statement about
 * document numbers rather than a trick to get a lock.
 *
 * ### Where a Doctrine flush fits in a DBAL transaction
 *
 * {@see MetadataEditor} writes through the entity manager and everything else
 * here writes through the connection. They are the same tenant connection
 * (config/services.yaml binds both), so the flush joins the transaction opened
 * below rather than running beside it, and a failed backfill takes the pattern
 * back out with it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class NumberingChange
{
    public function __construct(
        private Connection $connection,
        private MetadataEditor $editor,
        private ExistingNumbers $existing,
        private NumberAllocator $counters,
        private NumberBackfill $backfill,
    ) {
    }

    /**
     * What turning this on would do, without doing any of it.
     *
     * Every figure on the confirmation page comes from here, and none of it is
     * an estimate: the counts are counted, the first and last numbers are
     * rendered by the same code that will render the real ones, and the counter
     * it starts from is the floor the real run will apply. What somebody agrees
     * to on that page is therefore what happens, up to records created in
     * between — which {@see start()} recomputes rather than trusting.
     */
    public function plan(
        ModuleDefinition $module,
        FieldDefinition $field,
        NumberFormat $format,
        \DateTimeImmutable $on,
    ): NumberingPlan {
        $period = $format->period($on);
        $found = $this->existing->survey($module, $field, $format, $on);
        $from = max($found->floor(), $this->counters->peek($module->getKey(), $field->getKey(), $period));

        return new NumberingPlan(
            found: $found,
            from: $from,
            // Null rather than a number nobody gets: a field where every record
            // already holds something is a real case — a customer who has been
            // typing references by hand into all of them — and "the first will
            // be RE-0008" would be a promise about a record that does not exist.
            first: $found->blank === 0 ? null : $format->render($from, $on),
            last: $found->blank === 0 ? null : $format->render($from + $found->blank - 1, $on),
            next: $format->render($from + $found->blank, $on),
            period: $period,
        );
    }

    /**
     * Turn numbering on: promise the numbers are unique, floor the counter, save
     * the pattern, number what is here.
     *
     * @return NumberingPlan what was actually done, recomputed inside the
     *                       transaction rather than repeated back from the page
     *
     * @throws MetadataChangeRefused when the column already holds the same value
     *                               on two records, so no promise of unique
     *                               numbers could be kept over it (XIV-109)
     */
    public function start(
        ModuleDefinition $module,
        FieldDefinition $field,
        NumberFormat $format,
        \DateTimeImmutable $on,
    ): NumberingPlan {
        return $this->connection->transactional(function () use ($module, $field, $format, $on): NumberingPlan {
            // **Uniqueness first, and it is what closes the window this method
            // used to leave open** (XIV-109).
            //
            // A numbered field *is* a unique field. §5.10 opens by saying that
            // two documents carrying the same number is one of the two fatal
            // failures of this feature, and until now that promise was kept by
            // arithmetic alone — a counter that only moves forward, and a scan
            // of what somebody had typed in by hand. Both are good and neither
            // is an index, so the promise was true of everything either of them
            // could see.
            //
            // What neither could see was the sliver this method itself creates.
            // The scan below reads the column; the field does not become
            // `derived` until this transaction commits; and in between, on
            // another connection, a save could put a hand-typed value in beside
            // the freshly-applied floor. It was written down in §5.10 as
            // administrator-only and milliseconds long, and it was real.
            //
            // Marking the field unique builds the index — and a
            // `CREATE UNIQUE INDEX` takes a `SHARE` lock on the table, held
            // until this transaction commits, which conflicts with every INSERT
            // and UPDATE. So from this line onward nothing else can write a row
            // of this module at all. The scan that follows is no longer a read
            // that can be raced; it is a read of a table nobody can change. The
            // window does not get smaller, it stops existing.
            //
            // It is also the step that can *refuse* — a column already holding
            // the same reference twice cannot be made to promise unique numbers
            // — so it goes first for the reason the floor used to: a refusal
            // must leave the definition exactly as it was.
            $this->editor->makeUnique($field);

            $plan = $this->plan($module, $field, $format, $on);

            // Monotone, so it cannot undo a save that took a number while the
            // scan above was running: worst case the floor is below where the
            // counter already is and does nothing at all. Kept exactly as it was
            // (XIV-91), and not weakened by the index above: the two answer
            // different questions, and a lock that has stopped being taken for
            // some future reason must not silently take a counter with it.
            $this->counters->atLeast($module->getKey(), $field->getKey(), $plan->period, $plan->found->floor());

            $this->editor->setNumbering($field, $format->pattern);
            $this->backfill->fill($module, $field, $format, $on);

            return $plan;
        });
    }

    /**
     * Stop numbering a field, and leave every number where it is.
     *
     * **Nothing is erased**, which is the same answer §5.4 gives for removing a
     * field: taking the definition away and taking the data away are different
     * acts, and only one of them is reversible. The numbers on records are on
     * documents customers are holding, so they stay; the field simply stops
     * being filled and becomes typeable again.
     *
     * **The counter stays too**, and that is the decision worth reading. It
     * would have been tidier to delete the row, and it would mean that turning
     * numbering back on next month started at 1 and walked straight back through
     * numbers already printed. Keeping it makes off-then-on carry on where it
     * left off. A counter nobody draws from costs one row.
     *
     * **Uniqueness stays too, and that is XIV-109's half of the same argument.**
     * The field becomes typeable again, which is exactly the moment its index
     * starts earning its keep: the numbers on those records are on documents
     * customers are holding, and the first thing an ordinary text box invites is
     * somebody typing one of them a second time. Dropping the index here would
     * relax a promise nobody asked to have relaxed, in the act of changing
     * something else — and the customer can untick the box themselves if they
     * really mean it, which is the difference between a decision and a side
     * effect.
     *
     * There is no transaction here because there is one write.
     */
    public function stop(FieldDefinition $field): void
    {
        $this->editor->setNumbering($field, null);
    }

    /**
     * Wind the counter forward, checked against the records as well as against
     * itself (XIV-91).
     *
     * XIV-27 built the wind-forward for the customer arriving from another
     * system mid-sequence, and guarded it in one statement against going
     * backwards over numbers the counter had given out. This adds the check that
     * statement cannot make: a field that used to be typed into by hand may
     * carry `RE-2026-1043` that no counter ever produced, so a wind-forward to
     * 1043 is a duplicate the counter has no way of seeing.
     *
     * **Both, in this order, and the second is still the one that decides.** The
     * scan is a read, so it is racy by nature and could in principle miss a
     * value written a millisecond later; {@see NumberAllocator::restartAt()} is
     * one statement over the counter's own row and cannot be raced at all. So
     * this narrows and that guarantees. Removing this would let a hand-typed
     * duplicate through; removing that would let a concurrent allocation
     * through, and the second is the one that ends up on two invoices.
     *
     * @throws CounterRefused when the wanted number is on a record, or below where
     *                        the counter already stands
     */
    public function windForward(
        ModuleDefinition $module,
        FieldDefinition $field,
        NumberFormat $format,
        int $next,
        \DateTimeImmutable $on,
    ): void {
        $period = $format->period($on);
        $found = $this->existing->survey($module, $field, $format, $on);

        if ($found->highest !== null && $next <= $found->highest) {
            throw CounterRefused::alreadyOnARecord(
                $period,
                // Rendered rather than printed as an integer, because it is what
                // somebody would find if they went looking for it in the list:
                // the record says RE-2026-1043, not 1043.
                $format->render($found->highest, $on),
                $found->floor(),
                $next,
            );
        }

        $this->counters->restartAt($module->getKey(), $field->getKey(), $period, $next);
    }
}

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
 * ### The duplicate this closes, and the one it does not
 *
 * A counter starting at 1 knows nothing about `RE-2026-0007` sitting in the
 * column, and XIV-27's guard cannot help: it compares against the counter and
 * the collision is in the records. So the column is read
 * ({@see ExistingNumbers}) and the counter floored above everything the pattern
 * could have produced. That closes it for every value that is in the column when
 * this runs, and `derived` closes it going forward, because from here on nothing
 * writes that field but the engine.
 *
 * What it does **not** close is the sliver between the scan and the commit: the
 * field is not derived yet while this transaction runs, so a save landing in
 * another connection at that moment could still put a hand-typed value in. It is
 * a read-committed window of milliseconds on an administrator-only action, and
 * it is written down here rather than papered over — the honest fix is a unique
 * index on the column, which is §7.2's territory and not this ticket's.
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
     * Turn numbering on: floor the counter, save the pattern, number what is
     * here.
     *
     * @return NumberingPlan what was actually done, recomputed inside the
     *                       transaction rather than repeated back from the page
     */
    public function start(
        ModuleDefinition $module,
        FieldDefinition $field,
        NumberFormat $format,
        \DateTimeImmutable $on,
    ): NumberingPlan {
        return $this->connection->transactional(function () use ($module, $field, $format, $on): NumberingPlan {
            $plan = $this->plan($module, $field, $format, $on);

            // Monotone, so it cannot undo a save that took a number while the
            // scan above was running: worst case the floor is below where the
            // counter already is and does nothing at all.
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

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

use Symfony\Component\Workflow\WorkflowInterface;
use Xivi\Core\Record\Record;

/**
 * One module's lifecycle, ready to be asked about a record (XIV-14).
 *
 * A thin thing around symfony/workflow on purpose: the component decides what is
 * legal, and this decides what the rest of the application gets to see of it —
 * enabled transitions as *this* project's value objects, and a refusal that
 * carries what was possible instead.
 *
 * Wrapping rather than exposing `Workflow` directly keeps the component at one
 * seam. If it ever stops fitting, this class is what changes.
 *
 * **It is also where a module's own condition is evaluated** (XIV-110). §5.8
 * settled that in advance and for a reason worth restating: a condition belongs
 * to whoever already owns the refusal, and that is this class, which is holding
 * the {@see TransitionRefused} that carries a message somebody can act on. The
 * component's own guards would have put it on an event instead, and these state
 * machines are built with no dispatcher on purpose.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordLifecycle
{
    public function __construct(
        public Lifecycle $lifecycle,
        private WorkflowInterface $workflow,
        /**
         * Whose catalogue a guard's refusal is written in — the module that
         * declared this lifecycle, which is the same domain its transition
         * labels are read from.
         */
        private string $moduleKey = '',
        /**
         * How a guard gets at the record's rows, when it wants them. Optional so
         * that a lifecycle can still be built in a unit test with nothing behind
         * it; a guard that asks for rows then gets none, which is the same answer
         * an unsaved record gives.
         */
        private ?CollectionRows $rows = null,
    ) {
    }

    /**
     * Every move this record's state allows, each with the reason its guard
     * refuses it or nothing at all — in the order the module declared.
     *
     * **This is the one place the predicate is evaluated, and everything else
     * reads its answer.** The button on the page and the refusal behind the POST
     * are the same question asked about the same record, and the only way to be
     * sure a page never offers a move that would then be refused is for both to
     * come from here. That is why this returns the refused moves rather than
     * dropping them: the page has a sentence to print instead of a button, and
     * {@see self::enabledFor()} is a filter over the same list rather than a
     * second walk of it.
     *
     * The state machine is asked first and the guards second, which is not
     * merely an ordering. A guard may be expensive — the point of the mechanism
     * is that it can read the record's rows — and a move the record's state
     * already forbids should cost nothing to not offer.
     *
     * @return list<TransitionOffer>
     */
    public function offeredFor(Record $record): array
    {
        $names = array_map(
            static fn (\Symfony\Component\Workflow\Transition $t): string => $t->getName(),
            $this->workflow->getEnabledTransitions($record),
        );

        // One subject for the whole answer, so three guarded transitions asking
        // about the same collection make one query between them rather than
        // three. Built even when nothing is guarded, because building it reads
        // nothing.
        $guarded = $this->guarded($record);
        $offers = [];

        foreach ($this->lifecycle->transitions as $transition) {
            if (!\in_array($transition->name, $names, true)) {
                continue;
            }

            $offers[] = new TransitionOffer($transition, $transition->guard?->refusal($guarded));
        }

        return $offers;
    }

    /**
     * What a record in this state may actually become, in the order the module
     * declared.
     *
     * The moves that are legal *and* not refused, which is the list a caller
     * with nothing to print wants: the demo walker, and the refusal that names
     * what is possible from here. A caller that has a page to draw wants
     * {@see self::offeredFor()} instead, so that it can say why.
     *
     * @return list<LifecycleTransition>
     */
    public function enabledFor(Record $record): array
    {
        $possible = [];

        foreach ($this->offeredFor($record) as $offer) {
            if ($offer->isPossible()) {
                $possible[] = $offer->transition;
            }
        }

        return $possible;
    }

    public function stateOf(Record $record): string
    {
        $state = $record->get($this->lifecycle->field);

        return \is_string($state) && $state !== '' ? $state : $this->lifecycle->initial;
    }

    public function isLocked(Record $record): bool
    {
        return $this->lifecycle->isLocked($this->stateOf($record));
    }

    /**
     * Moves the record, or refuses and says why.
     *
     * The record is changed in memory only; saving it is the caller's business,
     * because a transition is a change to a record like any other and has to go
     * through the writer that owns the transaction and the history entry (§5.2).
     *
     * **This is the enforcement, and the button is only the courtesy** (XIV-110).
     * The page not drawing a button that would be refused is a kindness to
     * whoever is reading it; a POST is a URL, and a URL can be retyped, replayed
     * from a bookmark or submitted from a page that was drawn before somebody
     * else emptied the record. So the guard is asked again here, against the
     * record as it is now, and what it says is what happens. Anything that only
     * hid the button would have been a page that looks like a rule.
     *
     * Three refusals in a deliberate order — not a transition this lifecycle has,
     * not a move from this state, not a move this record is ready for — because
     * each is a different sentence and only the last one needs a module to have
     * written anything.
     *
     * @throws TransitionRefused
     */
    public function apply(Record $record, string $transition): void
    {
        $declared = $this->lifecycle->transition($transition);

        if ($declared === null) {
            throw TransitionRefused::unknown($transition, $this->lifecycle);
        }

        if (!$this->workflow->can($record, $transition)) {
            throw TransitionRefused::notFromHere($transition, $this->stateOf($record), $this->enabledFor($record));
        }

        $refusal = $declared->guard?->refusal($this->guarded($record));

        if ($refusal !== null) {
            throw TransitionRefused::notReady($transition, $refusal, $this->moduleKey);
        }

        $this->workflow->apply($record, $transition);
    }

    /**
     * The record as a guard sees it: itself, and a way to reach its rows.
     *
     * A fresh one per ask rather than one kept around. The memo inside it is
     * only correct for as long as nothing has written to the record's
     * collections, and "as long as one question is being answered" is the
     * longest interval this class can promise that for — a `RecordLifecycle`
     * lives for the whole request and outlives any number of saves.
     */
    private function guarded(Record $record): GuardedRecord
    {
        return new GuardedRecord(
            $record,
            fn (string $collection): array => $this->rows?->of($this->moduleKey, $collection, $record->id) ?? [],
        );
    }
}

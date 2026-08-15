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
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordLifecycle
{
    public function __construct(
        public Lifecycle $lifecycle,
        private WorkflowInterface $workflow,
    ) {
    }

    /**
     * What a record in this state may become, in the order the module declared.
     *
     * @return list<LifecycleTransition>
     */
    public function enabledFor(Record $record): array
    {
        $names = array_map(
            static fn (\Symfony\Component\Workflow\Transition $t): string => $t->getName(),
            $this->workflow->getEnabledTransitions($record),
        );

        return array_values(array_filter(
            $this->lifecycle->transitions,
            static fn (LifecycleTransition $t): bool => \in_array($t->name, $names, true),
        ));
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
     * Moves the record, or refuses and says what was possible.
     *
     * The record is changed in memory only; saving it is the caller's business,
     * because a transition is a change to a record like any other and has to go
     * through the writer that owns the transaction and the history entry (§5.2).
     *
     * @throws TransitionRefused
     */
    public function apply(Record $record, string $transition): void
    {
        if ($this->lifecycle->transition($transition) === null) {
            throw TransitionRefused::unknown($transition, $this->lifecycle);
        }

        if (!$this->workflow->can($record, $transition)) {
            throw TransitionRefused::notFromHere($transition, $this->stateOf($record), $this->enabledFor($record));
        }

        $this->workflow->apply($record, $transition);
    }
}

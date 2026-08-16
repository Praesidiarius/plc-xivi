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

/**
 * The states a module's records move through, and the moves that are allowed
 * (XIV-14).
 *
 * **Declared by the module, in code**, beside its fields and its presets — a
 * lifecycle is part of what a module *is*, so changing one is a release rather
 * than something a customer configures (§6.1). That is also why it is not in
 * `config/packages/workflow.yaml` where the framework would put it: a YAML file
 * would have to list every customer's modules, and which modules exist is a
 * runtime question (§3).
 *
 * **The state lives in an ordinary field** — a `choice` field the module already
 * declares. So it is filterable, listable, exportable and visible in history for
 * free, and a lifecycle is a *rule over a value* rather than a second place the
 * state is kept. Two stores would eventually disagree.
 *
 * The states are the transitions' own: anything named as a `from` or a `to`,
 * plus the initial one. Listing them separately would be a second description to
 * keep in step with the first.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Lifecycle
{
    /**
     * @param string                    $field       the record field holding the state
     * @param string                    $initial     what a new record starts as
     * @param list<LifecycleTransition> $transitions
     * @param list<string>              $locked      states in which the record may no longer
     *                                               be edited — a sent invoice is a document
     */
    public function __construct(
        public string $field,
        public string $initial,
        public array $transitions,
        public array $locked = [],
    ) {
    }

    /**
     * Every state this lifecycle knows, initial first and then in the order the
     * transitions mention them.
     *
     * @return list<string>
     */
    public function states(): array
    {
        $states = [$this->initial];

        foreach ($this->transitions as $transition) {
            foreach ([...$transition->from, $transition->to] as $state) {
                if (!\in_array($state, $states, true)) {
                    $states[] = $state;
                }
            }
        }

        return $states;
    }

    /**
     * The shortest run of transitions that gets a record from one state to
     * another, or nothing at all when there is no way (XIV-73).
     *
     * **Asked by whoever has a state in mind and only the front door to reach it
     * with.** Demo data is the first such caller: it is handed a plausible
     * status to aim at and must arrive there the way a person would, one legal
     * move at a time, so that the record collects the history entries and the
     * derived values each of those moves produces (§5.17). Writing the state
     * straight onto the record would be quicker and would produce a `paid`
     * invoice nobody ever sent.
     *
     * **Shortest, and that is a real choice.** An order can be cancelled from
     * `draft` or from `confirmed`, so "get to cancelled" has more than one
     * answer; breadth-first takes the direct one. The alternative — a wander
     * that happens to end up there — would be a second, invisible distribution
     * on top of whatever the caller asked for, and a caller that wants a long
     * road can ask for the state in the middle of it first.
     *
     * An empty list means two different things and deliberately does not
     * distinguish them: the record is already there, or it can never get there.
     * Both leave the caller with nothing to do.
     *
     * @return list<LifecycleTransition>
     */
    public function pathTo(string $from, string $to): array
    {
        if ($from === $to) {
            return [];
        }

        // Reached states rather than visited ones: marked as they go on the
        // queue, so a state with two ways into it is not queued twice and a
        // lifecycle with a loop in it terminates.
        $reached = [$from => true];
        /** @var list<array{string, list<LifecycleTransition>}> $queue */
        $queue = [[$from, []]];

        while ($queue !== []) {
            [$state, $path] = array_shift($queue);

            foreach ($this->transitions as $transition) {
                if (!\in_array($state, $transition->from, true) || isset($reached[$transition->to])) {
                    continue;
                }

                $next = [...$path, $transition];

                if ($transition->to === $to) {
                    return $next;
                }

                $reached[$transition->to] = true;
                $queue[] = [$transition->to, $next];
            }
        }

        return [];
    }

    /** Whether a record in this state has stopped being editable. */
    public function isLocked(?string $state): bool
    {
        return $state !== null && \in_array($state, $this->locked, true);
    }

    public function transition(string $name): ?LifecycleTransition
    {
        foreach ($this->transitions as $transition) {
            if ($transition->name === $name) {
                return $transition;
            }
        }

        return null;
    }
}

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

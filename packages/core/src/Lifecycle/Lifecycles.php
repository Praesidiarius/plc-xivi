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

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Xivi\Core\Module\ModuleRegistry;

/**
 * The lifecycle of each module that has one, built once per request (XIV-14).
 *
 * **From the blueprints, not from configuration.** The framework's own way in is
 * `framework.workflows` in YAML, which cannot work here: that file would have to
 * name every module every customer might install, and which modules a customer
 * has is a runtime question (§3). `DefinitionBuilder` is the component's answer
 * to exactly that, so the definitions are assembled from the same declarations
 * the fields come from.
 *
 * A module the build no longer ships has no lifecycle, and a record of it simply
 * has no transitions to offer — the same shape as a permission grant for an
 * uninstalled module going inert rather than erroring (§8.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class Lifecycles
{
    /** @var array<string, RecordLifecycle|null> */
    private array $built = [];

    public function __construct(private readonly ModuleRegistry $modules)
    {
    }

    /** Null for a module with no lifecycle, which is most of them. */
    public function for(string $moduleKey): ?RecordLifecycle
    {
        return $this->built[$moduleKey] ??= $this->build($moduleKey);
    }

    private function build(string $moduleKey): ?RecordLifecycle
    {
        if (!$this->modules->has($moduleKey)) {
            return null;
        }

        $lifecycle = $this->modules->get($moduleKey)->lifecycle;

        if ($lifecycle === null) {
            return null;
        }

        // **One component transition per origin, all sharing the name.** A
        // `Transition` taking a list of froms means something else entirely: to
        // the Petri net underneath, a transition with two inputs needs the
        // subject to be in *both* at once, so "cancel, from draft or from
        // active" — an ordinary requirement — quietly never becomes available.
        // A state machine wants them as alternatives, and alternatives are
        // spelled as separate transitions with one origin each. Two tests
        // failed on this; neither class name would have told anybody.
        $transitions = [];

        foreach ($lifecycle->transitions as $transition) {
            foreach ($transition->from as $from) {
                $transitions[] = new Transition($transition->name, $from, $transition->to);
            }
        }

        $definition = new Definition($lifecycle->states(), $transitions, $lifecycle->initial);

        return new RecordLifecycle(
            $lifecycle,
            // **StateMachine, not Workflow.** A record is in exactly one state,
            // which is what a state machine models; `Workflow` is the Petri net
            // above, where a subject holds several places at once. The choice
            // also validates the definition the way this project wants it
            // validated — one origin per transition object.
            //
            // No event dispatcher: the component's own events are a second place
            // behaviour could hide, and this project already has one seam for
            // that (§6). What happens when a record moves is decided by whoever
            // saves it, in the open.
            new StateMachine($definition, new RecordMarkingStore($lifecycle), name: $moduleKey),
        );
    }
}

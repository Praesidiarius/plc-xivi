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
 * Whether a move the graph allows may be taken by *this* record, right now
 * (XIV-110).
 *
 * A lifecycle without one can only refuse the moves the **graph** forbids — an
 * order cannot be delivered before it is confirmed — and never the moves the
 * **record** forbids: an order with nothing on it confirmed cleanly, and every
 * other part of the engine that looks as though it would have caught that turns
 * out not to. Field validation is per field and unconditional, so "an order needs
 * a line" said there would demand the line of a *draft* too, which is not the
 * rule anybody means; and {@see \Xivi\Core\Record\RecordWriter} validates
 * nothing, so the save a transition makes is inspected by nobody. §5.8 argued
 * that a lifecycle is the thing that says which moves are legal, and half of that
 * was missing.
 *
 * **A predicate in code, not an expression string, and that was decided before
 * this existed** (XIV-88, §5.8). A lifecycle is declared by a module, in code
 * (§6.1), and against code an expression is strictly worse than a typed callable:
 * PHPStan cannot see into it, neither can an IDE, and renaming a field key breaks
 * it in silence. An evaluator earns its keep only where the author cannot ship
 * PHP — which means a customer, and a customer cannot author a lifecycle at all,
 * because there is nowhere in tenant metadata for a per-transition option to
 * live. The framework's own answer, `workflow.guard`, is closed by the same
 * section for a different reason: these state machines are built with no event
 * dispatcher on purpose, and adopting the component's guards means adopting the
 * seam it refused.
 *
 * **Not a service, and that is what lets it be declared where it belongs.** A
 * guard is constructed inline in the module's blueprint beside the transition it
 * is about, like {@see \Xivi\Core\Money\LineTotals} and
 * {@see \Xivi\Core\Numbering\NumberFormat} — so it is stateless, takes no
 * dependencies, and reads everything it needs from the record it is handed. A
 * tagged service would have meant the pairing living somewhere other than the
 * declaration, which is the thing this project keeps trying not to do.
 *
 * **One guard per transition rather than a list.** "May this move be taken now"
 * is one question, and a module wanting two conditions writes one guard that asks
 * both — which is also the only way it gets to choose *which* of the two
 * sentences comes back. A list would need a rule for whose message wins, and a
 * rule like that is invented in the engine on behalf of a module that knows
 * better.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface TransitionGuard
{
    /**
     * Why this record may not take the move, or null when it may.
     *
     * **The return value is a translation key in the module's own catalogue** —
     * the same catalogue and the same domain the transition's label comes from
     * ({@see LifecycleTransition::labelKey()}). A key rather than a built
     * `TranslatableMessage` because the domain is not the guard's to choose: it
     * is whichever module declared the lifecycle, and a guard that named one
     * could name the wrong one. The engine puts the two together.
     *
     * The sentence behind the key is the point of the whole mechanism. "Cannot
     * confirm" is a refusal that leaves somebody with nothing to do; "an order
     * needs at least one line before it can be confirmed" is a refusal that
     * tells them what to fix, and only the module knows how to say it.
     *
     * **Asked twice about the same record and expected to agree.** Once to
     * decide whether to draw the button, and once when the POST arrives; see
     * {@see RecordLifecycle::offeredFor()}. A guard that answered differently on
     * two consecutive calls would produce a page that offers a move and then
     * refuses it, which is worse than not offering it — so read the record and
     * its rows, and nothing else. Nothing here is the place to look at the clock
     * or at who is signed in.
     */
    public function refusal(GuardedRecord $record): ?string;
}

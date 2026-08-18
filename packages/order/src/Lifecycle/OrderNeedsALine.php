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

namespace Xivi\Order\Lifecycle;

use Xivi\Core\Lifecycle\GuardedRecord;
use Xivi\Core\Lifecycle\TransitionGuard;
use Xivi\Order\OrderModule;

/**
 * An order with nothing on it is not an order yet (XIV-110).
 *
 * **The rule this whole mechanism was found by.** An empty order confirmed
 * cleanly: the button was drawn, the POST went through, the record moved to
 * `confirmed` and a document with no lines and a total of zero became a
 * confirmed sale. Nothing in the engine caught it, and nothing was going to —
 * §5.8 explains at length why field validation cannot say this, and the short
 * version is that a rule which is only true *after* a transition has nowhere to
 * be written as a rule about a field. The neighbouring half of the same sentence
 * makes the point: `contact` is a `required` field precisely because "an order
 * names a customer" happens to be true of a draft as well.
 *
 * **On confirm, and on nothing else.** Not on `deliver`, which is unreachable
 * without confirming first and would be a second copy of the same rule; and
 * emphatically not on `cancel`, because an empty order is exactly the kind
 * somebody wants to get rid of, and a guard that trapped a record in a state it
 * cannot leave would be worse than the bug it was fixing. That is the shape to
 * check whenever one of these is written: a guard on the only way out is a
 * dead end.
 *
 * **Lines, not money, and that is a decision rather than a simplification.** The
 * survey that found this named "a total of zero" alongside "no lines", and only
 * one of them is a mistake. An order can legitimately come to nothing — a
 * goodwill replacement, a line discounted in full, a sample sent out priced at
 * zero — and refusing those would be the engine having an opinion about somebody
 * else's pricing. An order with no lines at all cannot be any of those things:
 * there is nothing on it to have been priced.
 *
 * A comment line counts, which is the one edge worth stating out loud. It is a
 * line somebody deliberately typed, this guard is about a document that has had
 * something done to it rather than about a total, and "at least one line" is what
 * the message promises — a rule whose sentence and whose code disagree is worse
 * than either.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class OrderNeedsALine implements TransitionGuard
{
    /** What it says when it refuses, in the order module's own catalogue (XIV-8). */
    public const string REASON = 'guard.needs_a_line';

    public function refusal(GuardedRecord $record): ?string
    {
        return $record->rows(OrderModule::LINES) === [] ? self::REASON : null;
    }
}

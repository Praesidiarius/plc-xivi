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

namespace App\Registry\Entity;

/**
 * Where a support ticket has got to, as the operator has said (XIV-123, §8.17).
 *
 * **Three states, and the argument is about the ones that are not here.**
 *
 * §8.15 refused a status column on a purchase request outright, and the reason
 * was good: fulfilment there is *observable* — the customer either has the
 * module or does not, their own metadata is the truth, and a column would have
 * been a second copy of a fact free to disagree with it. None of that transfers.
 * Whether somebody is looking at a question is not observable from anywhere; it
 * exists only in an operator's head until they say so, and the whole point of
 * this ticket is that a customer who asked something is currently staring at
 * silence.
 *
 * So a status is a real thing to store here, and the test each case has to pass
 * is *does this say something nothing else on the row already says*:
 *
 * * {@see self::Open} — nobody has said anything. The state a collection writes.
 * * {@see self::InProgress} — somebody has picked it up. **This is the case that
 *   earns the enum**: it is the sentence a customer most wants and cannot get
 *   from anywhere else, and without it the only way to say *"we are on it"* is
 *   to close a ticket that is not finished.
 * * {@see self::Closed} — done with, whatever the outcome. Not *"resolved"*,
 *   because a question that turned out to be a misunderstanding is closed and
 *   was never resolved, and a status that claims an outcome would be a claim
 *   made by whoever clicked last.
 *
 * **There is no `Answered`, and that is the deliberate absence.** A reply is
 * visible on the row — the customer is reading it — so a state saying one exists
 * would be a copy of a fact sitting two columns away, which is exactly what
 * §8.15 refused. An operator who replies and considers it finished closes it;
 * one who replies and expects to hear more leaves it in progress.
 *
 * **And no priority, no category, no SLA.** An ERP support queue is not a
 * helpdesk product, and those three are how it becomes one. Each of them is also
 * a promise: a priority is a promise about ordering, an SLA a promise about
 * time, and this installation knows nothing about the arrangement between the
 * two companies that would let it keep either.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum SupportStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    /**
     * Whether this is a ticket somebody still owes an answer for.
     *
     * The operator's screen leads with the count of these and the customer's
     * screen draws them first, so the two pages cannot disagree about what
     * "outstanding" means — which is the kind of thing that drifts when each
     * template writes its own condition.
     */
    public function isOutstanding(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * The translation key for this state, in the application's own catalogue.
     *
     * In `messages` rather than in the control plane's part of it, because both
     * screens draw it: an operator sets the status and the customer reads it, and
     * one installation has one catalogue ({@see NoticeAudience} made the same
     * call for the same reason).
     */
    public function label(): string
    {
        return 'support.status.' . $this->value;
    }
}

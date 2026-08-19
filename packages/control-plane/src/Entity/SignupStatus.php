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

namespace Xivi\ControlPlane\Entity;

/**
 * How far a self-service signup has got (XIV-64).
 *
 * **Two states, and there is deliberately no third.** A signup is either an
 * address somebody typed into a form — which proves nothing at all, because
 * anybody can type anybody's address — or an address whose owner has followed a
 * link only their mailbox received. Everything the intake refuses to do before
 * the second one has happened hangs off this distinction: no slug is held, no
 * other signup is blocked, and nothing downstream will look at the row.
 *
 * **`provisioned` is not here on purpose.** Turning a confirmed signup into a
 * tenant is [XIV-98], and when it exists the tenant registry is where the answer
 * to "does this customer exist" lives — `tenant.slug`, with the unique index it
 * has had since the registry was created. A third state here would be a second
 * copy of that fact, free to disagree with it, and the disagreement would be
 * silent: a row marked `provisioned` whose tenant was later deprovisioned would
 * go on holding a slug nobody owns. So the intake table holds *live* signups
 * only, and [XIV-98] removes the row when it has made a tenant out of it. See
 * docs/architecture/identity-and-access.md §8.12.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum SignupStatus: string
{
    /**
     * Recorded, mail sent, nothing held.
     *
     * A row in this state reserves *nothing*: its slug is available to anybody
     * else who asks for it, and the only thing it occupies is its own email
     * address, which a second submission from that address takes over rather
     * than collides with. That is the anti-squatting property in one sentence —
     * holding a name costs a working mailbox, because it costs a confirmation.
     */
    case Pending = 'pending';

    /**
     * The address answered, and the slug is now held for it.
     *
     * This is the state [XIV-98] reads. It is also the state that makes the
     * address exclusive: a confirmed signup that has not been provisioned yet is
     * an unfinished order, and letting one address stack up several of them
     * would let one confirmed mailbox hold as many names as it liked.
     */
    case Confirmed = 'confirmed';
}

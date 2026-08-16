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

namespace App\Tenant\EventListener;

use App\Tenant\Entity\User;
use App\Tenant\Security\UserManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * An invitation link is spent the moment it works (XIV-1).
 *
 * This is the single-use half of the invitation, and it is here rather than in a
 * controller because there is no controller: the login link authenticator
 * intercepts `/invitation` and answers with a redirect, so the route's own action
 * is never reached. `LoginSuccessEvent` is the first point at which "this
 * particular link authenticated somebody" is a fact, which is exactly when it has
 * to stop being usable.
 *
 * **After the user checker, not before.** By the time this runs the passport has
 * been through `ActiveUserChecker`, so a deactivated person's click never gets
 * here — their link is refused and, deliberately, *not* consumed. Reactivating
 * them within the 24 hours makes the invitation they were already sent work,
 * rather than burning it on a refusal they never saw.
 *
 * The seed rotates rather than clearing to null. Both would invalidate the link
 * — the signature covers the value either way — but a null is also the value
 * every never-invited account carries, and "used" and "never issued" are worth
 * keeping distinguishable in a row somebody is reading during an incident.
 *
 * Only login-link authentications are touched. An ordinary sign-in has no
 * invitation to spend, and rotating a seed on every password login would be a
 * write on the hot path buying nothing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final readonly class InvitationAcceptedListener
{
    public function __construct(
        private UserManager $users,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        if (!$event->getAuthenticator() instanceof LoginLinkAuthenticator) {
            return;
        }

        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $this->users->rotateInvitationSeed($user);
    }
}

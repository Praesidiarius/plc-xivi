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
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Ends the session of somebody who has been deactivated since they signed in.
 *
 * `ActiveUserChecker` refuses the sign-in itself, but that is not enough on its
 * own: when a session is restored on a later request, Symfony's `ContextListener`
 * compares the stored user with the reloaded one on identifier, password and
 * roles, and **never asks the user checker**. So withdrawing somebody's access
 * would otherwise take effect whenever their session happened to expire, which
 * for the one case where it matters — a person who has just left — is the wrong
 * answer for as long as the session lasts.
 *
 * Verified rather than assumed: the test for this failed before this class
 * existed, with the checker already in place.
 *
 * `EquatableInterface` on the user is the other way to do it, and is not taken
 * here. It *replaces* the framework's whole change comparison, so this class
 * would silently become responsible for signing people out on a password change
 * too — including the shortened-hash case `ContextListener` handles. One
 * explicit rule beats owning four implicit ones.
 *
 * Priority 7: just below the firewall (8), so the user has been restored and can
 * be looked at, and before anything downstream acts on it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class DeactivatedUserListener
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokens->getToken()?->getUser();

        if (!$user instanceof User || $user->isActive()) {
            return;
        }

        $this->tokens->setToken(null);

        $request = $event->getRequest();

        $session = $request->hasSession(true) ? $request->getSession() : null;

        if ($session instanceof Session) {
            // Invalidated first, then written to: invalidate() starts a fresh
            // session, so a flash added before it would go with the old one.
            $session->invalidate();
            $session->getFlashBag()->add('warning', 'This account has been deactivated.');
        } else {
            $session?->invalidate();
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('login')));
    }
}

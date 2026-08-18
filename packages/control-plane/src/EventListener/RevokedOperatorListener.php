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

namespace Xivi\ControlPlane\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\ControlPlane\Entity\Operator;

/**
 * Ends the session of an operator who has been revoked since they signed in
 * (XIV-92).
 *
 * **The whole reason this exists is that revoking somebody does not, on its own,
 * do anything to a session they already have.** Symfony refreshes the user from
 * the provider on every request, which sounds as though a withdrawn account
 * would fall out at the next click, and it is not what happens:
 * `ContextListener::refreshUser()` compares the stored user with the reloaded one
 * on identifier, password and roles, and `active` is none of those. A revoked
 * operator would therefore keep browsing the control plane — the tenant list,
 * every customer's hostname and plan — until their session happened to expire.
 * For the case this ticket is about, somebody who has just left or a credential
 * that has just leaked, that is the wrong answer for exactly as long as it
 * lasts.
 *
 * Established rather than assumed, which is the same sentence
 * `App\Tenant\EventListener\DeactivatedUserListener` carries and it was earned
 * the same way here: `ControlPlaneRevocationTest::testARevokedOperatorsLiveSessionEnds()`
 * was written first and watched to fail, with
 * {@see \Xivi\ControlPlane\Security\ActiveOperatorChecker} already wired onto the
 * firewall. A user checker is not consulted on a session restore, so the checker
 * covers the sign-in and this covers the session; neither covers the other's
 * case.
 *
 * **A password change needs no listener of its own**, and that asymmetry is
 * worth knowing rather than rediscovering: the hash *is* one of the three things
 * `ContextListener` compares, so `control:operator:password` invalidates every
 * live session for that account for free. Only revocation had to be taught.
 *
 * `EquatableInterface` on `Operator` is the other way to do this and is not
 * taken, for the reason the tenant side gives: it replaces the framework's whole
 * change comparison, so this package would silently become responsible for the
 * password case above too — including the shortened-hash handling
 * `ContextListener` does. One explicit rule beats owning four implicit ones.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class RevokedOperatorListener
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Priority 7, the same slot the tenant listener occupies: just below the
     * firewall at 8, so the token has been restored and can be looked at, and
     * before anything downstream acts on it.
     *
     * No host check and none needed. The only firewall that mints an `Operator`
     * token is the control plane's, and the two firewalls store their tokens
     * under different context keys (§8.9), so a request on a customer's hostname
     * cannot produce one to find here.
     */
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokens->getToken()?->getUser();

        if (!$user instanceof Operator || $user->isActive()) {
            return;
        }

        $this->tokens->setToken(null);

        $request = $event->getRequest();
        $session = $request->hasSession(true) ? $request->getSession() : null;

        if ($session instanceof Session) {
            // Invalidated first and written to second: `invalidate()` starts a
            // fresh session, so a flash added before it would be attached to the
            // one that is being thrown away.
            $session->invalidate();
            $session->getFlashBag()->add('warning', $this->translator->trans('control_plane.revoked'));
        } else {
            $session?->invalidate();
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('control_plane_login')));
    }
}

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
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Holds an account at the password page until its generated password has been
 * replaced (§8.5).
 *
 * A generated password is a way in rather than a credential: the administrator
 * who created the account read it off a screen and passed it on by whatever
 * means was to hand — chat, a phone call, an email that will sit in a mailbox
 * for years. It stops being shared knowledge only when the owner has chosen
 * their own, so everything else waits until they have.
 *
 * A hold rather than a wizard: every page redirects to the same one, so there is
 * no separate first-run flow to keep in step with the ordinary one, and nothing
 * to get stuck half way through. Signing out is deliberately still allowed —
 * somebody who cannot change their password right now must be able to leave.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final readonly class MustChangePasswordListener
{
    /**
     * Where somebody being held is still allowed to go: the page that lifts the
     * hold, and the way out of the building.
     */
    private const array ALLOWED = ['account', 'logout', 'login'];

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

        if (!$user instanceof User || !$user->mustChangePassword()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        // Route names rather than paths: a path check would have to be kept in
        // step with the routes, and the profiler's own paths would need
        // excluding by hand.
        if (\in_array($route, self::ALLOWED, true) || !\is_string($route)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('account')));
    }
}

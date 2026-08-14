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

namespace App\Tenancy\EventListener;

use App\Tenancy\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ties a session to the tenant it was created for.
 *
 * Symfony keeps the *user identifier* in the session and reloads the user from
 * the provider on each request. Our identifiers are emails, and emails are only
 * unique within a tenant — one person setting up several customers will happily
 * be admin@example.com in all of them. So a session minted on customer A and
 * replayed against customer B's host would authenticate as B's user of the same
 * name. Browsers will not do that on their own, since cookies are scoped to a
 * host, but a copied cookie is not an exotic attack.
 *
 * So: record which tenant a session belongs to, and refuse to reuse it anywhere
 * else. A mismatch becomes a fresh empty session instead of a silent
 * cross-tenant authentication.
 *
 * Deliberate side effect: invalidating destroys the session server-side, so
 * replaying a cookie on the wrong host also signs the real owner out. That is a
 * fair trade — anyone able to replay the cookie could already have used it on the
 * right host, so it costs them nothing they did not already have, and a session
 * turning up where it does not belong is worth ending.
 *
 * Two halves, because the session usually does not exist yet when the request
 * arrives: check on the way in, stamp on the way out.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantSessionGuard
{
    public const string SESSION_KEY = '_tenant';

    public function __construct(private TenantContext $context, private LoggerInterface $logger)
    {
    }

    /**
     * Priority 96: after TenantRequestListener resolves the tenant (100) and
     * before the firewall reads the session to restore a token.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 96)]
    public function discardForeignSession(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->hasPreviousSession()) {
            return;
        }

        $session = $event->getRequest()->getSession();
        $sessionTenant = $session->get(self::SESSION_KEY);
        $currentTenant = $this->context->tryGetTenant()?->getSlug();

        if ($sessionTenant === $currentTenant) {
            return;
        }

        if ($sessionTenant !== null) {
            $this->logger->warning('Discarding a session created for tenant "{session}" on tenant "{current}".', [
                'session' => $sessionTenant,
                'current' => $currentTenant ?? '(none)',
            ]);
        }

        // Clears the data and regenerates the id, so nothing from the other
        // tenant survives — the authentication token above all.
        $session->invalidate();
    }

    /**
     * By response time the firewall has started a session if the request warranted
     * one, so this is where a login gets stamped. Sessions that were never started
     * stay unstarted: stamping unconditionally would hand a cookie to every
     * anonymous visitor.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
    public function stampCurrentTenant(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $tenant = $this->context->tryGetTenant();

        if ($tenant === null || !$request->hasSession(skipIfUninitialized: true)) {
            return;
        }

        $session = $request->getSession();

        if ($session->get(self::SESSION_KEY) !== $tenant->getSlug()) {
            $session->set(self::SESSION_KEY, $tenant->getSlug());
        }
    }
}

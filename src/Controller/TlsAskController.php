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

namespace App\Controller;

use App\Tenancy\Exception\UnknownTenantHostException;
use App\Tenancy\TenantResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Answers whether this installation serves a hostname, so Caddy can decide
 * whether to get a certificate for it (XIV-61, §4.8).
 *
 * **This is what makes on-demand TLS safe rather than a way to exhaust the
 * certificate budget.** Tenancy resolves by hostname, so every customer is
 * `<slug>.<domain>` and there is no fixed list of names to write into a
 * configuration file. Caddy therefore has to be allowed to answer names nobody
 * listed, and without this endpoint that means anybody who points a DNS record
 * at this address causes a certificate request. Let's Encrypt counts those
 * against the *registered domain*, currently 50 new certificates a week, so the
 * budget being spent is every real customer's.
 *
 * Caddy calls this on a cache miss, before the TLS handshake completes, which
 * fixes three properties of it:
 *
 *   - **It has to be fast.** One registry query, no session, no security
 *     listener worth the name. A slow answer here is a slow first request for a
 *     real customer.
 *   - **It cannot require a credential**, because Caddy has none to send.
 *     Restricting it therefore has to be done by where the request came from,
 *     which is the loopback check below.
 *   - **The status code is the whole answer.** Caddy reads 2xx as yes and
 *     anything else as no, so this returns 204 and 404 and never a body worth
 *     parsing.
 *
 * ## Why it cannot be used to enumerate tenants
 *
 * A yes-or-no about a hostname is, in the wrong hands, a way to ask whether a
 * customer exists. The endpoint is reachable only from the loopback address,
 * and Caddy asks it from inside this very container, so a request that arrived
 * over the network is refused before the registry is touched.
 *
 * That check reads `REMOTE_ADDR` from the server parameters rather than
 * `Request::getClientIp()`, and the difference matters. `getClientIp()` honours
 * `X-Forwarded-For` when trusted proxies are configured, which would let
 * somebody who can reach this instance through a proxy claim to be the loopback.
 * The unforwarded address cannot be set by a client at all.
 *
 * ## What counts as a hostname this installation serves
 *
 * Any tenant in the registry, plus the platform's own names from
 * `app.system_hosts`: the control plane, the signup host, and the loopback
 * entries. §4.3 is where that list is decided.
 *
 * **A suspended or lapsed tenant still gets a certificate**, deliberately. §4.6
 * gives a lapsed customer a page where they can read and export their data, and
 * serving that page over a certificate error would make the lapse look like a
 * fault of ours. Only a tenant that no longer exists is refused, and it is
 * refused because the registry has no row rather than because of its status.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TlsAskController
{
    /**
     * @param list<string> $systemHosts
     */
    public function __construct(
        private readonly TenantResolver $resolver,
        #[Autowire('%app.system_hosts%')]
        private readonly array $systemHosts,
    ) {
    }

    #[Route('/_tls/ask', name: 'tls_ask', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!self::isLoopback($request)) {
            return new Response(status: Response::HTTP_FORBIDDEN);
        }

        $domain = TenantResolver::normalize((string) $request->query->get('domain', ''));

        if ($domain === '') {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        if ($this->isSystemHost($domain)) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        try {
            $this->resolver->resolve($domain);
        } catch (UnknownTenantHostException) {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * The address the connection actually came from, never a forwarded header.
     */
    private static function isLoopback(Request $request): bool
    {
        $remote = $request->server->get('REMOTE_ADDR');

        return is_string($remote) && in_array($remote, ['127.0.0.1', '::1'], true);
    }

    /**
     * `app.system_hosts` holds an empty string when signup is switched off, and
     * an empty domain was already refused above, so nothing can match it here.
     */
    private function isSystemHost(string $domain): bool
    {
        foreach ($this->systemHosts as $host) {
            if ($host !== '' && TenantResolver::normalize($host) === $domain) {
                return true;
            }
        }

        return false;
    }
}

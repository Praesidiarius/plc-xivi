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

namespace Xivi\ControlPlane\Security;

use App\Tenancy\TenantResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Where the control plane is served, and the only place that answer is worked
 * out (XIV-57).
 *
 * Three things need it and they must not be allowed to disagree:
 *
 *   1. **The firewall.** `security.yaml` names this class as the
 *      `request_matcher` of the `control_plane` firewall, which is what makes
 *      that firewall host-scoped.
 *   2. **{@see \Xivi\ControlPlane\EventListener\ControlPlaneRequestListener}**,
 *      which refuses a control-plane path on any other host and refuses any
 *      other path on this one.
 *   3. **`app.system_hosts`**, which is what makes a request here resolve no
 *      tenant at all (§4, and `TenantRequestListener`). That one is wired in
 *      `config/services.yaml` by putting `%app.control_plane_host%` into the
 *      list rather than by calling anything here — the parameter is the shared
 *      fact, and this class is one of its readers.
 *
 * **A request matcher rather than the `host:` key in `security.yaml`, and the
 * reason is not style.** That key is a *regular expression*, so a hostname put
 * into it directly is a pattern in which every dot matches any character:
 * `control.example.com` also matches `controlXexample.com`. Nothing in this
 * system escapes it, and the failure would not be loud — a firewall that matches
 * one hostname too many is a firewall silently claiming a request that belongs
 * to somebody else. Comparing normalised strings cannot go wrong that way, and
 * it compares them through {@see TenantResolver::normalize()}, which is the same
 * function `TenantRequestListener` uses to decide whether a host is a system
 * host. That shared normalisation is the point: the firewall matches exactly the
 * hosts on which no tenant is resolved, and the two answers are derived from one
 * function rather than being two opinions that happen to agree today.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ControlPlaneHost implements RequestMatcherInterface
{
    /**
     * What every control-plane URL starts with.
     *
     * **The host is the boundary; this prefix is what makes the boundary
     * legible** — and, more usefully, what makes it enforceable before routing
     * has run. A listener that has to decide "is this a control-plane request"
     * on the way in cannot ask the router, because asking the router is what it
     * is trying to gate; a path prefix is a fact available from the raw request.
     *
     * It also keeps the two `access_control` rules honest. Those are global
     * rather than per firewall, so `^/control/` is how the control plane says
     * "operators only" without saying anything about the tenant application, and
     * the existing `^/` rule keeps meaning exactly what it meant.
     *
     * Yes, this reads as `control.example.com/control/`. The redundancy is the
     * price of a rule that can be checked without a router, and it is cheap: the
     * alternative was serving the control plane at `/` and then discovering that
     * `^/` in `access_control` had two meanings depending on the Host header.
     */
    public const string PATH_PREFIX = '/control';

    public function __construct(
        #[Autowire('%app.control_plane_host%')]
        private string $host,
    ) {
    }

    /**
     * Implements {@see RequestMatcherInterface} for the firewall map, and
     * deliberately matches on the host **only**.
     *
     * So every request arriving on the control-plane host belongs to the
     * control-plane firewall, including the ones asking for tenant paths. That
     * is what we want: a request there must never fall through to `main`, which
     * would authenticate it against `tenant_users` — and `main` has no `host:`
     * of its own, so it matches everything and would take anything this leaves
     * behind. What happens to a tenant path asked for on this host is then the
     * listener's business, and the answer is 404.
     */
    public function matches(Request $request): bool
    {
        return $this->servesRequest($request);
    }

    /** Whether this request arrived on the host the control plane is served on. */
    public function servesRequest(Request $request): bool
    {
        return TenantResolver::normalize($request->getHost()) === $this->normalisedHost();
    }

    /**
     * Whether this request is asking for a control-plane URL, whatever host it
     * arrived on.
     *
     * The trailing-slash-or-end test is not pedantry: without it a tenant route
     * called `/controlling` would read as a control-plane path and start
     * answering 404 on every tenant.
     */
    public function isControlPlanePath(Request $request): bool
    {
        $path = $request->getPathInfo();

        return $path === self::PATH_PREFIX || str_starts_with($path, self::PATH_PREFIX . '/');
    }

    /** The hostname the control plane answers on, normalised as tenancy normalises hostnames. */
    public function normalisedHost(): string
    {
        return TenantResolver::normalize($this->host);
    }
}

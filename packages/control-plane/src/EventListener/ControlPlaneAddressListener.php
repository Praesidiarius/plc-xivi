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

use App\Deployment\ControlPlaneAllowList;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Xivi\ControlPlane\Security\ControlPlaneHost;

/**
 * Refuses a control-plane request from an address the deployment has not listed
 * (XIV-124, docs/architecture/identity-and-access.md §8.9).
 *
 * ## The layer, and where it sits among the others
 *
 * §8.9's three layers all run *after* the request has arrived: the request
 * listener next to this one decides that a path exists, the firewall decides
 * which provider answers a credential, and `access_control` decides that a role
 * is present. This one decides nothing about the request at all. It looks at
 * where it came from and, when that is not somewhere the deployment named,
 * ends it.
 *
 * {@see ControlPlaneAllowList} holds the policy and the argument
 * for it, including why the address comes from `Request::getClientIp()` and
 * never from a header this code reads. This class is only the placement.
 *
 * ## Why the application and not the Caddyfile, and why that is not exclusive
 *
 * A web-server rule is genuinely stronger: a request refused by Caddy never
 * reaches PHP, never allocates a kernel, and cannot be undone by a bug anywhere
 * in this repository. If an operator wants that, they should have it, and
 * *Running an installation → The control plane* on the documentation site says
 * so and shows the block.
 *
 * It is not what shipped, for three reasons that are about this codebase rather
 * than about web servers. **It travels with the code**: `bin/deploy` replaces
 * containers built from this repository, and a rule living in a Caddyfile that a
 * deployment maintains separately is a rule that can be a release behind, or
 * absent, with nothing here able to tell. **It is testable**:
 * `ControlPlaneAllowListTest` boots a kernel, sends a forged `X-Forwarded-For`
 * and asserts what happens, and the equivalent assertion about a web-server
 * configuration would need the web server, which the suite does not run.
 * **And it inherits `TRUSTED_PROXIES`**: Caddy would have to be told separately
 * which upstream may speak for a client, in its own syntax, which is a second
 * copy of [XIV-93]'s decision and therefore a second place for it to be wrong.
 *
 * The two compose. An operator who configures both gets a refusal at the edge
 * and a refusal in the application, and the second one is what still holds the
 * day somebody puts a load balancer in front of the first.
 *
 * ## The refusal says nothing and the log says everything
 *
 * A bare **403** with an empty body, which is the line
 * {@see \App\Deployment\EventListener\UntrustedHostListener} already draws for
 * the trusted-host 400 and is drawn here for the same reason rather than a new
 * one: whoever is on the far end of a refused request is by definition not
 * somebody this installation admits, and a body naming the variable, the list or
 * even the fact that an allow-list exists would be telling the one audience that
 * should not be told. The diagnosis goes where the operator is — `stderr`, at
 * `error` level, which is the level `config/packages/monolog.yaml` flushes the
 * production buffer at.
 *
 * **A 403 rather than the 404 the listener beside this one uses.** That listener
 * answers 404 because the path genuinely is not there on that host. Here the
 * path is there and the caller is not welcome, and pretending otherwise would
 * mean an operator who has locked themselves out — the failure this feature is
 * most likely to cause — sees exactly what they would see if the control plane
 * had never been deployed. A 403 is the answer that can be told apart from a
 * broken deployment at two in the morning, and it reveals nothing the caller did
 * not already know by having reached the host at all.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 101)]
final readonly class ControlPlaneAddressListener
{
    public function __construct(
        private ControlPlaneHost $controlPlane,
        private ControlPlaneAllowList $allowList,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Priority 101: **before `TenantRequestListener` (100) and before
     * `ControlPlaneRequestListener` (99)**, which makes this the first thing in
     * the application to look at a control-plane request.
     *
     * Outermost is the point of it. This decision needs nothing that tenancy
     * establishes — no tenant resolves on this host by construction — so
     * refusing here means an address that is not on the list cannot make the
     * installation consult its registry, resolve a route, touch a session or
     * build a firewall listener. Every layer that could be surprising is behind
     * a decision made from two facts available on the raw request.
     *
     * It also means the refusal covers the shared paths that
     * `ControlPlaneRequestListener` deliberately stands aside for — assets, the
     * importmap, the profiler. That asymmetry is wanted: those are exempt from
     * *which host serves them* because they belong to nobody, and they are not
     * exempt from *who may connect*, so an off-list caller gets the same 403 for
     * every path on this host rather than a map of which ones exist.
     */
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->allowList->isConfigured()) {
            // The unconfigured case returns before anything is read, which is
            // what makes "an installation that sets nothing behaves exactly as
            // today" a property of the code rather than a claim about it —
            // `getHost()` is not even called, so this cannot change which
            // listener a suspicious host is refused by.
            return;
        }

        $request = $event->getRequest();

        if (!$this->controlPlane->servesRequest($request)) {
            // Every customer's request, and the signup host's, leave here. The
            // allow-list is about the surface that can see every tenant;
            // applying it to the tenants themselves would be an installation
            // that serves nobody.
            return;
        }

        // **The one line that matters.** `getClientIp()` resolves through
        // `TRUSTED_PROXIES` and the header list §4.3 decided on, so a forged
        // `X-Forwarded-For` is ignored unless it came from an address the
        // deployment already trusts to speak for a client. Reading the header
        // here instead would make this allow-list a suggestion.
        $address = $request->getClientIp();

        if ($this->allowList->admits($address)) {
            return;
        }

        $this->refuse($request->server->get('REMOTE_ADDR'), $address);

        // An empty body, and `setResponse()` rather than an exception. The
        // exception route would hand this to the error listener and, for a
        // request the firewall has since claimed, to the firewall's entry point
        // — which would answer a refused address with the sign-in page it is
        // being refused from. A response set here stops propagation and is what
        // is sent.
        $event->setResponse(new Response('', Response::HTTP_FORBIDDEN));
    }

    /**
     * The one log line, addressed to the operator rather than to a monitor.
     *
     * **Both addresses are named, and the second one is the point.** The
     * resolved client address is what the decision was made on; `REMOTE_ADDR` is
     * the peer the connection actually came from. When they differ, there is a
     * proxy in front and `TRUSTED_PROXIES` has been set; when they are equal and
     * the operator swears they are behind a load balancer, `TRUSTED_PROXIES` has
     * *not* been set and every request in the installation is being attributed
     * to the balancer — which is a misconfiguration this line can hand somebody
     * in one glance and which would otherwise present as "the allow-list refuses
     * my office and my office address is correct".
     */
    private function refuse(mixed $remoteAddress, ?string $clientAddress): void
    {
        if ($this->logger === null) {
            return;
        }

        $advice = sprintf(
            '%s is "%s", and only those addresses and ranges reach the control plane. The address '
            . 'above is what Symfony resolved through TRUSTED_PROXIES and the forwarded headers '
            . 'config/packages/framework.yaml believes — if this installation is behind a load '
            . 'balancer and the address looks like the balancer rather than the caller, '
            . 'TRUSTED_PROXIES is what is missing. "bin/console deploy:check-control-plane '
            . '--address=..." answers this question without waiting for a request. See '
            . 'docs/architecture/identity-and-access.md §8.9.',
            ControlPlaneAllowList::VARIABLE,
            implode(',', $this->allowList->entries()),
        );

        if ($this->allowList->rejected() !== []) {
            // Said only when it is true, and said loudly when it is, because a
            // rejected entry is the single most likely reason an operator is
            // reading this line about their own address.
            $advice .= sprintf(
                ' %d entr%s in that variable %s not an address or a CIDR range and admit%s nobody: "%s".',
                \count($this->allowList->rejected()),
                \count($this->allowList->rejected()) === 1 ? 'y' : 'ies',
                \count($this->allowList->rejected()) === 1 ? 'is' : 'are',
                \count($this->allowList->rejected()) === 1 ? 's' : '',
                implode('", "', $this->allowList->rejected()),
            );
        }

        $this->logger->error(
            'Refused a control-plane request from {client_ip}: it is not in {variable}. {advice}',
            [
                'client_ip' => $clientAddress ?? '(no address)',
                'remote_addr' => \is_string($remoteAddress) ? $remoteAddress : '(none)',
                'variable' => ControlPlaneAllowList::VARIABLE,
                'advice' => $advice,
                'control_plane_allow_list' => $this->allowList->entries(),
            ],
        );
    }
}

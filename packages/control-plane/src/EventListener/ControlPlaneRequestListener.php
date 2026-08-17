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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Xivi\ControlPlane\Security\ControlPlaneHost;

/**
 * Keeps the control plane and the tenant application on their own hosts, in both
 * directions (XIV-57).
 *
 * Two refusals, and they are not the same refusal seen twice:
 *
 *   * **A control-plane path on a tenant hostname does not exist.** This is the
 *     one the ticket is about. Without it, `acme.example.com/control/` is an
 *     ordinary route under the `main` firewall, guarded only by the
 *     `access_control` rule demanding `ROLE_OPERATOR` — which is a check on a
 *     role rather than on whose installation this is, and a role is a column in
 *     a customer's own database. Nothing stops a customer's administrator
 *     writing a string into their own `app_user.roles`, and if they did, the
 *     only thing between them and the control plane would be that string not
 *     being the one we happened to pick.
 *
 *   * **A tenant path on the control-plane hostname does not exist either.** The
 *     control-plane firewall matches on host alone (see {@see ControlPlaneHost}),
 *     so every request there is inside it, including one asking for `/records/…`.
 *     No tenant resolves on this host — it is in `app.system_hosts` — so the
 *     tenant connection is deliberately unusable and such a request would end in
 *     a 500 from a controller wondering where its database went. The
 *     `access_control` rules would already turn most of them away, an operator
 *     holding no `ROLE_USER`, but "most" is the wrong word for a boundary and a
 *     403 is the wrong answer for a page that is not there.
 *
 * **404 rather than 403 in both cases, deliberately.** These paths do not exist
 * on these hosts, in the plainest sense: the router would refuse them too if it
 * could be told about hostnames from an environment variable, which it cannot —
 * Symfony forbids env placeholders in routing configuration, so a `host:` on the
 * routes themselves would have to be a value compiled into the source. A 403
 * would additionally confirm to a customer poking at their own installation that
 * there is something at `/control/` worth being refused from.
 *
 * **What is exempt from the second refusal, and why it has to be.** Stylesheets,
 * the importmap and the web debug toolbar are served on every host because they
 * belong to neither side: they are not a customer's data and not the platform's,
 * they need no session, and the `dev` firewall in `security.yaml` already stands
 * aside for exactly that set. So the same parameter names them in both places
 * (`app.shared_paths`) rather than this class carrying its own copy of a pattern
 * that would then drift — a control-plane page with no CSS because somebody
 * renamed an asset directory in one file of two is a silly way to find out.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 99)]
final readonly class ControlPlaneRequestListener
{
    public function __construct(
        private ControlPlaneHost $controlPlane,
        /** @see the class docblock; the same value is the `dev` firewall's pattern */
        #[Autowire('%app.shared_paths%')]
        private string $sharedPaths,
    ) {
    }

    /**
     * Priority 99: immediately after `TenantRequestListener` (100) and before
     * anything that reads a session or a route.
     *
     * After tenancy rather than before it on purpose. Tenancy is what rejects an
     * unknown hostname and what clears the tenant connection on a system host,
     * and both of those should have happened before this decides anything — a
     * request on a hostname belonging to nobody is a 404 for that reason, not
     * for this one, and the message it carries says so.
     */
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $onControlPlaneHost = $this->controlPlane->servesRequest($request);
        $wantsControlPlane = $this->controlPlane->isControlPlanePath($request);

        if ($onControlPlaneHost === $wantsControlPlane) {
            return;
        }

        // Assets and dev tooling, which belong to neither side and are asked for
        // on whichever host drew the page. Checked only here, in the direction
        // that could refuse them: a request for `/control/…` is never one of
        // these, because the pattern and the prefix cannot both match.
        if (!$wantsControlPlane && preg_match('{' . $this->sharedPaths . '}', $request->getPathInfo()) === 1) {
            return;
        }

        if ($wantsControlPlane) {
            throw new NotFoundHttpException(sprintf(
                'The control plane is not served on "%s".',
                $request->getHost(),
            ));
        }

        throw new NotFoundHttpException(sprintf(
            'Only the control plane is served on "%s"; this installation\'s customers are elsewhere.',
            $request->getHost(),
        ));
    }
}

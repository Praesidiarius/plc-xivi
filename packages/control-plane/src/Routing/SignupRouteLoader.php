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

namespace Xivi\ControlPlane\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\RouteCollection;
use Xivi\ControlPlane\Controller\SignupApiController;
use Xivi\ControlPlane\Controller\SignupConfirmationController;
use Xivi\ControlPlane\Controller\SignupPageController;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Signup\SignupApiKey;
use Xivi\ControlPlane\Signup\SignupHost;
use Xivi\ControlPlane\Signup\SignupPage;

/**
 * Whether the public signup endpoint exists at all (XIV-64).
 *
 * ### "Off" means no route, not a route that answers 404
 *
 * That is the acceptance criterion, and it is a stronger statement than it
 * sounds. A registered route is a controller the router can reach: it is in the
 * compiled matcher, it is in `debug:router`, it is one misplaced
 * `access_control` line or one bug in a listener away from running. A route that
 * was never loaded cannot be reached by any of those, and the endpoint's
 * *absence* is then a property of the routing table rather than of a check
 * somebody has to keep correct.
 *
 * Symfony has no way to express that in routing configuration, and the reason is
 * worth writing down because it looks like an omission: **environment
 * placeholders are forbidden in routing**, exactly as they are for the
 * control-plane host (see `ControlPlaneRequestListener`, which is a listener for
 * the same reason). `when@dev` is about the kernel's environment rather than
 * about a deployment's choice, and a `condition:` still registers the route. A
 * loader is the framework's own answer: it runs at route-load time, it can read
 * anything a service can read, and what it returns *is* the routing table.
 *
 * ### It also sets the host, which removes a listener nobody would have to write
 *
 * The routes carry no `host:` of their own, because a hostname in routing
 * configuration would have to be compiled into the source. Setting it here means
 * the signup endpoint is matched on the signup host and nowhere else — so the
 * same URL on a customer's hostname, or on the control plane's, is not refused
 * by a rule but is genuinely not a route. `RouteCollection::setHost()` quotes
 * static text, unlike `security.yaml`'s `host:` key, so the dots in a hostname
 * are dots.
 *
 * ### Two switches, one loader (XIV-65)
 *
 * [XIV-65] added a landing page and a second switch for it, and the second switch
 * is read here rather than anywhere else so that the two compose in one place.
 * `SIGNUP_HOST` decides whether there is an intake and where; `SIGNUP_PAGE`
 * decides whether this installation also draws the form. The states that fall out
 * are the three a deployment asks for:
 *
 *     SIGNUP_HOST empty            → nothing at all, whatever SIGNUP_PAGE says
 *     SIGNUP_HOST set, page false  → the intake and the confirmation page
 *     SIGNUP_HOST set, page true   → those, and the landing page as well
 *
 * The early return below is what makes the first line true for both switches at
 * once: a page whose only job is to post to an intake cannot outlive the intake,
 * and the fourth state is therefore not a refusal but an unsayable thing. See
 * {@see SignupPage} for the argument in full, and for why the page shares the
 * intake's hostname rather than having one of its own.
 *
 * **The page is switched off the same way the endpoint is** — by not being
 * loaded. A `SIGNUP_PAGE=false` deployment has no `signup_page` route, no
 * `signup_page_submit` and no `signup_page_name`; they are absent from the
 * compiled matcher and from `debug:router`, exactly as the whole feature is when
 * the host is empty. That is the acceptance criterion this ticket repeats from
 * [XIV-64], and it is why the page is a plain controller rather than a live
 * component — a component answers at a route this feature does not own, and could
 * not have been switched off this way. `SignupPageController`'s docblock has that
 * argument.
 *
 * ### Two refusals that stop the build rather than the request
 *
 * Both are configuration mistakes that would otherwise be discovered by their
 * consequences, so they are thrown from here — which means a deployment fails to
 * warm its cache rather than serving something wrong.
 *
 *   * **The signup host may not be the control-plane host.** They are separate
 *     hostnames on purpose ({@see SignupHost}); if they are the same, an
 *     anonymous endpoint is being served on the operator console's hostname and
 *     the firewall ordering decides which of the two `security.yaml` blocks wins.
 *     That is not a thing to leave to an ordering.
 *   * **A signup host with no shared secret is an open endpoint.**
 *     {@see SignupApiKey} already fails closed at request time, so this refusal
 *     is belt and braces — and it is the half that says so at deploy time
 *     instead of at the first support ticket about signups not working.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
// Tagged by hand rather than by autoconfiguration, which does not cover this
// one: the framework autoconfigures `AbstractController` and a long list of
// interfaces, and `Config\Loader\LoaderInterface` is not among them. Without the
// tag the loader is not in the router's resolver and `config/routes.yaml` fails
// with "Cannot load resource "."", which is a puzzling way to find out.
#[AutoconfigureTag('routing.loader')]
final class SignupRouteLoader extends Loader
{
    /** What `config/routes.yaml` names as the `type` of its signup resource. */
    public const string TYPE = 'xivi_signup';

    /**
     * **Every controller of the signup surface, and the list is load-bearing
     * twice** (XIV-65).
     *
     * This loader reads it to decide what to import, and
     * {@see \Xivi\ControlPlane\DependencyInjection\SignupRoutesComeOnlyFromTheLoaderPass}
     * reads it to make sure *nothing else* imports the same classes. Two readers,
     * one list, because the second reader existing at all is the answer to a bug
     * this class silently had — see that pass for what it was.
     */
    public const array CONTROLLERS = [
        SignupApiController::class,
        SignupConfirmationController::class,
        SignupPageController::class,
    ];

    /**
     * The ones the page switch owns, as opposed to the endpoint's.
     *
     * Separate from the list above rather than filtered out of it, because the
     * pass has to untag *all* signup controllers whatever either switch says: a
     * page whose routes are absent from this collection and present in the
     * routing table anyway is precisely the failure both are guarding against.
     */
    private const array PAGE_CONTROLLERS = [SignupPageController::class];

    public function __construct(
        private readonly SignupHost $host,
        private readonly ControlPlaneHost $controlPlane,
        private readonly SignupApiKey $apiKey,
        private readonly SignupPage $page,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();

        if (!$this->host->isEnabled()) {
            // Switched off. Nothing is registered, so nothing exists to be
            // reached, refused, listed or forgotten about.
            return $routes;
        }

        $host = $this->host->normalisedHost();

        if ($host === $this->controlPlane->normalisedHost()) {
            throw new \LogicException(sprintf(
                'SIGNUP_HOST and CONTROL_PLANE_HOST are both "%s". '
                . 'The public signup endpoint must not be served on the operator console\'s hostname.',
                $host,
            ));
        }

        if (!$this->apiKey->isConfigured()) {
            throw new \LogicException(
                'SIGNUP_HOST is set but XIVI_SIGNUP_SECRET is empty, which would publish an endpoint '
                . 'with no credential in front of it. Set the secret, or unset SIGNUP_HOST to switch signup off.',
            );
        }

        foreach (self::CONTROLLERS as $controller) {
            // **The landing page, only if this deployment draws its own**
            // (XIV-65). Skipped here rather than loaded separately, so that the
            // host, the scheme and the "off means absent" property below are
            // stated once and cannot come to differ between the page and the
            // endpoint it posts to.
            if (\in_array($controller, self::PAGE_CONTROLLERS, true) && !$this->page->isEnabled()) {
                continue;
            }

            $routes->addCollection($this->import($controller, 'attribute'));
        }

        $routes->setHost($host);

        // **https only**, and not because a deployment might forget. The API
        // request carries a shared secret in a header and the confirmation link
        // is how somebody proves control of a mailbox; neither belongs on a
        // plaintext connection, and a redirect is a better answer than serving
        // it and hoping.
        $routes->setSchemes(['https']);

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}

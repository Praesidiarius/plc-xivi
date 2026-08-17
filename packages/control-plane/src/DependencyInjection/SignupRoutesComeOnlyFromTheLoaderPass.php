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

namespace Xivi\ControlPlane\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Xivi\ControlPlane\Routing\SignupRouteLoader;

/**
 * **The signup routes come from {@see SignupRouteLoader} and from nowhere else**
 * (XIV-65).
 *
 * ### The bug this exists to close, which was live and is worth stating exactly
 *
 * [XIV-64]'s central acceptance criterion is that switching signup off means *no
 * route is registered* — not a route that answers 404. `SignupRouteLoader` keeps
 * that promise about the collection it returns, and `SignupRouteLoaderTest`
 * proves it about that collection. It was not true of the routing table.
 *
 * Symfony autoconfigures **every class carrying a `#[Route]` attribute** with the
 * `routing.controller` tag, and `config/routes.yaml`'s `resource:
 * routing.controllers` loads all of them through `AttributeServicesLoader`. The
 * signup controllers carry `#[Route]` attributes, so they were loaded twice: once
 * by this feature's loader, with the configured host and `https` stamped on, and
 * once by the framework's, **with neither**. Route names are unique in a
 * collection, so whichever load came last won — and it happened to be the
 * loader's, purely because `signup:` sat below `controllers:` in a YAML file.
 *
 * Two things followed, and both were verified against `debug:router` rather than
 * reasoned about:
 *
 *   * **With `SIGNUP_HOST` empty — the shipped default, the "neither" state, the
 *     one a company self-hosting relies on — every signup route was still in the
 *     table**, on every hostname the installation serves, over plain HTTP as
 *     readily as TLS. The loader returned nothing and the framework had already
 *     registered the lot. What kept that from being an open intake was
 *     {@see \Xivi\ControlPlane\Signup\SignupApiKey} failing closed on an unset
 *     secret — a defence in depth doing the whole job on its own, which is
 *     exactly the situation "off means the route does not exist" was written to
 *     avoid.
 *   * **Reordering two keys in `config/routes.yaml` silently unbound the host**
 *     of the entire feature. Found that way: moving `signup:` above `controllers:`
 *     — to let the landing page win `/` against the application's dashboard —
 *     made the framework's host-less copies the survivors, and put the anonymous
 *     intake on every customer's hostname.
 *
 * ### What this does about it
 *
 * Removes the `routing.controller` tag from every class in
 * {@see SignupRouteLoader::CONTROLLERS}, so the framework's loader never sees
 * them. They keep `controller.service_arguments` and are still perfectly ordinary
 * controller services; what they stop being is *self-registering*. After this,
 * the loader is the only thing in the process that can put a signup route in the
 * table, which makes every property it enforces — the host, the scheme, and both
 * switches — a property of the routing table rather than of an import order.
 *
 * **A compiler pass rather than a line of configuration**, because the tag is
 * added by autoconfiguration at compile time and there is no declarative way to
 * take one back off. It runs at priority 10: after the autoconfiguration passes,
 * which are at 100 and are what add the tag, and before Symfony's own
 * `RoutingControllerPass` at 0, which is what reads it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SignupRoutesComeOnlyFromTheLoaderPass implements CompilerPassInterface
{
    /** The tag `AttributeServicesLoader` is built from; see the class docblock. */
    private const string TAG = 'routing.controller';

    public function process(ContainerBuilder $container): void
    {
        foreach (SignupRouteLoader::CONTROLLERS as $controller) {
            if (!$container->hasDefinition($controller)) {
                // A production build that has excluded one, or a test container
                // built without this bundle's services. Nothing to untag, and
                // nothing to complain about — the absence is the same outcome.
                continue;
            }

            $container->getDefinition($controller)->clearTag(self::TAG);
        }
    }
}

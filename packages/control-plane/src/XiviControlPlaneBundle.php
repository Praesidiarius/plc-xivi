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

namespace Xivi\ControlPlane;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Xivi\ControlPlane\DependencyInjection\SignupRoutesComeOnlyFromTheLoaderPass;

/**
 * **The administration surface, and only that** (XIV-60, docs/architecture.md §3).
 *
 * This package is not "the control plane". The control plane is a database, and
 * every request a tenant makes reads it before it can know whose request it is —
 * so the part of it that answers "which customer owns this hostname, and what is
 * the credential to connect to their database" cannot live out here. That part
 * stayed in the application as `App\Registry`, because an instance serving
 * customers cannot boot without it.
 *
 * What moved is everything an *operator* touches: provisioning and deprovisioning,
 * tenant migrations, the module catalogue's write side, operator identity and its
 * firewall, the tenant list, and usage collection. None of it is on the path of a
 * customer's request, and all of it is on the path of somebody who is allowed to
 * see every customer at once.
 *
 * **The direction is the point, and it is the opposite of every other package
 * here.** A module may depend on core and on nothing else; this may depend on the
 * *application* — `App\Registry`, `App\Tenancy`, `App\Tenant` — and the
 * application may never depend on it. That is the same shape `packages/xivi-mate`
 * has and for a related reason: a tool that sits above the thing it operates on
 * can reach into it, and the moment the arrow turns round, the application can no
 * longer be built without the surface. deptrac enforces both halves.
 *
 * There is no `xivi/app` in this package's `composer.json` to depend on, because
 * the application is a project rather than a package. That is a gap in what
 * Composer can express here, not a licence to reverse the arrow — the rule lives
 * in `deptrac.yaml`, which is the file that can actually state it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class XiviControlPlaneBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');
    }

    /**
     * **What the application used to have to say about this package** (XIV-96,
     * §4.4).
     *
     * Two things named control-plane classes and directories from inside the
     * application's own configuration, and both of them stopped the container
     * from compiling when the package was absent — which is why "build an image
     * without the administration surface" was not a matter of dropping a
     * Composer requirement. They are here now, and the application no longer
     * mentions either.
     *
     *   * `config/security.php` — the `operators` provider and the
     *     `control_plane` and `signup` firewalls. Read that file for why being
     *     prepended is what now guarantees XIV-57's ordering invariant rather
     *     than threatening it.
     *   * `config/doctrine.php` — the `Xivi\ControlPlane\Entity` mapping on the
     *     `control` entity manager. DoctrineBundle checks that a mapping's
     *     directory exists at compile time, so this one failed a build just as
     *     hard as the security configuration did and rather less legibly.
     *
     * **Prepended rather than loaded**, in both cases, because the merge order
     * is the point: configuration prepended by a bundle is merged ahead of
     * everything the application loaded, so the firewalls below cannot be
     * reordered from `security.yaml` and the mapping cannot be shadowed by it.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/security.php');
        $container->import(__DIR__ . '/../config/doctrine.php');
    }

    /**
     * One compiler pass, and its docblock is the argument for it.
     *
     * Priority 10 rather than the default 0: autoconfiguration adds the tag it
     * removes at priority 100, and Symfony's own `RoutingControllerPass` reads
     * that tag at 0. Ten is the only window where taking the tag off means
     * anything.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(
            new SignupRoutesComeOnlyFromTheLoaderPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            10,
        );
    }
}

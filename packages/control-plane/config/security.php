<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Xivi\ControlPlane\Entity\Operator;

/*
 * **Who the control-plane firewall asks about a credential** (XIV-96, §4.4).
 *
 * One provider, prepended, and the reason it is here rather than in the
 * application's `security.yaml` is the reason the whole ticket exists: that file
 * named `Xivi\ControlPlane\Entity\Operator`, so the application's security
 * configuration could not be compiled without this package installed, and an
 * image without the administration surface therefore could not be built at all.
 *
 * **The firewalls that use it are next door in `firewalls.php` and are not
 * prepended**, which looks inconsistent and is Symfony's decision rather than
 * ours: `security.firewalls` is `disallowNewKeysInSubsequentConfigs()`, so every
 * firewall in the installation has to be named by a single configuration source.
 * `security.providers` carries no such restriction, so this half gets to be
 * contributed the tidy way. Read `firewalls.php` for the other half and for the
 * ordering invariant that survives the split.
 */

return static function (ContainerConfigurator $container): void {
    $container->extension('security', [
        'providers' => [
            // The other half of `tenant_users` in the application's
            // security.yaml (XIV-57, §8.9). `manager_name` is omitted rather
            // than set to `control`, because the control plane is the *default*
            // entity manager — naming it would be correct and would also suggest
            // there is a choice being made here per request, which is exactly
            // the wrong idea to leave lying next to a provider that really does
            // make one.
            'operators' => [
                'entity' => [
                    'class' => Operator::class,
                    'property' => 'email',
                ],
            ],
        ],
    ], prepend: true);
};

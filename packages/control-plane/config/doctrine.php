<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * **The administration surface's own entity mapping** (XIV-96, §3.1, §4.4).
 *
 * An operator, a signup request, and what a tenant was measured to be using.
 * These rows are in the control-plane database beside the registry's, and the
 * split between the two mappings is XIV-60's: what a *customer's* request reads
 * is `App\Registry\Entity` and stays in the application, and what an *operator*
 * touches is this.
 *
 * It moved out of `config/packages/doctrine.yaml` for the same reason the
 * firewalls moved out of `security.yaml`, and it is the less obvious of the two
 * because it looks like data rather than like code. DoctrineBundle validates
 * that a mapping's `dir` exists while the container is being built, so an
 * application configuring `packages/control-plane/src/Entity` **cannot compile**
 * on a checkout where that directory is not there. That is the same failure the
 * security configuration had, arriving from a different file and a good deal
 * less legibly: the message names a directory rather than a class.
 *
 * `is_bundle: false` even though this *is* a bundle: the path is spelled out for
 * the same reason the registry's is next door, and a bundle-relative mapping
 * would only be shorter. The migrations are not here and must not be —
 * `migrations/control/` stays in the application under
 * `DoctrineMigrations\ControlPlane`, which is the namespace recorded in the
 * `doctrine_migration_versions` table, and no table moved when the classes did
 * (§3.1).
 */

return static function (ContainerConfigurator $container): void {
    $container->extension('doctrine', [
        'orm' => [
            'entity_managers' => [
                'control' => [
                    'mappings' => [
                        'ControlPlane' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => '%kernel.project_dir%/packages/control-plane/src/Entity',
                            'prefix' => 'Xivi\ControlPlane\Entity',
                            'alias' => 'ControlPlane',
                        ],
                    ],
                ],
            ],
        ],
    ], prepend: true);
};

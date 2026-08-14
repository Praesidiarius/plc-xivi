<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Core's services are autowired, but two of their arguments cannot be resolved
 * here: the entity manager and the connection. Core has no opinion about which
 * database it operates on — the application binds those to the tenant's, in
 * config/services.yaml. Guessing would mean writing customer records into the
 * control plane.
 */

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->load('Xivi\\Core\\', __DIR__ . '/../src/')
            ->exclude([
                __DIR__ . '/../src/Entity/',
                __DIR__ . '/../src/Module/{ModuleBlueprint,CollectionBlueprint,FieldBlueprint}.php',
                __DIR__ . '/../src/Record/Record.php',
                __DIR__ . '/../src/Validation/UniqueFieldValue.php',
                __DIR__ . '/../src/XiviCoreBundle.php',
            ]);
};

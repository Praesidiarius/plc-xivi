<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->load('Xivi\\Core\\', __DIR__ . '/../src/')
            ->exclude([
                __DIR__ . '/../src/Entity/',
                __DIR__ . '/../src/Metadata/',
                __DIR__ . '/../src/Record/Record.php',
                __DIR__ . '/../src/XiviCoreBundle.php',
            ]);
};

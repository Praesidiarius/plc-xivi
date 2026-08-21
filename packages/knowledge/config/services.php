<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
        ->load('Xivi\\Knowledge\\', __DIR__ . '/../src/')
            ->exclude([
                __DIR__ . '/../src/XiviKnowledgeBundle.php',
                // One card of the index (XIV-177). An answer the provider hands
                // back, not a service: the container would otherwise try to
                // autowire a `string $value` into it.
                __DIR__ . '/../src/Index/TopicCard.php',
            ]);
};

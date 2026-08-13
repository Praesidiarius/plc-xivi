<?php

declare(strict_types=1);

namespace Xivi\Core;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The engine: metadata, field types, record storage.
 *
 * Core knows nothing about any module, and nothing about tenancy — it is handed
 * an entity manager and a connection, and the application decides which database
 * those point at. Both directions matter: the boundary is checked by deptrac in
 * CI (docs/architecture.md §3).
 */
final class XiviCoreBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');
    }
}

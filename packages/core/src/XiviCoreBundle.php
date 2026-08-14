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
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class XiviCoreBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');
    }
}

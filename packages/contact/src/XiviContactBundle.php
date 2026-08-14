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

namespace Xivi\Contact;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The first module built on the engine, and the reason the engine is allowed to
 * exist at all: no abstraction in core is kept unless something here needs it
 * (docs/architecture.md §1).
 *
 * It may depend on core. It may not depend on another module, and core may not
 * depend on it — enforced by deptrac rather than by good intentions.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class XiviContactBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');
    }
}

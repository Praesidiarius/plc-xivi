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

namespace Xivi\Article;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The second module, and the first one nobody had to change the engine for.
 *
 * Contact proved the engine could describe a module (§1); this one is the check
 * that it was not built to describe *that* module. It brought two field types
 * with it and nothing else — no controller, no entity, no template — which is
 * the claim being tested.
 *
 * It may depend on core. It may not depend on another module, and core may not
 * depend on it — enforced by deptrac rather than by good intentions.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class XiviArticleBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');
    }
}

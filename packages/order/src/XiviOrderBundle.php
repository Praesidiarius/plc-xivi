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

namespace Xivi\Order;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The third module, and the first one that is *about* other modules.
 *
 * Contact and article each stand on their own; an order stands only by pointing
 * at both. That is why it is worth building — it is the test §1's claim has been
 * waiting for, and anything it needs beyond a declaration is a finding about the
 * engine rather than about orders.
 *
 * It may depend on core. It may not depend on another module, and core may not
 * depend on it — enforced by deptrac rather than by good intentions. Note what
 * that means here: an order line points at an *article* without this package
 * depending on the article package. The link is a string in a definition, and
 * the customer's own installation is what makes it resolve (§3, §7.6).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class XiviOrderBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');
    }
}

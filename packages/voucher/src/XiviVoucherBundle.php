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

namespace Xivi\Voucher;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The voucher module (XIV-103).
 *
 * The fifth module, and the first one whose interesting part is not its shape.
 * Contact proved the engine could describe a module, Article proved the engine
 * was not built around Contact, Order proved a module could be mostly
 * relationships — and this one carries a **counter with a rule in it**, which is
 * the first thing a module has needed that a declaration could not express.
 * Everything about what a voucher *is* is still a blueprint; everything about
 * how many times it may be used is a table and one statement, and the two do not
 * meet except through a record id.
 *
 * It may depend on core. It may not depend on another module, and core may not
 * depend on it — enforced by deptrac rather than by good intentions. That rule
 * is worth restating here because this module points at the article module and
 * still imports nothing from it: the link is a key in a declaration and a
 * customer's own installation is what makes it resolve (§3, §7.6).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class XiviVoucherBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');
    }
}

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

namespace Xivi\Invoice;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The fourth module, and the one that had to be free.
 *
 * Order was the first module that was mostly *relationships*, and building it
 * cost the engine six tickets. This one is the same shape — it names an order,
 * its lines name articles, it has a number, a lifecycle, totals and a VAT table
 * — so the honest measure of that work is how little is left to write here.
 *
 * The answer is a declaration and a translation file. Nothing in this package is
 * a class the engine calls: the totals are declared (XIV-19), the number is
 * declared (XIV-15), the lifecycle is declared (XIV-14), and being made *from*
 * an order is declared too. That is §1's claim, tested by a module nobody was
 * tempted to write code for.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class XiviInvoiceBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');
    }
}

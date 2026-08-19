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

namespace Xivi\Knowledge;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * The sixth module, and the first one that added nothing to the engine at all.
 *
 * Every module before this brought *something* with it. Contact proved the
 * engine could describe a module; article brought two field types; order and
 * invoice brought line totals, seeding and document numbers; voucher brought a
 * field type and a counter. This one brought a declaration, a translation file
 * and this bundle — which is the claim §1 has been making since the beginning,
 * finally tested by a module that had no excuse to reach for anything.
 *
 * That is the whole reason the package exists as a package rather than as a few
 * rows somebody types into the metadata editor: a module a *customer* assembles
 * by hand is not installable by the next customer, is not in the store (§6.3),
 * and has no labels in a second language. Being a package is what makes it a
 * product rather than one tenant's configuration.
 *
 * It may depend on core. It may not depend on another module, and core may not
 * depend on it — and here that boundary costs nothing to keep, because this
 * module imports exactly one thing from core and it is the interface below.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class XiviKnowledgeBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.php');
    }
}

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
 * The sixth module, and for eight days the first one that added nothing to the
 * engine at all.
 *
 * Every module before this brought *something* with it. Contact proved the
 * engine could describe a module; article brought two field types; order and
 * invoice brought line totals, seeding and document numbers; voucher brought a
 * field type and a counter. This one brought a declaration, a translation file
 * and this bundle — which is the claim §1 has been making since the beginning,
 * finally tested by a module that had no excuse to reach for anything.
 *
 * **Then it wanted a card per topic** (XIV-168), and the interesting part is
 * where that went. First into the engine, as a general grouping capability with
 * this module as its only declaration; then straight back out again (XIV-177,
 * XIV-178), because §1's rule is that an abstraction is earned by a second
 * concrete use case and there was not one. What the engine kept is a seam that
 * knows a template name and some data; what this package gained is `src/Index/`
 * and `templates/index/`, its first code and its first markup. The claim is
 * smaller now and is the one worth having: **a module can change how its own
 * records are drawn without the engine learning what it drew.**
 *
 * That is also the whole reason the package exists as a package rather than as a
 * few rows somebody types into the metadata editor: a module a *customer*
 * assembles by hand is not installable by the next customer, is not in the store
 * (§6.3), has no labels in a second language, and cannot ship a template at all.
 * Being a package is what makes it a product rather than one tenant's
 * configuration.
 *
 * It may depend on core. It may not depend on another module, and core may not
 * depend on it, and here that boundary is kept in the two places it is easy to
 * lose: `src/Index/TopicCards.php` asks core for the addresses it needs rather
 * than naming a route, and `tests/Unit/ModuleTemplatesKeepTheBoundaryTest.php`
 * checks the template, which deptrac cannot read.
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

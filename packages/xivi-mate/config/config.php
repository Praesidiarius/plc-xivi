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

/*
 * Services for Mate's own container, not this application's.
 *
 * Mate autowires and makes public every class it finds an #[McpTool] on, but it
 * cannot invent a constructor argument it has never heard of — so the bridge the
 * tools depend on has to be declared, exactly as the Monolog and Symfony bridges
 * declare theirs. `%mate.root_dir%` is set by Mate's ContainerFactory and is the
 * project root, which is the one thing this file knows that the classes cannot
 * work out for themselves: `getcwd()` is whatever the MCP client happened to
 * launch the server from.
 *
 * Reached because composer.json's extra.ai-mate names it under `includes`.
 */

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Xivi\Mate\Bridge\ApplicationBridge;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(ApplicationBridge::class)
            ->args(['%mate.root_dir%']);
};

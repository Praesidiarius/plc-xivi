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

namespace Xivi\Core\Module;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by each module bundle to declare what it needs installed.
 *
 * An interface rather than a naming convention or a config key, because §2 is
 * explicit: if a module must implement something, it is checked at compile time,
 * not documented and hoped for.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AutoconfigureTag(self::TAG)]
interface ModuleProvider
{
    public const string TAG = 'xivi.module';

    public function blueprint(): ModuleBlueprint;
}

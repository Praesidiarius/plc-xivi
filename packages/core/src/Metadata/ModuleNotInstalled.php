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

namespace Xivi\Core\Metadata;

/**
 * The module has no definitions in this tenant's database. Either it was never
 * installed for this customer, or the code is asking for a module the customer
 * does not have — which is a runtime question, not a packaging one
 * (docs/architecture.md §3).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleNotInstalled extends \RuntimeException
{
    public static function named(string $moduleKey): self
    {
        return new self(sprintf('Module "%s" is not installed for this tenant.', $moduleKey));
    }
}

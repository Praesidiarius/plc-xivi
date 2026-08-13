<?php

declare(strict_types=1);

namespace Xivi\Core\Metadata;

/**
 * The module has no definitions in this tenant's database. Either it was never
 * installed for this customer, or the code is asking for a module the customer
 * does not have — which is a runtime question, not a packaging one
 * (docs/architecture.md §3).
 */
final class ModuleNotInstalled extends \RuntimeException
{
    public static function named(string $moduleKey): self
    {
        return new self(sprintf('Module "%s" is not installed for this tenant.', $moduleKey));
    }
}

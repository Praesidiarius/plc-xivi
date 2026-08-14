<?php

declare(strict_types=1);

namespace Xivi\Core\Module;

/**
 * A named field set a module can be installed with (§6.1).
 *
 * Shipped with the module, in code, versioned alongside it: there are a handful
 * per module, they are identical for every customer who picks one, and changing
 * one means the module changed. The moment a preset is named after a customer it
 * has stopped being a preset and wants to be metadata rows instead.
 *
 * A seed, not a type. Once installed the customer's definitions are the truth and
 * this has no further say — which is why nothing records which preset was used.
 * Storing it would only invite something to re-apply it later, and §6.1 is
 * explicit that blueprint changes are not retro-fitted.
 *
 * **Fields only, never collections.** Not an arbitrary limit: a field left out
 * can be added back by the customer in the metadata editor (§5.4), so choosing
 * the smaller preset is reversible. Nothing can add a *collection* back — that
 * needs a table, which only the installer creates — so a preset that omitted one
 * would be a decision the customer could never undo. Until §7.2's additive
 * upgrade exists, every collection a module declares is installed every time.
 */
final readonly class ModulePreset
{
    /** @param list<string> $fields keys of the blueprint's fields to install */
    public function __construct(
        public string $key,
        public string $label,
        /** Shown when choosing, so the choice is not made from the name alone. */
        public string $description,
        public array $fields,
    ) {
    }
}

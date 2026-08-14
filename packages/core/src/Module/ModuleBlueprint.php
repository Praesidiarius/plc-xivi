<?php

declare(strict_types=1);

namespace Xivi\Core\Module;

/**
 * What a module declares about itself in code, before any customer has it.
 *
 * A blueprint is not the definition — it is the seed the installer writes into a
 * tenant's database once. From then on the customer's copy is what counts, and
 * it may have grown fields the blueprint never mentioned (§6). That separation
 * is what lets two customers run the same module with different shapes.
 */
final readonly class ModuleBlueprint
{
    /**
     * @param list<FieldBlueprint>      $fields
     * @param list<CollectionBlueprint> $collections child rows this module owns,
     *                                               such as a contact's addresses
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $table,
        public array $fields,
        public array $collections = [],
    ) {
    }
}

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
     * @param list<CollectionBlueprint> $collections    child rows this module owns,
     *                                                  such as a contact's addresses
     * @param list<ModulePreset>        $presets        named subsets of `fields` a
     *                                                  customer can be installed with (§6.1)
     * @param string|null               $defaultPreset  which one applies when nobody
     *                                                  chooses; null installs every field
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $table,
        public array $fields,
        public array $collections = [],
        public array $presets = [],
        public ?string $defaultPreset = null,
        /** Bootstrap Icons name, without the `bi-` prefix. */
        public ?string $icon = null,
    ) {
    }

    public function preset(string $key): ?ModulePreset
    {
        foreach ($this->presets as $preset) {
            if ($preset->key === $key) {
                return $preset;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function presetKeys(): array
    {
        return array_map(static fn (ModulePreset $p): string => $p->key, $this->presets);
    }
}

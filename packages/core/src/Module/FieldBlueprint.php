<?php

declare(strict_types=1);

namespace Xivi\Core\Module;

final readonly class FieldBlueprint
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public bool $required = false,
        public bool $unique = false,
        public bool $filterable = false,
        /** Shown as a column on the list. A module's own fields are, by default. */
        public bool $listed = true,
        public int $position = 0,
        public array $options = [],
    ) {
    }
}

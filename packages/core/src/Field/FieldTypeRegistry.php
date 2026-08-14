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

namespace Xivi\Core\Field;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The closed set of field types, keyed by their identifier.
 *
 * Asking for a type that no longer exists is fatal rather than silently ignored:
 * a definition row naming a removed type means stored data nobody can interpret,
 * and pretending otherwise is how that data quietly rots (§7.2).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FieldTypeRegistry
{
    /** @var array<string, FieldType> */
    private array $types = [];

    /** @param iterable<FieldType> $types */
    public function __construct(
        #[AutowireIterator(FieldType::TAG)]
        iterable $types,
    ) {
        foreach ($types as $type) {
            $this->types[$type->key()] = $type;
        }
    }

    /** @throws UnknownFieldType */
    public function get(string $key): FieldType
    {
        return $this->types[$key] ?? throw UnknownFieldType::named($key, array_keys($this->types));
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /** @return array<string, FieldType> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->types);
    }
}

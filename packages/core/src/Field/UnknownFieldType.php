<?php

declare(strict_types=1);

namespace Xivi\Core\Field;

final class UnknownFieldType extends \RuntimeException
{
    /** @param list<string> $known */
    public static function named(string $key, array $known): self
    {
        return new self(sprintf(
            'No field type "%s" is registered. Known types: %s. A definition naming a type that no '
            . 'longer exists describes stored data nobody can read.',
            $key,
            $known === [] ? 'none' : implode(', ', $known),
        ));
    }
}

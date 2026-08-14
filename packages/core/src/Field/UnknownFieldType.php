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

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
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

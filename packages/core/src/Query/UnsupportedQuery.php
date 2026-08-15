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

namespace Xivi\Core\Query;

/**
 * A query the engine will not answer, because it has no honest answer (§7.3).
 *
 * Thrown rather than quietly dropped. A filter that silently does nothing shows
 * the customer a list that looks like a result and is not one, which is worse
 * than an error — they would act on it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class UnsupportedQuery extends \InvalidArgumentException
{
    public static function unknownField(string $path, string $shape): self
    {
        return new self(sprintf('No field "%s" on "%s".', $path, $shape));
    }

    public static function unknownCollection(string $collection, string $module): self
    {
        return new self(sprintf('Module "%s" has no collection "%s".', $module, $collection));
    }

    /** @param list<Operator> $supported */
    public static function operator(Operator $operator, string $path, string $type, array $supported): self
    {
        return new self(sprintf(
            'Cannot ask whether "%s" (%s) %s. That type accepts: %s.',
            $path,
            $type,
            // The stored value rather than the label, which is now a translation
            // key (XIV-8) — and consistent with how the supported ones are listed.
            $operator->value,
            implode(', ', array_map(static fn (Operator $o): string => $o->value, $supported)),
        ));
    }

    public static function sortingByCollection(string $path): self
    {
        return new self(sprintf(
            'Cannot sort by "%s": it belongs to a collection, and a record with two of them has two '
            . 'values. Which one would be the record\'s?',
            $path,
        ));
    }
}

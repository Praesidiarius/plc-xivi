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

use Symfony\Component\Translation\TranslatableMessage;

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
    /**
     * What to show whoever asked, in their language (XIV-8).
     *
     * This one really does reach a customer: the query is in the URL, so a
     * hand-edited one arrives as a flash on the list page.
     */
    private TranslatableMessage $translatable;

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    /** @param array<string, mixed> $parameters */
    private static function of(string $message, string $key, array $parameters): self
    {
        $refusal = new self($message);
        $refusal->translatable = new TranslatableMessage($key, $parameters, 'xivi');

        return $refusal;
    }

    public static function unknownField(string $path, string $shape): self
    {
        return self::of(
            sprintf('No field "%s" on "%s".', $path, $shape),
            'query.unknown_field',
            ['%path%' => $path, '%shape%' => $shape],
        );
    }

    public static function unknownCollection(string $collection, string $module): self
    {
        return self::of(
            sprintf('Module "%s" has no collection "%s".', $module, $collection),
            'query.unknown_collection',
            ['%collection%' => $collection, '%module%' => $module],
        );
    }

    /** @param list<Operator> $supported */
    public static function operator(Operator $operator, string $path, string $type, array $supported): self
    {
        $accepts = implode(', ', array_map(static fn (Operator $o): string => $o->value, $supported));

        return self::of(
            sprintf('Cannot ask whether "%s" (%s) %s. That type accepts: %s.', $path, $type, $operator->value, $accepts),
            'query.unsupported_operator',
            [
                '%path%' => $path,
                '%type%' => $type,
                // The operator's own label, itself translated: Symfony resolves a
                // parameter that is translatable before it fills the sentence in.
                '%operator%' => new TranslatableMessage($operator->labelKey(), [], 'xivi'),
                '%supported%' => $accepts,
            ],
        );
    }

    public static function sortingByCollection(string $path): self
    {
        return self::of(
            sprintf(
                'Cannot sort by "%s": it belongs to a collection, and a record with two of them has two '
                . 'values. Which one would be the record\'s?',
                $path,
            ),
            'query.sort_by_collection',
            ['%path%' => $path],
        );
    }

    /**
     * The same refusal for a field that holds several values (XIV-113).
     *
     * Its own sentence rather than the one above, because the reason is the same
     * and the *fix* is not: a collection is sorted by naming one of its rows'
     * fields somewhere else, and a field holding four links has nowhere else to
     * go. Saying "it belongs to a collection" about a field that does not would
     * send somebody looking for a collection there is none of.
     */
    public static function sortingBySeveralValues(string $path, string $type): self
    {
        return self::of(
            sprintf(
                'Cannot sort by "%s" (%s): it holds several values, and a record with four of them has '
                . 'four. Which one would the list be in the order of?',
                $path,
                $type,
            ),
            'query.sort_by_several_values',
            ['%path%' => $path, '%type%' => $type],
        );
    }
}

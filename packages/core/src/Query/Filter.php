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
 * One condition: a field, a comparison, and a value (§7.3).
 *
 * The field may live on the module or on one of its collections, which is the
 * whole difficulty. `collection` is what tells the compiler which of the two it
 * is, and therefore whether this becomes an ordinary predicate or a semi-join —
 * a contact with two addresses in Zürich is one contact, not two, so a child
 * condition can never be a plain JOIN (§5.1).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Filter
{
    public function __construct(
        public string $field,
        public Operator $operator,
        public mixed $value = null,
        /** Null for a field on the module itself. */
        public ?string $collection = null,
    ) {
    }

    /** As it appears in a URL and in the form: "city" or "addresses.city". */
    public function path(): string
    {
        return $this->collection === null ? $this->field : $this->collection . '.' . $this->field;
    }

    /** Splits "addresses.city" into its parts; anything else is a module field. */
    public static function fromPath(string $path, Operator $operator, mixed $value = null): self
    {
        $parts = explode('.', $path, 2);

        return \count($parts) === 2
            ? new self($parts[1], $operator, $value, $parts[0])
            : new self($path, $operator, $value);
    }
}

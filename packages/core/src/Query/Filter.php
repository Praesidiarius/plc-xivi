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
 * The field may live on the module, on one of its collections, or on a record
 * this one links to — which is the whole difficulty. `through` names the step
 * that gets there, and what it names decides what the condition compiles to:
 *
 * - nothing: an ordinary predicate on the module's own row;
 * - a collection: a semi-join, because a contact with two addresses in Zürich is
 *   one contact and not two (§5.1);
 * - a reference field: a join into the linked module's table (§7.6, XIV-13).
 *
 * The compiler decides which by asking the shape, rather than the path carrying
 * a marker — `addresses.city` and `company.name` are the same syntax, and the
 * definitions already know which of the two `addresses` and `company` are.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Filter
{
    public function __construct(
        public string $field,
        public Operator $operator,
        public mixed $value = null,
        /**
         * The collection or reference field this condition reaches through.
         * Null for a field on the module itself.
         */
        public ?string $through = null,
    ) {
    }

    /** As it appears in a URL and in the form: "city", "addresses.city", "company.name". */
    public function path(): string
    {
        return $this->through === null ? $this->field : $this->through . '.' . $this->field;
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

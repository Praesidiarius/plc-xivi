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

namespace Xivi\Core\Module;

/**
 * A child collection a module declares: many rows belonging to one record.
 *
 * Same seed-not-definition relationship as ModuleBlueprint — the installer
 * writes it into a customer's database once, and from then on their copy is what
 * counts.
 *
 * There is no `unique` here worth honouring and the installer refuses it: unique
 * across the whole table and unique within one parent are different rules, and
 * which one a customer means is not something to guess at. It waits for the same
 * decision §7.5 is waiting for.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CollectionBlueprint
{
    /** @param list<FieldBlueprint> $fields */
    public function __construct(
        public string $key,
        public string $label,
        /** Its own table. Conventionally the parent's name and the collection's. */
        public string $table,
        public array $fields,
        /** Where it sits among its siblings in the parent's form. */
        public int $position = 0,
        /**
         * The key of the choice field deciding what kind a row is (§5.5, XIV-20).
         * Null for a collection whose rows are all the same thing.
         */
        public ?string $variantField = null,
    ) {
    }
}

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

namespace Xivi\Core\Record;

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\PrimesFromRecords;

/**
 * "Here is every record about to be rendered" — said once, by whoever has them
 * (XIV-54).
 *
 * The one place a set of records is announced to the field types that can do
 * something with it. Today that is references, which turn 500 lines naming 500
 * articles into one `WHERE id IN (…)` per target module instead of one lookup
 * per line; tomorrow it is whatever else needs a second table to render, and
 * nothing above this line has to learn about it.
 *
 * **Calling it is optional everywhere.** Nothing breaks without it, and that is
 * the property the whole design is built around ({@see PrimesFromRecords}): the
 * memo behind a reference falls back to a single lookup, so a caller that
 * forgets is slower and never wrong. It is called from the places that hold a
 * whole set of records and are about to render all of it — the list, the record
 * page, and the rows a document template repeats.
 *
 * **What it does not do is fetch anything itself.** It has no connection and no
 * idea what a reference is; it groups a shape's fields by type and hands each
 * type the ones that are its own. Which is the same division as everywhere else
 * here: the shape says what the fields are, the type says what they mean.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordPrimer
{
    public function __construct(private FieldTypeRegistry $fieldTypes)
    {
    }

    /**
     * Prime for a set of records of one shape.
     *
     * Cheap and safe to call with nothing — an empty collection, a record page
     * whose module has no references — because a type that finds no values of
     * its own reads nothing. Cheap enough that call sites do not have to guess
     * whether it is worth it, which is what keeps it from growing conditions.
     *
     * @param list<Record> $records
     */
    public function prime(ShapeDefinition $shape, array $records): void
    {
        if ($records === []) {
            return;
        }

        /** @var array<string, list<FieldDefinition>> $byType */
        $byType = [];

        foreach ($shape->getFields() as $field) {
            $byType[$field->getType()][] = $field;
        }

        foreach ($byType as $key => $fields) {
            // Not `has()` first: these records were hydrated through the same
            // registry a moment ago, so a type that could not be resolved here
            // could not have produced them either — and a quietly skipped field
            // would be a page that renders one fewer thing than it should (§7.2).
            $type = $this->fieldTypes->get($key);

            if ($type instanceof PrimesFromRecords) {
                $type->primeFrom($fields, $records);
            }
        }
    }
}

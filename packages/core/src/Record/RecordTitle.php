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

/**
 * What a record is called (§5.4), in one place.
 *
 * A shape names the fields its records are known by, and everything that shows a
 * record to somebody rather than showing its fields — the heading on its page,
 * the label a reference renders, the options a picker offers, and now the
 * results an autocomplete endpoint returns (XIV-36) — has to build the same
 * string out of them. There were two copies of this before this file existed and
 * the third was about to be written, which is one more than the number at which
 * copies start disagreeing about the details.
 *
 * The details worth naming, because they are exactly what a copy gets subtly
 * wrong:
 *
 * - **A reference is never part of a name.** It would recurse — a name built by
 *   naming another record, which is named by naming another — and it is not what
 *   anybody calls a record by anyway. Marked as a title field it contributes
 *   nothing rather than an id.
 * - **Only scalars.** Asking each title field's own type to render itself would
 *   mean holding the field-type registry, which would make this a service and
 *   put a container dependency behind every heading. A date used as a title
 *   therefore reads as the ISO string it is stored as, which is legible; a
 *   collection or an array reads as nothing, which beats "Array".
 * - **Something is always returned.** A company with no name at all is still a
 *   row somebody has to be able to point at, so the fallback names its shape and
 *   its id. A blank label in a dropdown is an option nobody can click.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordTitle
{
    public static function of(ShapeDefinition $shape, Record $record): string
    {
        return self::fromValues($shape, $record->data, (int) $record->id);
    }

    /**
     * The same answer from a payload rather than from a record.
     *
     * Kept as a second entry point because the reference field type names a
     * record it has looked up and the picker names one it has just read, while a
     * caller holding only what a form submitted has no `Record` at all. All
     * three want one string built one way.
     *
     * @param array<string, mixed> $data
     */
    public static function fromValues(ShapeDefinition $shape, array $data, int $id): string
    {
        $parts = [];

        foreach ($shape->getTitleFields() as $field) {
            $shown = trim(self::valueOf($field, $data[$field->getKey()] ?? null));

            if ($shown !== '') {
                $parts[] = $shown;
            }
        }

        return $parts === [] ? sprintf('%s #%d', $shape->getLabel(), $id) : implode(' ', $parts);
    }

    private static function valueOf(FieldDefinition $field, mixed $value): string
    {
        if ($field->getType() === 'reference' || !\is_scalar($value)) {
            return '';
        }

        return (string) $value;
    }
}

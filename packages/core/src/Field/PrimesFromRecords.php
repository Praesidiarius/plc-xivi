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

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Record\Record;

/**
 * A field type that can read what a whole set of records will ask for, before
 * they are rendered one at a time (XIV-54).
 *
 * **Why the caller cannot do this itself.** Rendering is per value: a template
 * walks the rows of a collection and asks each one's fields to display
 * themselves, so there is no moment during rendering at which every id is known.
 * The *data* is another matter — a list has its page back from `findBy()` and a
 * record page has every collection row back from `findChildren()` before a line
 * of Twig runs — so the set is in hand exactly once, and this is the seam that
 * hands it to the types that can use it.
 *
 * **A type gets its own fields together**, rather than one call per field,
 * because how to batch is the type's business and not the primer's: a reference
 * knows that two fields pointing at the same module are one query and that two
 * fields pointing at different ones are two, which is knowledge {@see
 * \Xivi\Core\Record\RecordPrimer} would have to grow a switch on field type to
 * have — and a caller switching on field type is the thing the field type
 * abstraction exists to prevent (see {@see LinksToRecord} for the same argument
 * about links).
 *
 * **Priming is an optimisation and never a requirement.** Whatever a type does
 * here, its display path has to give the same answer without it — slower, but
 * the same. A seam that breaks when nobody calls it is worse than the queries it
 * saves, because forgetting to call it is silent and every new call site is a
 * chance to forget.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface PrimesFromRecords
{
    /**
     * Warm whatever these records will be asked about.
     *
     * @param list<FieldDefinition> $fields  this type's fields on the shape the records have
     * @param list<Record>          $records every record about to be rendered
     */
    public function primeFrom(array $fields, array $records): void;
}

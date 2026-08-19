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

namespace Xivi\Core\ValueList;

use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;

/**
 * One field, somewhere in this customer's installation, that takes its values
 * from a shared list (XIV-127).
 *
 * **The shape a refusal and a confirmation both need.** Everything consequential
 * about a shared list is consequential *elsewhere*: removing an entry breaks
 * records in modules the person removing it is not looking at, and merging two
 * rewrites a column in each of them. So the answer to "where does this list
 * reach" has to be a thing rather than a loop, and it has to name itself in a
 * way a customer recognises — which is why {@see self::label()} is built from
 * the customer's own labels for the module, the collection and the field, rather
 * than from the keys the definition holds (§6.1: the installed definition is the
 * truth, and a customer who renamed Contacts to Kunden should read "Kunden").
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ValueListUse
{
    public function __construct(
        public ModuleDefinition $module,
        /** The module itself, or one of its collections — a collection's fields point at lists too (§5.1). */
        public ShapeDefinition $shape,
        public FieldDefinition $field,
    ) {
    }

    /**
     * "Kunden → Region", or "Kunden / Adressen → Region" for a collection.
     *
     * One line, because it is read inside a sentence: a refusal naming four
     * fields is four of these separated by commas, and anything longer would be
     * a paragraph where a phrase was wanted.
     */
    public function label(): string
    {
        $where = $this->shape instanceof CollectionDefinition
            ? sprintf('%s / %s', $this->module->getLabel(), $this->shape->getLabel())
            : $this->module->getLabel();

        return sprintf('%s → %s', $where, $this->field->getLabel());
    }
}

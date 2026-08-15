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

namespace App\View;

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Record;

/**
 * Records of one module pointing at the record being looked at (XIV-13).
 *
 * The reverse of a reference, read rather than stored: a person carries its
 * company, so a company's list of people is a query over that field, and storing
 * both sides would be two records of one fact (§7.6).
 *
 * **Which module, as well as which field.** Within one module the field's label
 * was enough to say what a group of linked records was. Across modules it is
 * not: an order and an invoice may both call their link "Contact", and a list
 * keyed by label alone would have quietly shown one and dropped the other.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class LinkedRecords
{
    /** @param list<Record> $records */
    public function __construct(
        public ModuleDefinition $module,
        public FieldDefinition $field,
        public array $records,
    ) {
    }

    /**
     * What to head the group with: the module, and the link when a module points
     * here more than once.
     *
     * "Orders" reads better than "Orders: Contact" and says the same thing — the
     * field only earns a mention when there are two of them, which is the case
     * this exists to keep readable rather than the common one.
     */
    public function heading(bool $withField): string
    {
        return $withField
            ? sprintf('%s: %s', $this->module->getLabel(), $this->field->getLabel())
            : $this->module->getLabel();
    }
}

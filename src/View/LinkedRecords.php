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
use Xivi\Core\Query\Operator;
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
 * **What is shown and how many there are are two numbers, not one** (XIV-52).
 * A card shows the first few; the count is the answer to "how many orders does
 * this customer have", which somebody reads off the page and believes. Counting
 * the array would answer the first question in the voice of the second, and be
 * wrong by everything the cap left out.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class LinkedRecords
{
    /**
     * @param list<Record> $records  the first few, capped by the page
     * @param int          $total    how many there are, counted under the same
     *                               access predicate the records were read with —
     *                               counting what somebody may not see would leak
     *                               it one integer at a time (§8.4)
     * @param int          $pointsAt the record they all point at
     */
    public function __construct(
        public ModuleDefinition $module,
        public FieldDefinition $field,
        public array $records,
        public int $total,
        public int $pointsAt,
    ) {
    }

    /** Whether the card is showing less than it counted. */
    public function isTruncated(): bool
    {
        return \count($this->records) < $this->total;
    }

    public function shown(): int
    {
        return \count($this->records);
    }

    /**
     * Where the rest of them are: the other module's list, filtered to this
     * record (XIV-13).
     *
     * The same shape the filter bar submits, so this is the list's own feature
     * rather than a second way to ask the question — RecordQueryFactory reads
     * these parameters back into the filter the card ran.
     *
     * @return array<string, mixed> route parameters for `module_index`
     */
    public function listing(): array
    {
        return [
            'module' => $this->module->getKey(),
            'filter' => [[
                'path' => $this->field->getKey(),
                'op' => Operator::Equals->value,
                'value' => (string) $this->pointsAt,
            ]],
        ];
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

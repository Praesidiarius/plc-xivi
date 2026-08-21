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
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Query\Operator;

/**
 * One card of a grouped index: the records sharing one value of the field the
 * module is grouped by (XIV-168).
 *
 * The same pair of numbers {@see \App\View\LinkedRecords} carries, for the same
 * reason. **What the card shows and how many there are are two facts, not one.**
 * A card is a glance, so it stops at a ceiling; the count is the answer to "how
 * many entries are filed under this", which somebody reads off the page and
 * believes. Counting the array would answer the second question with the first
 * question's number, and be wrong by exactly what the ceiling left out.
 *
 * Both are read under the same access predicate (§8.4), so a count on a card can
 * never be larger than what this reader would find by opening the list. A total
 * counted without the predicate would give away how many records exist one
 * integer at a time, which is the inference channel §8.4 is careful about.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordGroup
{
    /**
     * The URL parameter that asks a grouped index for its rows back (XIV-168).
     *
     * A card has a ceiling, and the way past a ceiling is the list the ceiling
     * was hiding. But filtering the grouped page to one value gives back the
     * same page with one card on it and the same ceiling, so "and 37 more" would
     * link to itself. So the link filters **and** says which view it wants, and
     * that view is the paged, sorted table this page has always been. Nothing
     * else in the application writes this parameter: the way to it is the link
     * on a card that has more to show, which is the only place somebody wants
     * it.
     *
     * Not a route of its own, and not a toggle above the cards. §5.3 has one
     * index route, and a filter bar that is already how a list is narrowed; this
     * is one more parameter on the URL that filter bar builds.
     */
    public const string VIEW = 'view';

    /** What {@see self::VIEW} has to say for the rows to come back. */
    public const string AS_LIST = 'list';

    /**
     * @param string       $value   the stored value these records share, or the
     *                              empty string for the records holding no value
     *                              at all
     * @param ?string      $label   the customer's word for that value, or null
     *                              when there is none to have. The unfiled card
     *                              has no option behind it, and neither has a
     *                              value left over from an option that is gone.
     *                              The caller words both, because wording is the
     *                              application's job and this package has no
     *                              translator
     * @param list<Record> $records the first few, in the page's order, capped by
     *                              the page
     * @param int          $total   how many there are under this value, counted
     *                              under the same access predicate and the same
     *                              filters as the records
     */
    public function __construct(
        public ModuleDefinition $module,
        public FieldDefinition $field,
        public string $value,
        public ?string $label,
        public array $records,
        public int $total,
    ) {
    }

    /**
     * Whether this is the card for records that answered the question with
     * nothing.
     *
     * It is drawn whenever anything is in it, and that is the decision this
     * class is quietest about and the one worth stating. The field a module is
     * grouped by need not be required, so records holding no value are ordinary
     * rather than broken; a grouping that drew only the field's own options
     * would make them invisible on their own index page, which is worse than the
     * table it replaced.
     */
    public function isUnfiled(): bool
    {
        return $this->value === '';
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
     * Where the rest of them are: this same index, narrowed to this value and
     * asked for as a list.
     *
     * The filter half is the shape the filter bar submits, so this is the list's
     * own feature rather than a second way to ask the question, and
     * {@see \Xivi\Core\Query\RecordQueryFactory} reads it back into the filter
     * the card ran. `IsEmpty` for the unfiled card, which compiles to the same
     * "null or blank" test the grouping partitioned on, so the page it lands on
     * holds exactly the records the card was counting.
     *
     * @return array<string, mixed> route parameters for `module_index`
     */
    public function listing(): array
    {
        return [
            'module' => $this->module->getKey(),
            'filter' => [[
                'path' => $this->field->getKey(),
                'op' => ($this->isUnfiled() ? Operator::IsEmpty : Operator::Equals)->value,
                'value' => $this->value,
            ]],
            self::VIEW => self::AS_LIST,
        ];
    }
}

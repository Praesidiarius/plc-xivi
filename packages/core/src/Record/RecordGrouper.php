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
use Xivi\Core\Field\Enumerates;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Module\GroupedList;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Direction;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Query\Sort;

/**
 * Turning a module's index into one card per value of a field it declared
 * (XIV-168).
 *
 * The engine half of {@see GroupedList}. The module says which field; this reads
 * the *customer's* definition of that field, asks it what it is a choice
 * between, runs one query, and hands back the cards in the order they should be
 * drawn. Nothing above it knows which module it is looking at, which is the
 * property the whole design exists for.
 *
 * ## The cases it decides once for every grouped module
 *
 * **A value nobody has used draws no card.** Both answers are defensible and the
 * ticket said so; this one is chosen for three reasons that point the same way.
 * A fresh tenant would otherwise open its knowledge base on six empty boxes,
 * which says "there is nothing here" six times where §5.3's empty state says it
 * once and points at the way in. Topics are the customer's to add (§5.20), so
 * the count of empty boxes is theirs to grow without meaning to. And a filter
 * has to reshape the cards, so an empty card under a search for a word would be
 * a heading claiming a match it has not got: drawing them unfiltered and hiding
 * them under a filter would be two behaviours where one will do.
 *
 * What is lost is the invitation, the empty "Supplier" card that tells a reader
 * the topic exists and asks for the first entry under it. The field editor is
 * where the list of topics is actually visible, and the record form's dropdown
 * offers every one of them when somebody writes, so the topic is discoverable in
 * the two places somebody is deciding what to file something under. It is only
 * the index that stays quiet about a topic nothing is filed under.
 *
 * **Records holding no value at all get a card of their own, and it goes last.**
 * That they get one is not negotiable: the field need not be required, so those
 * records are ordinary and a page that omitted them would hide entries on their
 * own index. Last rather than first, because the cards above it are in the order
 * the customer arranged their options in (§5.20) and this one is not one of
 * their options; putting the engine's card into the middle of somebody's own
 * arrangement is the thing that would need explaining. It is drawn whenever it
 * holds anything, so nothing is ever invisible, and the page is short enough
 * that "last" is still on it.
 *
 * **A value with no option left draws a card labelled with the raw value.**
 * §5.4 refuses to remove an option records hold, so this is rare rather than
 * impossible: a shared list can go away underneath a field pointing at it, and
 * an import can write a value nothing offers. The records exist either way, and
 * the argument for the unfiled card is the same argument here, so they are drawn
 * after the declared options and before the unfiled card rather than dropped.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordGrouper
{
    public function __construct(
        private ModuleRegistry $modules,
        private FieldTypeRegistry $fieldTypes,
        private RecordRepository $records,
    ) {
    }

    /**
     * The field this module's index is grouped by, or null for the ordinary
     * list.
     *
     * Null covers four cases that are one case to a caller, the same way
     * {@see \Xivi\Core\Mail\RecipientResolver::declaredFor()} collapses its
     * three: a build without the module, a module that declared no grouping, a
     * customer whose shape no longer has the field the declaration names, and a
     * field somebody converted to a type that no longer enumerates anything.
     * None of them is a fault and all four mean the same thing, which is that
     * this index is a list of rows. A page that threw instead would make a
     * supported metadata change (§5.4 allows the conversion) into an outage.
     */
    public function fieldFor(ModuleDefinition $module): ?FieldDefinition
    {
        $key = $module->getKey();

        if (!$this->modules->has($key)) {
            return null;
        }

        $declared = $this->modules->get($key)->groupedList;

        if ($declared === null) {
            return null;
        }

        $field = $module->getField($declared->field);

        if ($field === null) {
            return null;
        }

        return $this->fieldTypes->get($field->getType()) instanceof Enumerates ? $field : null;
    }

    /**
     * The cards, in the order they are drawn.
     *
     * @param int $perCard the ceiling on one card, passed in rather than owned
     *                     here because how much fits on a card is a property of
     *                     the page
     *
     * @return list<RecordGroup>
     */
    public function groupsFor(
        ModuleDefinition $module,
        FieldDefinition $field,
        RecordQuery $query,
        RecordAccess $access,
        int $perCard,
    ): array {
        $type = $this->fieldTypes->get($field->getType());
        \assert($type instanceof Enumerates);

        $found = $this->records->findGrouped($module, $field, self::ordered($module, $query), $access, $perCard);
        $groups = [];

        // The declared options first, in the customer's own arrangement, which
        // is free: `optionsOf()` returns them in that order and this walks it.
        foreach ($type->optionsOf($field) as $value => $label) {
            $value = (string) $value;

            if (isset($found[$value])) {
                $groups[] = self::group($module, $field, $value, $label, $found[$value]);
                unset($found[$value]);
            }
        }

        // Then whatever is left holding a value the field no longer offers, and
        // last the records holding nothing. Sorted so that two requests with the
        // same data draw the same page: what is left here came out of a hash and
        // has no order anybody chose.
        $leftOver = array_diff_key($found, ['' => null]);
        ksort($leftOver);

        foreach ($leftOver as $value => $rows) {
            $groups[] = self::group($module, $field, (string) $value, null, $rows);
        }

        if (isset($found[''])) {
            $groups[] = self::group($module, $field, '', null, $found['']);
        }

        return $groups;
    }

    /**
     * The query the cards are filled from: the caller's, with an ordering if it
     * carried none.
     *
     * **Sorting is what a grouped index loses** (§5.3), because the column
     * headers are where a list offers it and a card has no headers. What
     * replaces it is a default rather than nothing: a card of titles that is not
     * in title order is a card nobody can scan, and the module's own title
     * fields are what the card is showing. A sort that arrived in the URL is
     * left alone, so the ordering is still reachable by hand and by the export
     * beside it, which runs the same query.
     */
    private static function ordered(ModuleDefinition $module, RecordQuery $query): RecordQuery
    {
        if ($query->sorts !== []) {
            return $query;
        }

        $sorts = array_map(
            static fn (FieldDefinition $field): Sort => new Sort($field->getKey(), Direction::Ascending),
            $module->getTitleFields(),
        );

        return $sorts === [] ? $query : $query->withSorts(...$sorts);
    }

    /** @param array{records: list<Record>, total: int} $rows */
    private static function group(
        ModuleDefinition $module,
        FieldDefinition $field,
        string $value,
        ?string $label,
        array $rows,
    ): RecordGroup {
        return new RecordGroup($module, $field, $value, $label, $rows['records'], $rows['total']);
    }
}

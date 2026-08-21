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

namespace Xivi\Knowledge\Index;

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Direction;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Query\Sort;
use Xivi\Core\Record\IndexBody;
use Xivi\Core\Record\IndexBodyProvider;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordListUrl;
use Xivi\Core\Record\RecordPageUrl;
use Xivi\Core\Record\RecordRepository;
use Xivi\Knowledge\KnowledgeModule;

/**
 * The knowledge index, drawn as one card per topic rather than a page of rows
 * (XIV-168, brought here by XIV-177).
 *
 * A knowledge base read as twenty-five rows with a pager under them is a
 * knowledge base nobody browses. Rows answer *which entry is this*; what
 * somebody arriving actually wants is the shape of what has been written down,
 * which topics exist and what is filed under each, and that is a page of cards.
 *
 * ## Why this is the module's and not the engine's
 *
 * XIV-168 built it in core: a `GroupedList` declaration on `ModuleBlueprint`, a
 * `RecordGrouper`, a `RecordGroup`, `optionsOf()` promoted onto the public
 * {@see \Xivi\Core\Field\Enumerates} interface, and ninety-six lines of card
 * markup inside the template every module shares. It was general, it was
 * declarative, and **one module used it**. §1's rule is that the engine may not
 * grow features no module needs and that an abstraction is earned by a second
 * concrete use case; there was no second one, and there is still none.
 *
 * So what the engine kept is a seam that knows a template name and some data
 * ({@see IndexBodyProvider}), and everything below came here, where the one
 * module that has an opinion about it lives: what a card is, which cards exist,
 * what order they go in, what happens to entries with no topic, how many fit. The
 * one thing that did **not** come is the statement:
 * {@see RecordRepository::findGrouped()} compiles {@see RecordAccess} into its
 * window function, and a module writing that statement would be a module writing
 * its own permission filter.
 *
 * ## The cases decided once, and the reasoning behind each
 *
 * **A topic nobody has written under draws no card.** Both answers are
 * defensible; three arguments point the same way. A fresh tenant would otherwise
 * open its knowledge base on six empty boxes, which says "there is nothing here"
 * six times where §5.3's empty state says it once and points at the way in.
 * Topics are the customer's to add (§5.20), so the count of empty boxes is
 * theirs to grow without meaning to. And the filter bar has to reshape the
 * cards, so an empty card under a search for a word would be a heading claiming
 * a match it has not got: drawing them unfiltered and hiding them under a filter
 * would be two behaviours where one will do.
 *
 * What is lost is the invitation, the empty "Supplier" card that tells a reader
 * the topic exists and asks for the first entry under it. The field editor is
 * where the list of topics is actually visible, and the record form's dropdown
 * offers every one of them when somebody writes, so the topic is discoverable in
 * the two places somebody is deciding what to file something under. Only the
 * index stays quiet about a topic nothing is filed under.
 *
 * **Entries with no topic get a card of their own, and it goes last.** That they
 * get one is not negotiable: the field is not required (§5.22), so those entries
 * are ordinary and a page that omitted them would hide entries on their own
 * index. Last rather than first, because the cards above it are in the order the
 * customer arranged their options in (§5.20) and this one is not one of their
 * options; putting a card nobody arranged into the middle of somebody's own
 * arrangement is the thing that would need explaining.
 *
 * **A value with no option left draws a card labelled with the raw value.** §5.4
 * refuses to remove an option records hold, so this is rare rather than
 * impossible: a shared list can go away underneath a field pointing at it, and
 * an import can write a value nothing offers. The entries exist either way and
 * the argument for the unfiled card is the same argument here, so they are drawn
 * after the declared options and before the unfiled card rather than dropped.
 *
 * ## What it does when the customer's shape has moved underneath it
 *
 * Null, and the index draws its table. §6.1 says the customer's definitions are
 * the truth from the moment the module is installed, so this asks the definition
 * it was handed rather than the blueprint, and three supported changes each end
 * in the ordinary list instead of an error page: the topic field deleted, the
 * topic field converted to a type that is not a closed set of options, or this
 * provider asked about some other module entirely. That last one is the common
 * case, because every other module's index asks and is told null.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TopicCards implements IndexBodyProvider
{
    /**
     * How many entries fit on a card before it stops being a glance.
     *
     * **Ten, which is `ModuleController::LINKED_ON_RECORD`'s number and the same
     * judgement.** A card of the entries under a topic is the same object as a
     * card of the orders under a contact: a glance, with the list a click away
     * when the glance is not enough, and neither can make an argument against
     * the other's number.
     *
     * It is written out here rather than shared because it cannot be shared: a
     * module may see `Xivi\Core\` and nothing else (§3), and that constant is on
     * an application controller. XIV-168 reached it because the page passed the
     * cap in from up there, which was one of the several ways that design had
     * the engine knowing what a card is. Two tens that agree by argument rather
     * than by reference is the cost of the boundary, and it is a small one.
     */
    public const int PER_CARD = 10;

    public function __construct(
        private FieldTypeRegistry $fieldTypes,
        private RecordRepository $records,
        // Where an entry's own page is, and where the list of a topic's entries
        // is. Both are routes, both are the application's to name, and a
        // template in this package may build neither (§3, XIV-66, XIV-178).
        private RecordPageUrl $pages,
        private RecordListUrl $lists,
    ) {
    }

    public function bodyFor(ModuleDefinition $module, RecordQuery $query, RecordAccess $access): ?IndexBody
    {
        $field = $this->topicOf($module);

        if ($field === null) {
            return null;
        }

        $cards = $this->cards($module, $field, $query, $access);

        // Flat, for the page to prime in one call (XIV-54), and summed, because
        // the total under the index has to be the number this actually counted
        // rather than a second opinion from a second statement (§5.3).
        $records = [];
        $total = 0;

        foreach ($cards as $card) {
            $records = [...$records, ...$card->records];
            $total += $card->total;
        }

        return new IndexBody(
            template: '@XiviKnowledge/index/cards.html.twig',
            values: [
                'module' => $module,
                // The field, for the one word the unfiled card needs: the
                // customer's own label for the topic field, whatever they have
                // since renamed it to.
                'field' => $field,
                'cards' => $cards,
                // Built here rather than in the template, which is the whole
                // point of RecordPageUrl: this package's template must not know
                // a route name either.
                'urls' => $this->pageUrls($records),
            ],
            records: $records,
            total: $total,
        );
    }

    /**
     * The topic field as this customer has it, or null if their shape can no
     * longer be drawn as cards.
     *
     * **`ChoiceFieldType` by name, and that is not the switch this codebase
     * refuses.** The switch worth refusing is the engine testing a field's type
     * to decide what it can do with it, which is why
     * {@see \Xivi\Core\Field\Enumerates::findsHoldersBy()} and
     * {@see \Xivi\Core\Field\PointsAtAModule::findsTargetBy()} exist. This is a
     * module asking about **a field it declared itself**, whose type it chose,
     * to decide whether its own page still makes sense. That is a judgement about
     * one field rather than a rule about every field. XIV-168 asked the same
     * question through the interface, which is what made `optionsOf()` a promise
     * every enumerating type had to make for the sake of one module's index;
     * XIV-177 took that promise back off the interface, and this is the caller
     * it was taken back from.
     */
    private function topicOf(ModuleDefinition $module): ?FieldDefinition
    {
        if ($module->getKey() !== KnowledgeModule::KEY) {
            return null;
        }

        $field = $module->getField(KnowledgeModule::TOPIC);

        if ($field === null) {
            return null;
        }

        return $this->fieldTypes->get($field->getType()) instanceof ChoiceFieldType ? $field : null;
    }

    /**
     * The cards, in the order they are drawn.
     *
     * @return list<TopicCard>
     */
    private function cards(
        ModuleDefinition $module,
        FieldDefinition $field,
        RecordQuery $query,
        RecordAccess $access,
    ): array {
        $type = $this->fieldTypes->get($field->getType());
        \assert($type instanceof ChoiceFieldType);

        $found = $this->records->findGrouped($module, $field, self::ordered($module, $query), $access, self::PER_CARD);
        $cards = [];

        // The declared options first, in the customer's own arrangement, which
        // is free: `optionsOf()` returns them in that order and this walks it.
        foreach ($type->optionsOf($field) as $value => $label) {
            $value = (string) $value;

            if (isset($found[$value])) {
                $cards[] = $this->card($module, $field, $value, $label, $found[$value]);
                unset($found[$value]);
            }
        }

        // Then whatever is left holding a value the field no longer offers, and
        // last the entries filed under nothing. Sorted so that two requests with
        // the same data draw the same page: what is left here came out of a hash
        // and has no order anybody chose.
        $leftOver = array_diff_key($found, ['' => null]);
        ksort($leftOver);

        foreach ($leftOver as $value => $rows) {
            $cards[] = $this->card($module, $field, (string) $value, null, $rows);
        }

        if (isset($found[''])) {
            $cards[] = $this->card($module, $field, '', null, $found['']);
        }

        return $cards;
    }

    /**
     * The query the cards are filled from: the caller's, with an ordering if it
     * carried none.
     *
     * **Sorting is what a card index loses** (§5.3), because the column headers
     * are where a list offers it and a card has no headers. What replaces it is
     * a default rather than nothing: a card of titles that is not in title order
     * is a card nobody can scan, and the module's own title fields are what the
     * card is showing. A sort that arrived in the URL is left alone, so the
     * ordering is still reachable by hand and by the export beside it, which
     * runs the same query.
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

    /**
     * One card, with the address of the list behind it already built.
     *
     * **The filter half is the shape the filter bar submits**, so a card's "see
     * them all" is the list's own feature rather than a second way to ask the
     * question, and {@see \Xivi\Core\Query\RecordQueryFactory} reads it back into
     * the filter the card ran. `IsEmpty` for the unfiled card, which compiles to
     * the same "null or blank" test the grouping partitioned on, so the page it
     * lands on holds exactly the entries the card was counting.
     *
     * **And it asks for rows.** Narrowing this page to one topic gives back this
     * page with one card on it and the same ceiling, so the link would point at
     * itself; asking for rows lands on the paged, sorted table §5.3 has always
     * had, with its column headers and its pager.
     *
     * @param array{records: list<Record>, total: int} $rows
     */
    private function card(
        ModuleDefinition $module,
        FieldDefinition $field,
        string $value,
        ?string $label,
        array $rows,
    ): TopicCard {
        return new TopicCard(
            value: $value,
            label: $label,
            records: $rows['records'],
            total: $rows['total'],
            listing: $this->lists->forModule(
                $module->getKey(),
                [new Filter(
                    $field->getKey(),
                    $value === '' ? Operator::IsEmpty : Operator::Equals,
                    $value,
                )],
                asRows: true,
            ),
        );
    }

    /**
     * An address per entry, keyed by id, so the template looks one up instead of
     * building one.
     *
     * @param list<Record> $records
     *
     * @return array<int, string>
     */
    private function pageUrls(array $records): array
    {
        $urls = [];

        foreach ($records as $record) {
            $urls[(int) $record->id] = $this->pages->forRecord(KnowledgeModule::KEY, (int) $record->id);
        }

        return $urls;
    }
}

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

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Permission\RecordAccessProvider;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Query\Search;
use Xivi\Core\Query\Sort;

/**
 * The records a reference may point at — named, narrowed, scoped, sorted and
 * paged (XIV-36).
 *
 * **One place, because there are now three callers and they must not disagree.**
 * The select renders a page of these; the search endpoint returns pages of the
 * same thing as somebody types; and the choice list has to accept whichever id
 * comes back from either. If those three ever answer differently the failure is
 * not cosmetic — a candidate offered by one and refused by another is a record
 * somebody can see, click and then be told is not a valid choice.
 *
 * **Scoped exactly as the picker is** (§8.4, settled in XIV-13), and that is the
 * sharp end of this class. An unrestricted search endpoint is worse than the
 * unrestricted picker XIV-13 closed: a picker leaks the names it renders once,
 * where a search box lets somebody enumerate a module by typing letters at it.
 * So every read here goes through the same `RecordAccess` a list would, for
 * `View` on the target module, with no exception for administrators written into
 * the query — an administrator's bypass belongs in how their permissions resolve
 * (§8.4) and not in a second answer buried in a repository call.
 *
 * The variants narrow it the way `formOptions()` already narrows the select
 * (§5.5), so a person's employer offers companies rather than every contact —
 * and the same narrowing applies when a submitted id is checked, or the widget
 * would be a suggestion and the validation a different rule.
 *
 * **A set of them rather than one** (XIV-172), which is the whole of that
 * ticket's decision arriving here. It was a single nullable key until an order's
 * two voucher pickers needed *two* of the voucher module's four kinds each: the
 * document takes the kinds that apply to a document and a line takes the kinds
 * that apply to a line, so a field admitting exactly one variant could not say
 * what either of them means. Empty is what "every kind" is spelled as, which is
 * `FieldBlueprint::variants`' own rule (§5.5) rather than a second convention,
 * and it is what every reference that says nothing about variants passes.
 *
 * **And the module gets a say of its own** (XIV-175). Some of what cannot be
 * chosen is not a property of the *field* at all: an expired voucher cannot be
 * used by anybody, on any picker, and no blueprint could have said so, because
 * the rule is a date read against today rather than a key. {@see
 * NarrowsCandidates} is where a module states it, and this class is its only
 * caller, so a rule stated once reaches the select, the count that chooses the
 * widget, the endpoint and the check on a submitted id together.
 *
 * **What it narrows is what may be *newly chosen*.** {@see self::held()} is the
 * other side of that sentence and the reason it is worded so carefully: a
 * document keeps the voucher it was agreed with after the promotion ends, so
 * the value a record already holds stays choosable on its own form even when
 * the same id would not be offered to a new one.
 *
 * Sorted by the shape's first title field, which is what somebody is scanning,
 * and is the same order the select used before any of this existed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordCandidates
{
    /**
     * How many a search returns at once.
     *
     * A dropdown's worth. The widget pages through the rest as somebody scrolls
     * — see {@see \Xivi\Core\Form\RecordReferenceType} for why paging replaces
     * the ceiling the plain select had rather than merely raising it.
     */
    public const int PER_PAGE = 25;

    public function __construct(
        private MetadataRepository $metadata,
        private RecordRepository $records,
        private RecordAccessProvider $access,
        // Looking one record up by id is a question three other things already
        // ask, so it is asked through their memo rather than beside it (XIV-54).
        // See byId(), where it earns its place on a form of five hundred rows.
        private ReferenceTargets $targets,
        /**
         * The modules that have a rule of their own about what may be offered
         * (XIV-175). Nearly always empty of anything relevant: one voucher
         * module against every other module in the instance, which is why
         * {@see self::narrowingFor()} is a loop over a handful rather than a
         * lookup that has to be built.
         *
         * @var iterable<NarrowsCandidates>
         */
        #[AutowireIterator(NarrowsCandidates::TAG)]
        private iterable $narrowings = [],
    ) {
    }

    /**
     * One page of candidates, optionally narrowed by what somebody has typed.
     *
     * @param list<string> $variants which kinds may be offered; empty for all
     *
     * @return list<Candidate>
     */
    public function find(string $moduleKey, array $variants, string $search = '', int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $module = $this->moduleOf($moduleKey);

        if ($module === null) {
            return [];
        }

        $found = $this->records->findBy(
            $module,
            $this->queryFor($module, $variants, $search, $page, $perPage),
            $this->accessTo($moduleKey),
        );

        return self::named($module, $found);
    }

    /**
     * How many there are in total, under exactly the same conditions.
     *
     * **The same predicate as the page, or the number leaks.** A total counted
     * without the access restriction would say how many records exist, one
     * integer at a time, which is the thing scoping the picker was for.
     *
     * The variants are part of that sameness and not only for the leak: this
     * number is what decides whether the control is a select or a search box
     * ({@see \Xivi\Core\Field\Autocomplete::AUTO_ABOVE}), so counting the
     * whole module would turn a picker offering fifteen vouchers into a search
     * box because the customer happens to keep sixty of the other kind.
     *
     * @param list<string> $variants
     */
    public function count(string $moduleKey, array $variants, string $search = ''): int
    {
        $module = $this->moduleOf($moduleKey);

        if ($module === null) {
            return 0;
        }

        return $this->records->countBy(
            $module,
            $this->queryFor($module, $variants, $search, 1, self::PER_PAGE),
            $this->accessTo($moduleKey),
        );
    }

    /**
     * One candidate by id, or null when this reader may not have it.
     *
     * This is what makes a search box safe to submit from. The endpoint decides
     * what somebody may *find*; this decides what they may *pick*, and the two
     * have to agree or the widget offers records the form then rejects — or,
     * far worse the other way, accepts an id that was never offered because
     * somebody typed it into the request by hand.
     *
     * Null covers every way of not having it, and they are deliberately
     * indistinguishable from out here: no such module, no such record, deleted,
     * the wrong kind, and not yours. A caller that could tell them apart would
     * be a caller that can probe for which ids exist.
     *
     * **The kinds are checked here and not only in the list** (XIV-172). A
     * filtered dropdown is what somebody picks from; this is what decides
     * whether the id that arrives may be picked *at all*, including one nobody
     * picked because it was typed into the request. Narrowing only the list
     * would leave the rule true of the form and false of the wire.
     *
     * **The record comes out of {@see ReferenceTargets}, and that is not a
     * micro-optimisation** (XIV-54). An autocompleting picker on a collection
     * row asks this once per row for the record that row already points at, so
     * an order with five hundred lines asks five hundred times — measured at 494
     * queries before this went through the memo, which would have been a worse
     * number than the one XIV-87 had just fixed. Through the memo it is one
     * query per *distinct* target, and none at all where something has primed
     * them.
     *
     * The memo reads **unscoped**, deliberately (§8.4, XIV-42), and the scope is
     * applied here to the record it hands back — exactly the arrangement
     * `ReferenceFieldType::linkOf()` uses, and for the same reason: a name is
     * shown to whoever may read the record holding the link, and what a reader's
     * own permissions decide is whether they may *have* it. Applying the
     * predicate to a record already in memory costs no query and keeps the
     * refusal in one place.
     *
     * **The module's own rule is checked here too** (XIV-175), for the reason
     * the paragraph above gives about kinds: narrowing only the list would
     * leave "an expired voucher is not a choice" true of the form and false of
     * the wire.
     *
     * @param list<string> $variants which kinds may be picked; empty for all
     */
    public function byId(string $moduleKey, array $variants, int $id): ?Candidate
    {
        return $this->candidate($moduleKey, $variants, $id, narrowed: true);
    }

    /**
     * One candidate by id, admitting what a record already holds (XIV-175).
     *
     * The same answer as {@see self::byId()} in every way but two: neither the
     * module's own narrowing nor the field's kinds are applied. Everything that
     * makes an id *not this reader's to have* still is: no such module, no such
     * record, deleted, not yours. None of those has ever been true of a value
     * legitimately stored on a record somebody is looking at.
     *
     * **What this exists for is the voucher that expires after the order was
     * agreed.** A picker narrowed by the calendar stops offering it, which is
     * right for a document being written now and wrong for the one that already
     * names it: the form would fail to make it a choice, it would arrive as
     * nothing, and the save would give the use back and take the discount off a
     * document the shop has already agreed to, with nobody told. The engine's
     * own rule is the opposite (§5.9, XIV-110): a use is taken when the document
     * first names the voucher, and re-saving re-checks nothing.
     *
     * **The kinds joined it in [XIV-176], and the argument transfers word for
     * word**: the voucher whose family was renamed after the order was agreed is
     * the voucher that expired after the order was agreed. A narrowing now
     * reaches a tenant that installed the module before it existed
     * ({@see \Xivi\Core\Field\ModuleOwnedOptions}), so a document written under
     * the old shape can be holding a kind the picker no longer offers, and
     * dropping it on the next save is the same silent correction, on a document
     * somebody already agreed to.
     *
     * **What the write then does was expected to be a cost and is not one.**
     * `RedeemsVouchers` acts on the difference between what a document carried
     * before and what it carries now, because a use is taken once and re-saving
     * re-checks nothing (§5.9, XIV-110), the same rule the paragraph above
     * rests on. A document that already names the voucher names it before and
     * after, so nothing is taken and nothing is refused, and the discount stays
     * where the shop agreed it. What still refuses, and must, is a document
     * *taking* that voucher afresh: an import, a copy, or a value put back after
     * being cleared meet XIV-122's sentence naming the field, because a picker
     * is a convenience in front of a guarantee rather than a replacement for it.
     *
     * So this is not a hole in the narrowing, it is the narrowing's subject
     * stated exactly. Only a value the form was *given* reaches here, through
     * {@see \Xivi\Core\Form\RecordChoiceLoader::offer()}, which the form type
     * calls on `PRE_SET_DATA` with the record's stored links and nothing else. A
     * crafted id is not one of those, and re-submitting the id a record already
     * holds changes nothing about the record.
     *
     * **No kinds parameter, because it would be a parameter nothing reads.**
     * Both callers used to hand theirs over and both meant the same thing by
     * it; leaving it in the signature would leave the next reader believing it
     * decides something.
     */
    public function held(string $moduleKey, int $id): ?Candidate
    {
        return $this->candidate($moduleKey, [], $id, narrowed: false);
    }

    /**
     * Whether this module narrows its own candidates (XIV-175).
     *
     * Asked by {@see \Xivi\Core\Form\RecordReferenceType}, which needs it before
     * it has drawn anything: a picker whose module can hide a record that is
     * legitimately stored needs a choice list that can be told about that record
     * after the list has been built, and only one of the two widgets has one.
     */
    public function narrows(string $moduleKey): bool
    {
        return $this->narrowingFor($moduleKey) !== null;
    }

    /**
     * @param list<string> $variants
     * @param bool         $narrowed whether the module's own rule applies, which
     *                               is the difference between what may be chosen
     *                               and what may be kept
     */
    private function candidate(string $moduleKey, array $variants, int $id, bool $narrowed): ?Candidate
    {
        $module = $this->moduleOf($moduleKey);

        if ($module === null) {
            return null;
        }

        $record = $this->targets->of($moduleKey, $id);

        if ($record === null || $record->isDeleted()) {
            return null;
        }

        $access = $this->accessTo($moduleKey);

        if ($access->matchesNothing()) {
            return null;
        }

        if ($access->isRestricted() && $record->ownerId !== $access->ownerId()) {
            return null;
        }

        if (!self::isOneOf($module, $record, $variants)) {
            return null;
        }

        $narrowing = $narrowed ? $this->narrowingFor($moduleKey) : null;

        if ($narrowing !== null && !$narrowing->offers($module, $record)) {
            return null;
        }

        return new Candidate((int) $record->id, RecordTitle::of($module, $record));
    }

    /**
     * The query all three paths share: the kinds, the name search, the ordering
     * and the page.
     *
     * **The kinds travel as the query's own narrowing rather than as a filter**
     * (XIV-172). One of them used to be a `Filter` on the variant field, which
     * worked exactly as long as a field admitted one kind; two of them cannot be
     * two filters, because filters are ANDed and no record is of two kinds at
     * once. {@see RecordQuery::$variants} is where that argument is written
     * down, along with why it is not an operator over a list.
     *
     * **And the module's own rule travels beside them** (XIV-175), as the
     * conditions a candidate must not match. It is a third narrowing rather
     * than a fourth filter for a related reason: what a module wants to say is
     * a negation, and `RecordQuery::$excluding` is where the argument for that
     * shape is written down.
     *
     * @param list<string> $variants
     */
    private function queryFor(ModuleDefinition $module, array $variants, string $search, int $page, int $perPage): RecordQuery
    {
        return new RecordQuery(
            excluding: $this->unofferableIn($module),
            sorts: self::sortByTitle($module),
            page: max(1, $page),
            perPage: $perPage,
            // Looked for in exactly the fields the labels are built from, so
            // what matches is what is on the screen. Searching a field that is
            // not part of the name produces a result nobody can see the reason
            // for, which reads as the search being broken.
            search: new Search($search, array_map(
                static fn (\Xivi\Core\Entity\FieldDefinition $field): string => $field->getKey(),
                $module->getTitleFields(),
            )),
            variants: $variants,
        );
    }

    /**
     * What this module says may not be offered, as query conditions (XIV-175).
     *
     * @return list<Filter>
     */
    private function unofferableIn(ModuleDefinition $module): array
    {
        return $this->narrowingFor($module->getKey())?->unofferable($module) ?? [];
    }

    /**
     * The rule a module has about its own candidates, if it has one.
     *
     * **First match wins and there is deliberately no merging.** Two rules for
     * one module would be two places to look when a picker offers something it
     * should not, and this is a seam a module implements for itself: the module
     * that owns the records owns the sentence about which of them may be
     * chosen. A second implementation naming the same module is a mistake to
     * notice, and it is noticed by the picker behaving as the first one says.
     */
    private function narrowingFor(string $moduleKey): ?NarrowsCandidates
    {
        foreach ($this->narrowings as $narrowing) {
            if ($narrowing->moduleKey() === $moduleKey) {
                return $narrowing;
            }
        }

        return null;
    }

    /**
     * Whether one record in hand is of a kind this picker offers.
     *
     * The same question {@see self::queryFor()} asks the database, asked of a
     * record that is already loaded, which is what {@see self::byId()} has,
     * because the record comes out of the memo rather than out of a query
     * (XIV-54). Written once so the two answers cannot drift: a record the SQL
     * would exclude and this admits is precisely the hole the filtered list was
     * closing.
     *
     * A shape with no variant field admits nothing once a caller has named
     * kinds, which is {@see \Xivi\Core\Query\QueryCompiler}'s answer to the
     * same case and for the reason written there.
     *
     * @param list<string> $variants
     */
    private static function isOneOf(ModuleDefinition $module, Record $record, array $variants): bool
    {
        if ($variants === []) {
            return true;
        }

        $variantField = $module->getVariantField();

        return $variantField !== null && \in_array($record->get($variantField), $variants, true);
    }

    /**
     * Labels for a page of records, made distinguishable within it.
     *
     * **The rule moved out of here** (XIV-167) and is now
     * {@see DistinctLabels}, unchanged in what it is for and changed in one
     * thing it decides. It lived here because this was the only place that
     * needed it, and that stopped being true: an edit form fills its choice list
     * through {@see \Xivi\Core\Form\RecordChoiceLoader} instead, which reads one
     * stored id at a time through {@see self::byId()} and therefore had nothing
     * to disambiguate anything against. Two links sharing a title collapsed
     * there exactly the way they used to collapse in the select. Making that
     * path guard itself would have been a second rule for one question, and two
     * rules is how the picker and the form it belongs to come to spell the same
     * pair of records differently.
     *
     * The changed decision is the asymmetry: both of a colliding pair now carry
     * the id, where the first of them used to keep its plain label. The argument
     * is in {@see DistinctLabels}, and it turns on this class ordering by
     * `id DESC` while a stored set of links is sorted ascending, which gave the
     * two callers opposite answers about which of two records is "the" one.
     *
     * @param list<Record> $records
     *
     * @return list<Candidate>
     */
    private static function named(ModuleDefinition $module, array $records): array
    {
        $titles = [];

        foreach ($records as $record) {
            $titles[(int) $record->id] = RecordTitle::of($module, $record);
        }

        $labels = DistinctLabels::among($titles);
        $candidates = [];

        foreach ($records as $record) {
            $id = (int) $record->id;
            $candidates[] = new Candidate($id, $labels[$id]);
        }

        return $candidates;
    }

    /**
     * Ordered by what they are called, since that is what somebody is scanning.
     *
     * @return list<Sort>
     */
    private static function sortByTitle(ModuleDefinition $module): array
    {
        $first = $module->getTitleFields()[0] ?? null;

        return $first === null ? [] : [new Sort($first->getKey())];
    }

    private function accessTo(string $moduleKey): RecordAccess
    {
        return $this->access->accessFor($moduleKey, ModuleAction::View);
    }

    /**
     * The target's shape, or null when this customer does not have that module.
     *
     * §7.6 has not decided what a reference into an uninstalled module should
     * mean; offering nothing is at least honest, and is what the select already
     * did.
     */
    private function moduleOf(string $moduleKey): ?ModuleDefinition
    {
        try {
            return $this->metadata->get($moduleKey);
        } catch (ModuleNotInstalled) {
            return null;
        }
    }
}

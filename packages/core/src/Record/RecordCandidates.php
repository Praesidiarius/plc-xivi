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

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Permission\RecordAccessProvider;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
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
 * The variant narrows it the way `formOptions()` already narrows the select
 * (§5.5), so a person's employer offers companies rather than every contact —
 * and the same narrowing applies when a submitted id is checked, or the widget
 * would be a suggestion and the validation a different rule.
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
    ) {
    }

    /**
     * One page of candidates, optionally narrowed by what somebody has typed.
     *
     * @return list<Candidate>
     */
    public function find(string $moduleKey, ?string $variant, string $search = '', int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $module = $this->moduleOf($moduleKey);

        if ($module === null) {
            return [];
        }

        $found = $this->records->findBy(
            $module,
            $this->queryFor($module, $variant, $search, $page, $perPage),
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
     */
    public function count(string $moduleKey, ?string $variant, string $search = ''): int
    {
        $module = $this->moduleOf($moduleKey);

        if ($module === null) {
            return 0;
        }

        return $this->records->countBy(
            $module,
            $this->queryFor($module, $variant, $search, 1, self::PER_PAGE),
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
     * the wrong variant, and not yours. A caller that could tell them apart
     * would be a caller that can probe for which ids exist.
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
     */
    public function byId(string $moduleKey, ?string $variant, int $id): ?Candidate
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

        $variantField = $module->getVariantField();

        if ($variant !== null && $variantField !== null && ($record->get($variantField) ?? null) !== $variant) {
            return null;
        }

        return new Candidate((int) $record->id, RecordTitle::of($module, $record));
    }

    /**
     * The query all three paths share: the variant filter, the name search, the
     * ordering and the page.
     */
    private function queryFor(ModuleDefinition $module, ?string $variant, string $search, int $page, int $perPage): RecordQuery
    {
        $filters = [];
        $variantField = $module->getVariantField();

        if ($variant !== null && $variantField !== null) {
            $filters[] = new Filter($variantField, Operator::Equals, $variant);
        }

        return new RecordQuery(
            filters: $filters,
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
        );
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

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

namespace Xivi\Core\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\HoldsSeveralValues;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Permission\RecordAccessProvider;

/**
 * Turns a RecordQuery into SQL (§7.3) — the highest-risk component in the
 * system, and the reason collections were built before it.
 *
 * Three rules it exists to enforce:
 *
 * 1. **Nothing from a user is ever concatenated.** Field names are resolved
 *    against the customer's own definitions and become bound parameters;
 *    comparisons are a closed enum; the only text this class interpolates is a
 *    table name from a definition row and its own placeholder names.
 * 2. **A condition on a collection is a semi-join, never a join.** A contact with
 *    two addresses in Zürich is one contact. `EXISTS` asks "is there such a row"
 *    and returns the parent once; a JOIN would return it twice and quietly
 *    inflate every count and every page.
 * 3. **Sorting by a collection is refused.** With two addresses there are two
 *    cities and no answer, so this raises rather than picking one.
 *
 * 4. **Which records a person may see is a predicate here, not a check after
 *    loading** (§7.5). It arrived exactly where this docblock said it would:
 *    beside the soft-delete condition in compile(). A page filtered after
 *    fetching shows four rows under a total that says twenty-five, and the total
 *    is what somebody believes.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class QueryCompiler
{
    public function __construct(
        private Connection $connection,
        private FieldTypeRegistry $fieldTypes,
        // Both only for links (XIV-13): which module a reference points at, and
        // what the person may see of it. A filter that stays inside one module
        // touches neither.
        private MetadataRepository $metadata,
        private RecordAccessProvider $access,
    ) {
    }

    /** The one field type this compiler knows by name — see linkJoin(). */
    private const string REFERENCE = 'reference';

    /** The alias the records table carries, so a semi-join can name its parent. */
    public const string ALIAS = 'r';

    /**
     * Where a module's row records who it belongs to.
     *
     * A module's own table only — a collection's rows carry a parent instead
     * (§5.1), which is why access to a child resolves through the record that
     * owns it rather than being asked here.
     */
    public const string OWNER_COLUMN = 'owner_id';

    /**
     * The access restriction is a separate argument and deliberately **not** part
     * of RecordQuery.
     *
     * RecordQuery is built from request parameters by RecordQueryFactory. A
     * permission that travelled in the same object as the filters would be one
     * URL edit away from being chosen by the person it restricts, and a
     * permission the user can set is not a permission. Two arguments, two
     * origins, no way to confuse them.
     */
    public function compile(ModuleDefinition $module, RecordQuery $query, RecordAccess $access): CompiledQuery
    {
        $conditions = [sprintf('%s.deleted_at IS NULL', self::ALIAS)];
        $parameters = [];
        $types = [];
        $slot = 0;

        if ($access->isRestricted()) {
            $ownerId = $access->ownerId();

            if ($ownerId === null) {
                // Nobody's records. A false predicate rather than an early
                // return, so the count and the page still go through the same
                // statement shape and cannot come to different conclusions.
                $conditions[] = 'FALSE';
            } else {
                $conditions[] = sprintf('%s.%s = :access_owner', self::ALIAS, self::OWNER_COLUMN);
                $parameters['access_owner'] = $ownerId;
                $types['access_owner'] = ParameterType::INTEGER;
            }
        }

        foreach ($query->filters as $filter) {
            $conditions[] = $this->predicate($module, $filter, $slot, $parameters, $types);
        }

        if ($query->search !== null && !$query->search->isEmpty()) {
            $conditions[] = $this->searchGroup($module, $query->search, $slot, $parameters, $types);
        }

        return new CompiledQuery(
            where: implode(' AND ', $conditions),
            parameters: $parameters,
            types: $types,
            orderBy: $this->orderBy($module, $query),
        );
    }

    /**
     * One filter, as whatever kind of predicate its path turns out to name.
     *
     * The path does not say which it is — `addresses.city` and `company.name`
     * are the same syntax — so the shape is asked instead. A collection becomes
     * a semi-join and a reference becomes a link join; the definitions already
     * know which of the two a name is, and a marker in the URL would be a second
     * place to keep that in step (XIV-13).
     *
     * @param array<string, mixed>         $parameters
     * @param array<string, ParameterType> $types
     */
    private function predicate(
        ModuleDefinition $module,
        Filter $filter,
        int &$slot,
        array &$parameters,
        array &$types,
    ): string {
        if ($filter->through === null) {
            return $this->condition($module, $filter, $slot, $parameters, $types);
        }

        if ($module->getCollection($filter->through) !== null) {
            return $this->semiJoin($module, $filter, $slot, $parameters, $types);
        }

        $reference = $module->getField($filter->through);

        if ($reference !== null && $reference->getType() === self::REFERENCE) {
            return $this->linkJoin($module, $reference, $filter, $slot, $parameters, $types);
        }

        throw UnsupportedQuery::unknownCollection($filter->through, $module->getKey());
    }

    /**
     * One string looked for across several fields, as a parenthesised OR
     * (XIV-36).
     *
     * **The only disjunction this compiler emits**, and the parentheses are the
     * load-bearing part: without them the group's last term would bind to the
     * `AND` chain around it and the soft-delete and access predicates would stop
     * applying to everything, which is a permission bug wearing a syntax error's
     * clothes. See {@see Search} for why this is not the `OR` §5.3 refused.
     *
     * Every term goes through {@see self::comparison()} like any other
     * condition, so the value is bound, the field name is bound, and the type
     * still decides how its stored value is read. A field whose type does not
     * answer `contains` is skipped rather than refused — a shape named partly by
     * a date is still findable by the half of its name that is text, and raising
     * would turn one odd title field into a picker that cannot be typed into at
     * all.
     *
     * If that leaves nothing to look in, the answer is `FALSE` rather than the
     * unfiltered page: somebody typed something, and a search that silently
     * ignores what was typed shows a list that looks like a result and is not
     * one — §5.3's own objection to filters that quietly do nothing.
     *
     * @param array<string, mixed>         $parameters
     * @param array<string, ParameterType> $types
     */
    private function searchGroup(
        ModuleDefinition $module,
        Search $search,
        int &$slot,
        array &$parameters,
        array &$types,
    ): string {
        $terms = [];

        foreach ($search->fields as $key) {
            $field = $module->getField($key);

            if ($field === null) {
                continue;
            }

            if (!\in_array(Operator::Contains, $this->fieldTypes->get($field->getType())->operators(), true)) {
                continue;
            }

            $terms[] = $this->comparison(
                $field,
                new Filter($key, Operator::Contains, $search->text),
                self::ALIAS,
                $slot,
                $parameters,
                $types,
            );
        }

        return $terms === [] ? 'FALSE' : '(' . implode(' OR ', $terms) . ')';
    }

    /**
     * A condition on the module's own row.
     *
     * @param array<string, mixed>         $parameters
     * @param array<string, ParameterType> $types
     */
    private function condition(
        ModuleDefinition $module,
        Filter $filter,
        int &$slot,
        array &$parameters,
        array &$types,
    ): string {
        $field = $module->getField($filter->field)
            ?? throw UnsupportedQuery::unknownField($filter->path(), $module->getKey());

        return $this->comparison($field, $filter, self::ALIAS, $slot, $parameters, $types);
    }

    /**
     * A condition on a row of one of the module's collections.
     *
     * EXISTS rather than a join, per the class docblock. The child's own soft
     * delete is honoured too: an address that was removed should not keep its
     * contact in the results for a city nobody lives in any more.
     *
     * @param array<string, mixed>         $parameters
     * @param array<string, ParameterType> $types
     */
    private function semiJoin(
        ModuleDefinition $module,
        Filter $filter,
        int &$slot,
        array &$parameters,
        array &$types,
    ): string {
        \assert($filter->through !== null);

        $collection = $module->getCollection($filter->through)
            ?? throw UnsupportedQuery::unknownCollection($filter->through, $module->getKey());

        $field = $collection->getField($filter->field)
            ?? throw UnsupportedQuery::unknownField($filter->path(), $collection->getKey());

        $alias = 'c' . $slot;
        $comparison = $this->comparison($field, $filter, $alias, $slot, $parameters, $types);

        return sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s.%s = %s.id AND %s.deleted_at IS NULL AND %s)',
            $this->table($collection),
            $alias,
            $alias,
            CollectionDefinition::PARENT_COLUMN,
            self::ALIAS,
            $alias,
            $comparison,
        );
    }

    /**
     * A condition on the record this one links to (§7.6, XIV-13).
     *
     * `EXISTS` again, and for a different reason than the collection's: a
     * reference points at exactly one record, so a plain join would not multiply
     * rows — but it would turn a filter into an outer-join question about
     * records that may not exist, and `EXISTS` says what is meant. It also keeps
     * every filter the same shape, which is what stops this method from being
     * the start of a query builder.
     *
     * **The linked module's own permissions apply inside it.** Following a link
     * is reading the other module, and a filter that quietly ignored that would
     * let somebody sift records by a value they may not see — the inference
     * channel §8.4 is careful about. Somebody with no grant there gets a
     * predicate that matches nothing, which is the honest answer and not an
     * error: their list is simply empty of records that link anywhere.
     *
     * One hop only. `order.contact.city` from an invoice would be a second join
     * and a path nobody can reason about the cost of; it is refused with the
     * same message as any other unknown path.
     *
     * @param array<string, mixed>         $parameters
     * @param array<string, ParameterType> $types
     */
    private function linkJoin(
        ModuleDefinition $module,
        FieldDefinition $reference,
        Filter $filter,
        int &$slot,
        array &$parameters,
        array &$types,
    ): string {
        $targetKey = ReferenceFieldType::targetModule($reference);

        try {
            $target = $this->metadata->get($targetKey);
        } catch (ModuleNotInstalled) {
            // A link into a module this customer does not have (§3). Nothing to
            // join to, so nothing matches — the same answer as no permission,
            // and for the same reason it is not an error.
            return 'FALSE';
        }

        $field = $target->getField($filter->field)
            ?? throw UnsupportedQuery::unknownField($filter->path(), $target->getKey());

        $access = $this->access->accessFor($targetKey, ModuleAction::View);

        if ($access->matchesNothing()) {
            return 'FALSE';
        }

        $alias = 'l' . $slot;
        $conditions = [
            sprintf(
                '%s.id = (%s)::bigint',
                $alias,
                $this->storedValue(self::ALIAS, $reference),
            ),
            sprintf('%s.deleted_at IS NULL', $alias),
            $this->comparison($field, $filter, $alias, $slot, $parameters, $types),
        ];

        if ($access->isRestricted()) {
            ++$slot;
            $parameter = 'link_owner_' . $slot;
            $conditions[] = sprintf('%s.%s = :%s', $alias, self::OWNER_COLUMN, $parameter);
            $parameters[$parameter] = $access->ownerId();
            $types[$parameter] = ParameterType::INTEGER;
        }

        return sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s)',
            $this->table($target),
            $alias,
            implode(' AND ', $conditions),
        );
    }

    /**
     * One comparison, with its value bound.
     *
     * The type decides which comparisons it accepts and how its stored value has
     * to be read; this method only knows the shapes of the operators themselves.
     * That is what stops the compiler from growing a switch on field type.
     *
     * @param array<string, mixed>         $parameters
     * @param array<string, ParameterType> $types
     */
    private function comparison(
        FieldDefinition $field,
        Filter $filter,
        string $alias,
        int &$slot,
        array &$parameters,
        array &$types,
    ): string {
        $type = $this->fieldTypes->get($field->getType());
        $supported = $type->operators();

        if (!\in_array($filter->operator, $supported, true)) {
            throw UnsupportedQuery::operator($filter->operator, $filter->path(), $field->getType(), $supported);
        }

        // The field name is bound too. It comes from a definition row rather than
        // from a request, so this is belt and braces — but the belt costs nothing
        // and means one less thing to be sure about.
        $key = 'f' . $slot;
        $parameters[$key] = $field->getKey();
        $accessor = sprintf('%s.data->>:%s', $alias, $key);
        $value = $type->comparableSql($accessor);
        ++$slot;

        if (!$filter->operator->needsValue()) {
            return $filter->operator === Operator::IsEmpty
                ? sprintf("(%s IS NULL OR %s = '')", $accessor, $accessor)
                : sprintf("(%s IS NOT NULL AND %s <> '')", $accessor, $accessor);
        }

        $param = 'v' . $slot;
        ++$slot;

        [$sql, $bound] = match ($filter->operator) {
            // ILIKE, so a filter box is case-insensitive the way anyone typing
            // into one expects. The pattern is built here and the wildcards in
            // what the user typed are escaped, or searching for "50%" would match
            // everything beginning with 50.
            Operator::Contains => [sprintf('%s ILIKE :%s', $accessor, $param), '%' . self::escapeLike((string) $filter->value) . '%'],
            Operator::StartsWith => [sprintf('%s ILIKE :%s', $accessor, $param), self::escapeLike((string) $filter->value) . '%'],
            // IS DISTINCT FROM, not <>: a record with nothing in the field is
            // genuinely "not Zürich", and <> would drop it for being NULL.
            Operator::NotEquals => [sprintf('%s IS DISTINCT FROM :%s', $value, $param), $this->bind($type, $field, $filter->value)],
            Operator::Equals => [sprintf('%s = :%s', $value, $param), $this->bind($type, $field, $filter->value)],
            Operator::GreaterThan => [sprintf('%s > :%s', $value, $param), $this->bind($type, $field, $filter->value)],
            Operator::AtLeast => [sprintf('%s >= :%s', $value, $param), $this->bind($type, $field, $filter->value)],
            Operator::LessThan => [sprintf('%s < :%s', $value, $param), $this->bind($type, $field, $filter->value)],
            Operator::AtMost => [sprintf('%s <= :%s', $value, $param), $this->bind($type, $field, $filter->value)],
            // **Answered in the database, which is the point of it** (XIV-136).
            // "Which of these overlap today" over ten thousand bookings is one
            // `&&` against a GiST index — the same index the exclusion constraint
            // built ({@see \Xivi\Core\Record\OverlapExclusion}) — rather than ten
            // thousand records read into PHP to be sifted there.
            //
            // **The type's own expression on both sides**, and that is what keeps
            // this line free of any knowledge of what a period is.
            // `comparableSql()` is a pure text→SQL transform, so applying it to
            // the bound parameter builds the *same* range from the value somebody
            // typed as it builds from the column — one definition of "a period",
            // used twice, with no second parser here to disagree with the first.
            Operator::Overlaps => [
                sprintf('%s && %s', $value, $type->comparableSql(':' . $param)),
                $this->bind($type, $field, $filter->value),
            ],
            // **The type's own expression on both sides again** (XIV-113), and
            // for the reason the line above gives: `comparableSql()` is a pure
            // text→SQL transform, so applying it to the bound parameter builds
            // the same JSON array out of the one value somebody typed as it
            // builds out of the column, and this line stays free of any knowledge
            // of what a list is. `@>` is Postgres' containment operator, so
            // asking for 3 finds `[3, 12]` and does not find `[13]`, which a
            // joined string compared with LIKE would have got wrong.
            Operator::Includes => [
                sprintf('%s @> %s', $value, $type->comparableSql(':' . $param)),
                $this->bind($type, $field, $filter->value),
            ],
            // COALESCE, not a bare NOT: containment against a column holding
            // nothing is NULL rather than false, and `NOT NULL` is NULL, so a
            // record with no value at all would be dropped from a filter that is
            // true of it. Same correction `IS DISTINCT FROM` makes above.
            Operator::Excludes => [
                sprintf('NOT COALESCE(%s @> %s, FALSE)', $value, $type->comparableSql(':' . $param)),
                $this->bind($type, $field, $filter->value),
            ],
            default => throw UnsupportedQuery::operator($filter->operator, $filter->path(), $field->getType(), $supported),
        };

        $parameters[$param] = $bound;
        $types[$param] = ParameterType::STRING;

        return $sql;
    }

    /**
     * The value as the field type stores it, so a filter compares like with like
     * — a date typed as "2026-8-1" asks the same question as the stored
     * "2026-08-01". Bound as text and cast by comparableSql() where the type
     * needs it, so one odd value cannot break the statement.
     */
    private function bind(\Xivi\Core\Field\FieldType $type, FieldDefinition $field, mixed $value): string
    {
        $stored = $type->toStorage($value, $field);

        if (\is_array($stored)) {
            // A type that stores a list binds the JSON its column holds
            // (XIV-113), which is the same statement the scalar case makes: bind
            // what is stored, and let `comparableSql()` read it back the way it
            // reads the column back. Anything that will not encode is bound as
            // the empty array and matches nothing, which is what a filter on a
            // value nobody can express should do.
            return json_encode($stored, \JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        return \is_scalar($stored) ? (string) $stored : '';
    }

    /**
     * Sorting, always ending in the record's id.
     *
     * The tiebreaker is not decoration: without a total order, two records with
     * the same city can swap places between one page and the next, so a row is
     * shown twice and another never at all. Any list with LIMIT needs it.
     */
    private function orderBy(ModuleDefinition $module, RecordQuery $query): string
    {
        $terms = [];

        foreach ($query->sorts as $sort) {
            if (str_contains($sort->field, '.')) {
                throw UnsupportedQuery::sortingByCollection($sort->field);
            }

            $field = $module->getField($sort->field)
                ?? throw UnsupportedQuery::unknownField($sort->field, $module->getKey());

            $type = $this->fieldTypes->get($field->getType());

            // **A field holding several values has no place in an ordering**
            // (XIV-113), which is §5.3's refusal to sort by a collection with
            // fewer tables in it: a contact with four tags has four values and
            // none of them is the contact's, so there is nothing to compare the
            // next row against. Refused rather than sorted by the array's own
            // text, which would put `[10, 2]` before `[9]` and look like an
            // ordering nobody can explain.
            //
            // Asked of the capability rather than of the type's name, like every
            // other question here, and the list header asks the same thing
            // before it offers the link, so this is the guard behind a URL rather
            // than the thing a customer meets.
            if ($type instanceof HoldsSeveralValues) {
                throw UnsupportedQuery::sortingBySeveralValues($sort->field, $field->getType());
            }

            // Inline rather than bound: a parameter in ORDER BY is a value, and
            // Postgres would read it as "order by this constant" — which orders
            // by nothing at all. The key comes from a definition row and is
            // quoted as a literal here for that reason.
            $accessor = sprintf('%s.data->>%s', self::ALIAS, $this->connection->quote($field->getKey()));

            $terms[] = sprintf('%s %s', $type->comparableSql($accessor), $sort->direction->sql());
        }

        $terms[] = sprintf('%s.id DESC', self::ALIAS);

        return implode(', ', $terms);
    }

    /** Escapes what LIKE treats as special, so a literal % stays literal. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** A field's stored value, as text, for a place that binds no parameter. */
    private function storedValue(string $alias, FieldDefinition $field): string
    {
        return sprintf('%s.data->>%s', $alias, $this->connection->quote($field->getKey()));
    }

    private function table(ShapeDefinition $shape): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($shape->getTableName());
    }
}

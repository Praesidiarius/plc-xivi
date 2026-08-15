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
use Xivi\Core\Permission\RecordAccess;

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
    ) {
    }

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
            $conditions[] = $filter->collection === null
                ? $this->condition($module, $filter, $slot, $parameters, $types)
                : $this->semiJoin($module, $filter, $slot, $parameters, $types);
        }

        return new CompiledQuery(
            where: implode(' AND ', $conditions),
            parameters: $parameters,
            types: $types,
            orderBy: $this->orderBy($module, $query),
        );
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
        \assert($filter->collection !== null);

        $collection = $module->getCollection($filter->collection)
            ?? throw UnsupportedQuery::unknownCollection($filter->collection, $module->getKey());

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

    private function table(ShapeDefinition $shape): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($shape->getTableName());
    }
}

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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\QueryCompiler;
use Xivi\Core\Query\RecordQuery;

/**
 * Reads and writes the rows of any shape — a module's records, and the rows of
 * the collections hanging off them.
 *
 * There is deliberately no second repository for children. A contact's address
 * is stored, hydrated and soft-deleted by this same code, because a collection
 * is a shape and a shape is what this class knows how to read. The only place
 * the two kinds diverge is one column: a module's row names an owner, a
 * collection's names its parent.
 *
 * Reads are public. **Writes are internal to RecordWriter** (§5.2): every change
 * has to go through the unit of work that opens the transaction and records the
 * history, or the first caller to save a record directly writes no history at
 * all — and a history with holes in it is worse than no history, because it is
 * trusted. PHP has no package-private, so this is `@internal` and the fact that
 * nothing else calls it.
 *
 * Straight DBAL: the columns are known only at runtime, and the query layer
 * (§7.3) will need to build SQL over a mix of real columns and JSONB anyway.
 *
 * This class is the only place that knows *where* a custom field physically
 * lives. Today every one of them is a key in the JSONB payload; when column
 * promotion arrives (§5), it changes here and nothing above it has to.
 *
 * Which database it writes to is not its business either — it is handed a
 * connection, and the application points that at the tenant being served.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordRepository
{
    public function __construct(
        private Connection $connection,
        private FieldTypeRegistry $fieldTypes,
        private QueryCompiler $queries,
    ) {
    }

    /**
     * Records matching a query: filtered, sorted, and one page of them (§7.3).
     *
     * The SQL comes from the compiler, which is the only thing here allowed to
     * build a predicate. This method's job is the shape of the statement around
     * it — and to keep LIMIT and OFFSET as bound integers rather than as numbers
     * pasted into it.
     *
     * The access restriction is required rather than defaulted, because a
     * default would be a decision made by whoever wrote this signature on behalf
     * of every caller that ever forgets. Written out, a read that shows
     * everybody's records says so, and slice by slice they can be found by
     * grepping for RecordAccess::unrestricted().
     *
     * @return list<Record>
     */
    public function findBy(ModuleDefinition $module, RecordQuery $query, RecordAccess $access): array
    {
        $compiled = $this->queries->compile($module, $query, $access);

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %1$s.* FROM %2$s %1$s WHERE %3$s ORDER BY %4$s LIMIT :limit OFFSET :offset',
                QueryCompiler::ALIAS,
                $this->table($module),
                $compiled->where,
                $compiled->orderBy,
            ),
            [...$compiled->parameters, 'limit' => $query->perPage, 'offset' => $query->offset()],
            [...$compiled->types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(fn (array $row): Record => $this->hydrate($module, $row), $rows);
    }

    /**
     * How many match, ignoring the page.
     *
     * Counted with the same compiled predicate as the page itself, so the total
     * under a list can never disagree with the list — which it would the moment
     * two code paths built the same WHERE clause separately. That was already
     * true of filters; it now carries the access restriction too, which is the
     * case where disagreeing would not just look wrong but say out loud how many
     * records somebody is not allowed to see.
     */
    public function countBy(ModuleDefinition $module, RecordQuery $query, RecordAccess $access): int
    {
        $compiled = $this->queries->compile($module, $query, $access);

        return (int) $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM %2$s %1$s WHERE %3$s',
                QueryCompiler::ALIAS,
                $this->table($module),
                $compiled->where,
            ),
            $compiled->parameters,
            $compiled->types,
        );
    }

    public function find(ShapeDefinition $shape, int $id, bool $includeDeleted = false): ?Record
    {
        $sql = sprintf('SELECT * FROM %s WHERE id = :id', $this->table($shape));

        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $row = $this->connection->fetchAssociative($sql, ['id' => $id]);

        return $row === false ? null : $this->hydrate($shape, $row);
    }

    /**
     * Several records of one shape, by id, in one query (XIV-81, XIV-54).
     *
     * The sibling of {@see findChildrenOfAny()} one level sideways, and it exists
     * for exactly the same reason: a caller holding a list of ids wants the rows,
     * and a loop of `find()` is a query per row. **Two tickets arrived at this
     * method independently**, which is the best argument available that it is the
     * right shape. The dashboard's follow-up widget needed it first — follow-ups
     * live in one shared table and name a record each (§5.18), so resolving a
     * page of them back to something worth reading is one lookup per *module*,
     * not one per follow-up, and §5.16 names that N+1 as the cost a dashboard
     * cannot afford on the first page after signing in. Reference priming needed
     * the same thing for a different reason: a page holding a set of records
     * knows every id its references point at before it renders any of them, and
     * asking one at a time is how an invoice with 500 lines came to make 500
     * lookups of a handful of articles.
     *
     * **Not expressible through the query layer**, which is where this would
     * otherwise belong. §5.3 compiles conditions against the customer's own field
     * definitions, and `id` is not one of them — it is a column of the table
     * rather than a field of the shape, and teaching `Filter` about it would mean
     * a second kind of path with different resolution rules for the sake of one
     * caller. So this is a plain read, beside the other plain reads, with the
     * same soft-delete rule they all have.
     *
     * The ids are bound as an array parameter and never interpolated; the only
     * text this builds a statement out of is the table name off the definition
     * row, as everywhere else here. They are deduplicated first, because both
     * callers arrive with a column of values rather than a set — five hundred
     * lines naming the same six articles is the ordinary case.
     *
     * **Unordered on purpose.** Both callers are filling a lookup keyed by id
     * rather than drawing a list, so an ORDER BY here would be a sort nobody
     * reads. Deleted rows are excluded, which is what makes this agree with
     * {@see find()} — a stale reference has to look the same whether it was
     * primed or looked up, or priming would change what a page says rather than
     * only how many queries it took to say it.
     *
     * @param list<int> $ids
     *
     * @return list<Record>
     */
    public function findAny(ShapeDefinition $shape, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE id IN (:ids) AND deleted_at IS NULL', $this->table($shape)),
            ['ids' => array_values(array_unique($ids))],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_map(fn (array $row): Record => $this->hydrate($shape, $row), $rows);
    }

    /**
     * Deliberately minimal — ordering, filtering and pagination across JSONB and
     * real columns is §7.3, and guessing at it here would be the concatenated-SQL
     * mess that section exists to avoid.
     *
     * @return list<Record>
     */
    public function findAll(ShapeDefinition $shape, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE deleted_at IS NULL ORDER BY id DESC LIMIT :limit OFFSET :offset', $this->table($shape)),
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(fn (array $row): Record => $this->hydrate($shape, $row), $rows);
    }

    /**
     * The rows of one collection belonging to one record.
     *
     * In the order the customer put them in (XIV-21), with the id breaking ties
     * — a list somebody arranges, not a feed where the newest belongs on top.
     * Rows written before positions existed all sit at zero and therefore keep
     * the order they had.
     *
     * **`includeDeleted` is the same flag {@see self::find()} already carries**,
     * for the same one caller shape and no other (XIV-122). A `RecordChanged`
     * subscriber runs *after* the delete inside the same transaction (§5.2), so
     * the one moment at which what a record carried matters most — the moment it
     * stops carrying it — is also the one moment its rows are already behind a
     * tombstone. A voucher named on a line of an order somebody deletes has to be
     * given back, and there is nowhere else to read it from. Every ordinary
     * caller leaves the flag alone and sees exactly what it saw before.
     *
     * @return list<Record>
     */
    public function findChildren(CollectionDefinition $collection, int $parentId, bool $includeDeleted = false): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s WHERE %s = :parent%s ORDER BY %s ASC, id ASC',
                $this->table($collection),
                CollectionDefinition::PARENT_COLUMN,
                $includeDeleted ? '' : ' AND deleted_at IS NULL',
                CollectionDefinition::POSITION_COLUMN,
            ),
            ['parent' => $parentId],
            ['parent' => ParameterType::INTEGER],
        );

        return array_map(fn (array $row): Record => $this->hydrate($collection, $row), $rows);
    }

    /**
     * The rows of one collection belonging to any of these records.
     *
     * One query for a page of parents rather than one per parent: an export of
     * fifty thousand contacts would otherwise be fifty thousand queries for
     * their addresses.
     *
     * @param list<int> $parentIds
     *
     * @return list<Record>
     */
    public function findChildrenOfAny(CollectionDefinition $collection, array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s WHERE %s IN (:parents) AND deleted_at IS NULL ORDER BY %s ASC, %s ASC, id ASC',
                $this->table($collection),
                CollectionDefinition::PARENT_COLUMN,
                CollectionDefinition::PARENT_COLUMN,
                CollectionDefinition::POSITION_COLUMN,
            ),
            ['parents' => $parentIds],
            ['parents' => ArrayParameterType::INTEGER],
        );

        return array_map(fn (array $row): Record => $this->hydrate($collection, $row), $rows);
    }

    public function countAll(ShapeDefinition $shape): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE deleted_at IS NULL', $this->table($shape)),
        );
    }

    /** @internal Use RecordWriter, which owns the transaction and the history (§5.2). */
    public function save(ShapeDefinition $shape, Record $record): Record
    {
        $now = new \DateTimeImmutable();
        $payload = $this->encode($shape, $record->data);
        [$linkColumn, $linkValue] = $this->link($shape, $record);

        // A row of a collection also carries where it sits (XIV-21); a module's
        // record has nowhere to sit, so the column is not in its table at all.
        $ordered = $shape instanceof CollectionDefinition;
        $position = $ordered ? sprintf(', %s', CollectionDefinition::POSITION_COLUMN) : '';

        if ($record->isNew()) {
            $record->createdAt = $now;
            $record->updatedAt = $now;

            $id = $this->connection->fetchOne(
                sprintf(
                    'INSERT INTO %s (created_at, updated_at, %s, data%s) VALUES (:created, :updated, :link, :data%s) RETURNING id',
                    $this->table($shape),
                    $linkColumn,
                    $position,
                    $ordered ? ', :position' : '',
                ),
                [
                    'created' => $now->format('Y-m-d H:i:s'),
                    'updated' => $now->format('Y-m-d H:i:s'),
                    'link' => $linkValue,
                    'data' => $payload,
                    ...($ordered ? ['position' => $record->position ?? 0] : []),
                ],
                ['link' => ParameterType::INTEGER, 'position' => ParameterType::INTEGER],
            );

            $record->id = (int) $id;

            return $record;
        }

        $record->updatedAt = $now;

        $this->connection->executeStatement(
            sprintf(
                'UPDATE %s SET updated_at = :updated, %s = :link, data = :data%s WHERE id = :id',
                $this->table($shape),
                $linkColumn,
                $ordered ? sprintf(', %s = :position', CollectionDefinition::POSITION_COLUMN) : '',
            ),
            [
                'updated' => $now->format('Y-m-d H:i:s'),
                'link' => $linkValue,
                'data' => $payload,
                'id' => $record->id,
                ...($ordered ? ['position' => $record->position ?? 0] : []),
            ],
            ['link' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER, 'position' => ParameterType::INTEGER],
        );

        return $record;
    }

    /**
     * Soft delete, since §5 makes it a system column: a CRM that forgets on
     * command loses the audit trail with the record.
     *
     * A record's collections go with it. The foreign key cascades a *hard*
     * delete, which is not the path taken here, so the cascade is done in SQL
     * rather than left to the database — otherwise deleting a contact would
     * leave its addresses visible to anything that reads them directly.
     *
     * @internal Use RecordWriter, which owns the transaction and the history (§5.2).
     */
    public function delete(ShapeDefinition $shape, Record $record): void
    {
        if ($record->isNew()) {
            return;
        }

        $record->deletedAt = new \DateTimeImmutable();
        $stamp = $record->deletedAt->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            sprintf('UPDATE %s SET deleted_at = :deleted WHERE id = :id', $this->table($shape)),
            ['deleted' => $stamp, 'id' => $record->id],
            ['id' => ParameterType::INTEGER],
        );

        if (!$shape instanceof ModuleDefinition) {
            return;
        }

        foreach ($shape->getCollections() as $collection) {
            $this->connection->executeStatement(
                sprintf(
                    'UPDATE %s SET deleted_at = :deleted WHERE %s = :parent AND deleted_at IS NULL',
                    $this->table($collection),
                    CollectionDefinition::PARENT_COLUMN,
                ),
                ['deleted' => $stamp, 'parent' => $record->id],
                ['parent' => ParameterType::INTEGER],
            );
        }
    }

    /**
     * How many live records have *no* value for this field (XIV-91).
     *
     * The mirror of {@see countWithValue()} and not derivable from it, because
     * "all the records" is a third query and a soft-deleted row is in neither
     * answer. What it measures is the scale of a backfill: turning numbering on
     * writes a number into exactly these rows, and the confirmation page has to
     * be able to say how many that is before anybody agrees to it.
     *
     * The same emptiness the rest of this class means by empty — null or the
     * empty string — so what this counts and what
     * {@see \Xivi\Core\Numbering\NumberBackfill} then fills cannot disagree.
     */
    public function countWithoutValue(ShapeDefinition $shape, FieldDefinition $field): int
    {
        return (int) $this->connection->fetchOne(
            sprintf(
                "SELECT COUNT(*) FROM %s WHERE deleted_at IS NULL AND (data->>:field IS NULL OR data->>:field = '')",
                $this->table($shape),
            ),
            ['field' => $field->getKey()],
        );
    }

    /**
     * The live records with no value for this field, oldest first (XIV-91).
     *
     * **The order is the feature.** A number records when a document was made
     * (§5.10), so a backfill that walked the table in whatever order Postgres
     * felt like returning rows would put yesterday's contact ahead of the one
     * from 2019 and produce a numbering that means nothing. `created_at` is what
     * "when it was made" is stored in; the id breaks ties, and does it the same
     * way, since ids come out of an ascending identity column.
     *
     * Ids rather than records: the caller wants to write one key into each row
     * and hydrating the whole payload of every one of them to do it would be
     * work thrown away.
     *
     * @return list<int>
     */
    public function idsWithoutValue(ShapeDefinition $shape, FieldDefinition $field): array
    {
        $ids = $this->connection->fetchFirstColumn(
            sprintf(
                "SELECT id FROM %s WHERE deleted_at IS NULL AND (data->>:field IS NULL OR data->>:field = '')
                 ORDER BY created_at ASC, id ASC",
                $this->table($shape),
            ),
            ['field' => $field->getKey()],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * The values in one field that begin with a given text (XIV-91).
     *
     * Read by the numbering survey, which is looking for numbers a person typed
     * in before the field was numbered — so that the counter can be moved above
     * them instead of eventually rendering one of them onto a second record.
     *
     * The prefix is a *narrowing* and never the test. It is the literal text
     * ahead of the counter in the pattern ({@see
     * \Xivi\Core\Numbering\NumberFormat::literalPrefix()}), which throws away
     * everything that could not possibly be one of ours before it crosses to
     * PHP; deciding whether a value really is one is the pattern's own
     * arithmetic, on what comes back. An empty prefix therefore means "every
     * non-empty value", which is the correct answer for a pattern that starts
     * with its counter and not a degenerate case to guard against.
     *
     * Grouped, with the number of records behind each value, rather than one row
     * per record. Two things want that: the scan is looking for the *set* of
     * numbers in use and a repeated value adds nothing to it, and the page wants
     * to say how many **records** carry a recognisable number — which grouping
     * alone would have lost, since two contacts sharing a hand-typed reference
     * are two records the counter has to stay clear of and one value.
     *
     * `GROUP BY 1` rather than by repeating the expression, and that is a real
     * constraint rather than brevity: a named parameter used twice becomes two
     * *positional* parameters by the time Postgres sees it, so `data->>:field`
     * in the select list and `data->>:field` in the group-by are `$1` and `$2`
     * and are not recognised as the same expression at all. The ordinal names
     * the output column, which is unambiguous.
     *
     * @return array<string, int> the value => how many live records hold it
     */
    public function valueCountsStartingWith(ShapeDefinition $shape, FieldDefinition $field, string $prefix): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            sprintf(
                "SELECT data->>:field AS value, COUNT(*) AS held FROM %s
                 WHERE deleted_at IS NULL AND data->>:field IS NOT NULL AND data->>:field <> ''
                   AND data->>:field LIKE :prefix
                 GROUP BY 1",
                $this->table($shape),
            ),
            // Escaped, because a customer's pattern is a customer's text: a
            // prefix containing `%` or `_` would otherwise quietly turn into a
            // wildcard and match rows the pattern could never produce. Backslash
            // is Postgres's default LIKE escape and has to go first.
            ['field' => $field->getKey(), 'prefix' => str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $prefix) . '%'],
        );

        return array_map(intval(...), $rows);
    }

    /**
     * Which of these values live records still hold, and how many hold each
     * (XIV-144).
     *
     * What the editor asks before letting an option out of a `choice` field's
     * list. The question is deliberately the narrow one — *these* values, not
     * every value in the column — because the answer is used in a refusal
     * somebody has to act on: "3 records are Pallet" names the option to put
     * back or the records to change, where "the column has values in it" names
     * nothing.
     *
     * Values that nothing holds are simply absent from the result, so an empty
     * array is the whole of "nothing stands in the way" and the caller needs no
     * second query to find that out.
     *
     * An empty list of values asks nothing and returns nothing rather than
     * being sent to the database at all: `IN ()` is not valid SQL, so the guard
     * is load-bearing rather than an optimisation, and it makes "the customer
     * removed nothing" a case this method describes rather than one the caller
     * has to remember.
     *
     * `IN (:values)` and {@see ArrayParameterType}, not `= ANY(:values)`: DBAL
     * expands a list parameter into one placeholder per value, which is a list
     * of scalars rather than the array literal `ANY` wants. The two look
     * interchangeable in Postgres and are not interchangeable here.
     *
     * Live rows only, like every other count the editor refuses on: a record in
     * the recycle bin holding a removed option is not a record anybody is
     * looking at.
     *
     * @param list<string> $values
     * @param Operator     $held   which comparison finds a record holding one of
     *                             these, asked of the field's own type
     *                             ({@see \Xivi\Core\Field\Enumerates::findsHoldersBy()})
     *                             and never guessed here. See
     *                             {@see self::heldValues()} for what the two
     *                             answers compile to
     *
     * @return array<string, int> value => how many live records hold it, worst first
     */
    public function valueCountsAmong(
        ShapeDefinition $shape,
        FieldDefinition $field,
        array $values,
        Operator $held = Operator::Equals,
    ): array {
        if ($values === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            // Named once inside a subquery and grouped by the output column, for
            // the reason {@see self::duplicateValues()} sets out at length: the
            // same named parameter written twice is two positional parameters by
            // the time Postgres sees it, and two expressions it will not group.
            sprintf(
                'SELECT held_value, COUNT(*) AS held FROM (%s) AS values_held
                 WHERE held_value IN (:values)
                 GROUP BY held_value
                 ORDER BY COUNT(*) DESC, held_value ASC',
                self::heldValues($this->table($shape), $held),
            ),
            ['field' => $field->getKey(), 'values' => $values],
            ['values' => ArrayParameterType::STRING],
        );

        $held = [];

        foreach ($rows as $row) {
            $held[(string) $row['held_value']] = (int) $row['held'];
        }

        return $held;
    }

    /**
     * The other way round: which values live records hold that are **not** on a
     * given list ([XIV-127]).
     *
     * {@see self::valueCountsAmong()} answers "is anything in the way of taking
     * these away"; this answers "is anything in the way of only allowing
     * these", which is the question a field acquires the moment its values start
     * coming from somewhere else. Pointing a populated `choice` field at a
     * shared list is exactly that question, and answering it is what let a
     * shared list be an option on `choice` rather than a field type of its own
     * (§5.4).
     *
     * **The empty list is a real question here, and that is the difference from
     * the method above.** Asking "what does this column hold that is not among
     * *nothing*" is asking what the column holds at all, and it has a perfectly
     * good answer — so an empty `$values` runs the query without the `NOT IN`
     * rather than short-circuiting. The other method's guard exists because
     * `IN ()` is invalid SQL; here there is nothing to put in the parentheses in
     * the first place.
     *
     * Capped, because the reader is a refusal. A field somebody points at the
     * wrong list may hold four hundred distinct values that are not on it, and a
     * sentence listing four hundred is a sentence nobody reads; the caller asks
     * for one more than it means to print, which is how the message can tell
     * "these five" from "at least these five" without a second query — the same
     * trick {@see self::duplicateValues()} plays for the unique refusal.
     *
     * Empty and null are not "a value" and are excluded: a record with nothing
     * in the field is not a record holding something the list has not got, and
     * whether it may be empty at all is `required`'s question rather than this
     * one.
     *
     * @param list<string> $values what the field's values will be allowed to be
     * @param Operator     $held   which comparison finds a record holding one, on
     *                             {@see self::valueCountsAmong()}'s terms. It
     *                             matters more here than there: a field holding
     *                             several values is one whose whole *array* would
     *                             otherwise be read as a single value nothing on
     *                             the list matches, so pointing a populated one at
     *                             a list would be refused with a JSON array quoted
     *                             back at the customer
     *
     * @return array<string, int> value => how many live records hold it, worst first
     */
    public function valueCountsExcept(
        ShapeDefinition $shape,
        FieldDefinition $field,
        array $values,
        int $limit = 6,
        Operator $held = Operator::Equals,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            // The same subquery-and-group-by-the-output-column shape as
            // `valueCountsAmong()`, and for the same reason: one named parameter
            // written twice is two positional parameters by the time Postgres
            // sees it, and two expressions it will not group.
            sprintf(
                "SELECT held_value, COUNT(*) AS held FROM (%s) AS values_held
                 WHERE held_value IS NOT NULL AND held_value <> ''%s
                 GROUP BY held_value
                 ORDER BY COUNT(*) DESC, held_value ASC
                 LIMIT %d",
                self::heldValues($this->table($shape), $held),
                $values === [] ? '' : ' AND held_value NOT IN (:values)',
                $limit,
            ),
            $values === []
                ? ['field' => $field->getKey()]
                : ['field' => $field->getKey(), 'values' => $values],
            $values === [] ? [] : ['values' => ArrayParameterType::STRING],
        );

        $held = [];

        foreach ($rows as $row) {
            $held[(string) $row['held_value']] = (int) $row['held'];
        }

        return $held;
    }

    /**
     * Rewrite one value into another, in one statement, across a whole shape
     * ([XIV-127]).
     *
     * **@internal to {@see \Xivi\Core\ValueList\ValueListEditor::merge()}**, on
     * exactly the terms {@see self::setValues()} is internal to the numbering
     * backfill — and the reasoning transfers word for word, which is why it is
     * worth naming rather than repeating in full. A merge is **one
     * administrative act against a column**, not several hundred edits to
     * several hundred records: putting it through RecordWriter would mean a
     * transaction and a history entry per record, every deriver on the module
     * re-run against records nobody touched, and `updated_at` bumped to today on
     * every row. That last one decides it. "Zurich" and "Zürich" being the same
     * region is not news about any particular order, and stamping four hundred
     * orders as changed today in the act of saying so is precisely the confusion
     * §5.10 objected to.
     *
     * What replaces the history entry is the confirmation page, which says what
     * will happen and to how many records **before** it happens, rather than
     * describing it four hundred times afterwards.
     *
     * One statement rather than the loop the backfill uses, and the difference
     * is the shape of the work: a backfill computes a different value per row
     * and this writes one value everywhere it finds another, which is a `WHERE`
     * clause. Cheaper, and — the part that matters — atomic without depending on
     * the caller's transaction to make it so.
     *
     * @param Operator $held which comparison finds a record holding `$from`, on
     *                       {@see self::valueCountsAmong()}'s terms. A merge that
     *                       counted a field holding several values and then failed
     *                       to rewrite it would be the worst of the three: the
     *                       confirmation page would promise a number the statement
     *                       does not deliver, and §5.26's own warning about half of
     *                       somebody's data saying "Zurich" for ever would come
     *                       true through the mechanism written to prevent it
     *
     * @return int how many rows were rewritten
     */
    public function replaceValue(
        ShapeDefinition $shape,
        FieldDefinition $field,
        string $from,
        string $to,
        Operator $held = Operator::Equals,
    ): int {
        if ($held === Operator::Includes) {
            return $this->replaceValueAmongSeveral($shape, $field, $from, $to);
        }

        return (int) $this->connection->executeStatement(
            sprintf(
                'UPDATE %s SET data = jsonb_set(data, ARRAY[:field], to_jsonb(:to::text))
                 WHERE deleted_at IS NULL AND data->>:field = :from',
                $this->table($shape),
            ),
            ['field' => $field->getKey(), 'to' => $to, 'from' => $from],
        );
    }

    /**
     * The same merge, into a field holding a set of values ([XIV-169]).
     *
     * **A statement per row where the scalar case is one statement**, and the
     * reason is the canonical order rather than the containment test. Rewriting
     * `urgent` to `blocking` inside `["low", "urgent"]` has to de-duplicate,
     * because the record may already hold both, and then put what is left back
     * into the field's own option order, which is where `blocking` sits in the
     * customer's arrangement and not where `urgent` sat. Neither of those is
     * something SQL can be told: the order lives in the field definition, not in
     * the column. So the type is asked, which is the same seam every other write
     * in this class goes through ({@see self::encode()}), applied one row at a
     * time.
     *
     * The rows are read first and rewritten afterwards rather than in one
     * `UPDATE … RETURNING`, so the loop is over a fixed set and the count is the
     * rows that were actually written. It runs inside the merge's own transaction
     * ({@see \Xivi\Core\ValueList\ValueListEditor::merge()}), so a failure part
     * way through takes the whole merge back with it, which is the guarantee the
     * single statement gave for free and the only one worth keeping.
     *
     * `updated_at` is deliberately untouched, exactly as above: a merge is one
     * administrative act about what two values mean, not news about any record.
     */
    private function replaceValueAmongSeveral(
        ShapeDefinition $shape,
        FieldDefinition $field,
        string $from,
        string $to,
    ): int {
        $type = $this->fieldTypes->get($field->getType());

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT id, data->:field AS held FROM %s
                 WHERE deleted_at IS NULL AND jsonb_typeof(data->:field) = 'array'
                   AND data->:field @> to_jsonb(:from::text)",
                $this->table($shape),
            ),
            ['field' => $field->getKey(), 'from' => $from],
        );

        $statement = $this->connection->prepare(sprintf(
            'UPDATE %s SET data = jsonb_set(data, ARRAY[:field], :value::jsonb) WHERE id = :id',
            $this->table($shape),
        ));

        $written = 0;

        foreach ($rows as $row) {
            /** @var list<mixed> $values */
            $values = json_decode((string) $row['held'], true, flags: \JSON_THROW_ON_ERROR);

            $stored = $type->toStorage(
                array_map(static fn (mixed $value): mixed => $value === $from ? $to : $value, $values),
                $field,
            );

            $statement->bindValue('field', $field->getKey());
            $statement->bindValue('value', json_encode($stored, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
            $statement->bindValue('id', $row['id'], ParameterType::INTEGER);

            $written += (int) $statement->executeStatement();
        }

        return $written;
    }

    /**
     * The rows of one field's held values, one row per value ([XIV-169]).
     *
     * **The one place the two shapes of a stored value meet**, and the reason
     * every count above could stop caring which it was handed. A scalar field
     * holds one value per record, so the value *is* `data->>'key'`; a field
     * holding several holds a JSON array, and the value that a count, a refusal
     * or a merge is about is one element of it.
     *
     * Written as a set of rows rather than as a comparison because that is what
     * makes the callers identical: `IN (:values)`, `NOT IN (:values)`, the
     * grouping and the ordering are all written once and read the same, where a
     * containment predicate would have needed each of those three methods
     * rewritten in two spellings.
     *
     * The `jsonb_typeof` guard is not decoration. §5.4 keeps a removed field's
     * values, so a field added later with the same key meets whatever the old one
     * left, and `jsonb_array_elements_text` over a bare string is an error rather
     * than no rows. A record holding something this field never wrote counts as
     * holding nothing, which is the honest answer and the one the query layer
     * already gives ({@see \Xivi\Core\Field\Type\MultiChoiceFieldType::comparableSql()}).
     */
    private static function heldValues(string $table, Operator $held): string
    {
        return $held === Operator::Includes
            ? sprintf(
                "SELECT jsonb_array_elements_text(data->:field) AS held_value FROM %s
                 WHERE deleted_at IS NULL AND jsonb_typeof(data->:field) = 'array'",
                $table,
            )
            : sprintf('SELECT data->>:field AS held_value FROM %s WHERE deleted_at IS NULL', $table);
    }

    /**
     * Write one field's value into a set of records, and touch nothing else
     * (XIV-91).
     *
     * **@internal to {@see \Xivi\Core\Numbering\NumberBackfill}**, on the same
     * terms as the write methods above are internal to RecordWriter, and for
     * once the reasoning runs the other way — so it is worth writing down rather
     * than looking like an oversight.
     *
     * Every other write goes through RecordWriter because a record changed by a
     * person is a history entry, and a history with invisible gaps is worse than
     * none (§5.2). A backfill is not that. It is **one** administrative act
     * against a column, not several hundred edits to several hundred records,
     * and putting it through RecordWriter would have three consequences nobody
     * wants: a transaction and a history entry per record, every deriver on the
     * module re-run against records nobody touched, and — the one that actually
     * decides it — `updated_at` bumped to today on the whole table. A number is
     * supposed to record when a document was made; stamping every document as
     * changed today in the act of giving it one is precisely the confusion §5.10
     * is trying to prevent.
     *
     * So it writes the one key and leaves `updated_at` alone. What replaces the
     * history entry is the confirmation page, which names the scale before it
     * happens rather than describing it afterwards, three hundred times.
     *
     * A statement per row inside the caller's transaction. One statement over an
     * array of pairs would be fewer round trips and a `VALUES` list built out of
     * a loop anyway; at the size this runs at — the rows of one module that have
     * no number yet — the loop is the one somebody reading it can check.
     *
     * @param array<int, string> $values record id => the value it is given
     *
     * @return int how many rows were written
     */
    public function setValues(ShapeDefinition $shape, FieldDefinition $field, array $values): int
    {
        $statement = $this->connection->prepare(sprintf(
            'UPDATE %s SET data = jsonb_set(data, ARRAY[:field], to_jsonb(:value::text))
             WHERE id = :id AND deleted_at IS NULL',
            $this->table($shape),
        ));

        $written = 0;

        foreach ($values as $id => $value) {
            $statement->bindValue('field', $field->getKey());
            $statement->bindValue('value', $value);
            $statement->bindValue('id', $id, ParameterType::INTEGER);

            $written += (int) $statement->executeStatement();
        }

        return $written;
    }

    /**
     * Every live value in one field, with the row holding it ([XIV-146]).
     *
     * **@internal to {@see \Xivi\Core\Metadata\FieldTypeConversion}**, on the
     * same terms as the two methods above are internal to the numbering backfill
     * and to a list merge, and unlike either of them this one is *unbounded on
     * purpose*.
     *
     * Everything else in this class that reads a column reads a sample: the
     * duplicate check names five values, the option counts take a limit, and the
     * refusals they feed are messages somebody reads rather than proofs. A
     * conversion cannot be built on a sample. It rewrites every one of these
     * rows, so a dry run that looked at the first hundred would be promising
     * something about the other four hundred that it had not checked, and the
     * first value it had not seen would be a refusal arriving *after* the
     * customer agreed. The plan is exhaustive or it is a guess with a
     * confirmation button under it.
     *
     * The size that costs is therefore the size the operation was always going
     * to walk, and the values come back as text because that is the form the
     * `unique` index compares in (`data ->> 'key'`) and the form a message
     * prints.
     *
     * The parent is here for the collection case and null everywhere else. A
     * collection's events go in its parent's table (§5.2), so the conversion
     * needs to know which record a row belongs to before it can write the entry
     * that says what happened to it.
     *
     * **`$includeDeleted` is for a caller asking a different question** ([XIV-115]).
     * A conversion is about records somebody will read, so it walks live rows; a
     * check for files no record claims is about what is *held*, and a soft-deleted
     * record holds its values exactly as it did the day before it was deleted.
     * Walking live rows only, that check would report every deleted record's
     * attachment as an orphan, which is a check whose normal output is wrong.
     *
     * @return list<array{id: int, parent: int|null, value: string}> oldest first, so that
     *                                                               two runs over unchanged data touch the rows in the same order
     */
    public function valueHolders(ShapeDefinition $shape, FieldDefinition $field, bool $includeDeleted = false): array
    {
        $parent = $shape instanceof CollectionDefinition
            ? CollectionDefinition::PARENT_COLUMN
            : 'NULL';

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT id, %s AS parent_id, data->>:field AS held_value FROM %s
                 WHERE %s data->>:field IS NOT NULL AND data->>:field <> ''
                 ORDER BY id ASC",
                $parent,
                $this->table($shape),
                $includeDeleted ? '' : 'deleted_at IS NULL AND',
            ),
            ['field' => $field->getKey()],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'parent' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'value' => (string) $row['held_value'],
        ], $rows);
    }

    /**
     * Put one stored value into one row, exactly as given ([XIV-146]).
     *
     * **@internal to {@see \Xivi\Core\Metadata\FieldTypeConversion}**, and the
     * word doing the work is *exactly*. Every other write in this class goes
     * through {@see self::encode()}, which runs the whole payload back through
     * the field types before it stores it, and that is right for every caller
     * that has a record in hand: the type owns what its values look like on
     * disk. A conversion is the one caller for which it is wrong. The value it
     * has computed came out of the *new* type's reading while the definition
     * still says the old one, so re-encoding it here would hand the new type's
     * answer to the old type and store whatever came back, which is a third
     * spelling neither of them asked for.
     *
     * So the JSON is written as it stands, and the definition changes afterwards
     * in the same transaction, at which point the value on disk is what the new
     * type reads.
     *
     * Null removes the key rather than storing a JSON null, which is the same
     * emptiness {@see self::encode()} means by dropping it: "absent" and "set to
     * nothing" are one state here and always have been (§5).
     *
     * `updated_at` is deliberately not touched, and this is the one write in the
     * class where that needs saying rather than assuming. The conversion is an
     * administrative act, not an edit: it writes the record's history itself,
     * with the value it took away, and a column of four hundred contacts all
     * changed at 14:02 on a Tuesday would say nothing true about any of them.
     */
    public function writeStoredValue(ShapeDefinition $shape, FieldDefinition $field, int $id, mixed $value): void
    {
        $this->connection->executeStatement(
            sprintf(
                $value === null
                    ? 'UPDATE %s SET data = data - :field WHERE id = :id'
                    : 'UPDATE %s SET data = jsonb_set(data, ARRAY[:field], :value::jsonb) WHERE id = :id',
                $this->table($shape),
            ),
            $value === null
                ? ['field' => $field->getKey(), 'id' => $id]
                : [
                    'field' => $field->getKey(),
                    'value' => json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                    'id' => $id,
                ],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * How many live records hold a value for this field.
     *
     * What the metadata editor puts in front of somebody about to remove a field
     * (§5.4): the definition goes, the values stay, and this is how many there
     * are. A number beats "this may affect stored data".
     */
    public function countWithValue(ShapeDefinition $shape, FieldDefinition $field): int
    {
        return (int) $this->connection->fetchOne(
            sprintf(
                "SELECT COUNT(*) FROM %s WHERE deleted_at IS NULL AND data->>:field IS NOT NULL AND data->>:field <> ''",
                $this->table($shape),
            ),
            ['field' => $field->getKey()],
        );
    }

    /**
     * How many live records would fail a rule that is about to be switched on.
     *
     * Making a field required, or unique, is a promise about data that already
     * exists. Applying it blind leaves records that cannot be saved again until
     * somebody works out why — so the editor counts first and refuses.
     */
    public function countViolating(ShapeDefinition $shape, FieldDefinition $field, bool $required, bool $unique): int
    {
        return $this->countViolatingKey($shape, $field->getKey(), $required, $unique);
    }

    /**
     * The same question about a field that does not exist yet (XIV-70).
     *
     * The upgrade asks it before adding a field a blueprint declares, and there
     * is nothing to hand it a definition for: the point of the question is
     * whether the definition can be created carrying its blueprint's rules, or
     * has to arrive with them switched off because the records that already
     * exist could not satisfy them.
     *
     * A key rather than a definition is enough because a key is all the query
     * ever used — the value lives under it in the JSONB payload — and it is the
     * honest signature for the case that makes this necessary: **values can
     * outlive their definition**. §5.4's removal takes the row and leaves what is
     * stored, so a key with no field can still have duplicates in it, and
     * assuming a new field is empty everywhere is exactly the assumption that
     * would be wrong on the one installation where it mattered.
     */
    public function countViolatingKey(ShapeDefinition $shape, string $key, bool $required, bool $unique): int
    {
        $table = $this->table($shape);
        $violations = 0;

        if ($required) {
            $violations += (int) $this->connection->fetchOne(
                sprintf(
                    "SELECT COUNT(*) FROM %s WHERE deleted_at IS NULL AND (data->>:field IS NULL OR data->>:field = '')",
                    $table,
                ),
                ['field' => $key],
            );
        }

        if ($unique) {
            // Rows sharing a value, not groups of them: two records with the same
            // email are two records to fix.
            $violations += (int) $this->connection->fetchOne(
                sprintf(
                    "SELECT COALESCE(SUM(held), 0) FROM (
                         SELECT COUNT(*) AS held FROM %s
                         WHERE deleted_at IS NULL AND data->>:field IS NOT NULL AND data->>:field <> ''
                         GROUP BY data->>:field HAVING COUNT(*) > 1
                     ) AS duplicates",
                    $table,
                ),
                ['field' => $key],
            );
        }

        return $violations;
    }

    /**
     * The values more than one live record holds, and how many hold each
     * (XIV-109).
     *
     * The sibling of {@see self::countViolating()} and deliberately not a
     * replacement for it: that answers "how many records would this rule
     * invalidate", which is the number a refusal is measured in, and this
     * answers "which values are the problem", which is what somebody has to go
     * and fix. A count on its own sends a customer scrolling a list of six
     * hundred contacts looking for the two that share a phone number.
     *
     * **Capped, and the cap is a decision.** A column that is duplicated in a
     * thousand distinct ways is a column that was never meant to be unique, and
     * printing all of it into a flash message would be a refusal nobody can
     * read. The first few, ordered by how many records share each, put the worst
     * offenders in front of the reader; the count beside them says how much is
     * not shown.
     *
     * Same WHERE clause as the index it exists to protect
     * ({@see UniqueIndex}) — live rows, non-empty values — because a report of
     * duplicates that counted rows the index ignores would name values that are
     * not in anybody's way.
     *
     * @return array<string, int> value => how many live records hold it, worst first
     */
    public function duplicateValues(ShapeDefinition $shape, FieldDefinition $field, int $limit = 5): array
    {
        $rows = $this->connection->fetchAllAssociative(
            // Grouped over a subquery rather than over the expression itself,
            // and that is not a stylistic choice: a named parameter used more
            // than once is rewritten into several positional ones, so
            // `GROUP BY data->>$3` is a different expression from
            // `SELECT data->>$1` as far as Postgres is concerned, and it says so.
            // Naming the value once, inside, leaves one thing to group by.
            sprintf(
                'SELECT held_value, COUNT(*) AS held FROM (
                     SELECT data->>:field AS held_value FROM %s
                     WHERE deleted_at IS NULL AND data->>:field IS NOT NULL
                 ) AS values_held
                 GROUP BY held_value
                 HAVING COUNT(*) > 1
                 ORDER BY COUNT(*) DESC, held_value ASC
                 LIMIT :limit',
                $this->table($shape),
            ),
            ['field' => $field->getKey(), 'limit' => max($limit, 1)],
            ['limit' => ParameterType::INTEGER],
        );

        $duplicates = [];

        foreach ($rows as $row) {
            $duplicates[(string) $row['held_value']] = (int) $row['held'];
        }

        return $duplicates;
    }

    /** Backs the unique-field constraint; `exceptId` is the record being edited. */
    public function existsWithValue(ShapeDefinition $shape, FieldDefinition $field, mixed $value, ?int $exceptId = null): bool
    {
        if ($value === null) {
            // Two records with nothing in a field are not duplicates of each other.
            return false;
        }

        $sql = sprintf(
            'SELECT 1 FROM %s WHERE data->>:field = :value AND deleted_at IS NULL',
            $this->table($shape),
        );
        $params = ['field' => $field->getKey(), 'value' => (string) $value];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except';
            $params['except'] = $exceptId;
        }

        return $this->connection->fetchOne($sql . ' LIMIT 1', $params) !== false;
    }

    /**
     * The one column where the two kinds of shape differ.
     *
     * @return array{string, int|null}
     */
    private function link(ShapeDefinition $shape, Record $record): array
    {
        if (!$shape instanceof CollectionDefinition) {
            return ['owner_id', $record->ownerId];
        }

        if ($record->parentId === null) {
            // Caught here rather than by the not-null constraint, because "null
            // value violates not-null constraint" does not say which of the two
            // things above this line forgot to set it.
            throw new \InvalidArgumentException(sprintf(
                'A row of collection "%s" needs the id of the record it belongs to.',
                $shape->getKey(),
            ));
        }

        return [CollectionDefinition::PARENT_COLUMN, $record->parentId];
    }

    /**
     * A shape's values in the form they are stored in, every declared key
     * present, nulls included.
     *
     * Public because comparing two versions of a record is only meaningful in
     * this form: a date submitted as a string and a date read back as an object
     * are the same value, and would otherwise look like a change every time
     * (§5.2).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function storageValues(ShapeDefinition $shape, array $data): array
    {
        $values = [];

        foreach ($shape->getFields() as $field) {
            $values[$field->getKey()] = $this->fieldTypes
                ->get($field->getType())
                ->toStorage($data[$field->getKey()] ?? null, $field);
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return string JSON, ready for a jsonb column
     */
    private function encode(ShapeDefinition $shape, array $data): string
    {
        // Absent rather than null: a JSONB payload full of nulls is noise, and
        // "key missing" and "key set to null" should not become two states.
        $encoded = array_filter(
            $this->storageValues($shape, $data),
            static fn (mixed $value): bool => $value !== null,
        );

        return json_encode($encoded, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(ShapeDefinition $shape, array $row): Record
    {
        $stored = \is_string($row['data'] ?? null)
            ? json_decode($row['data'], true, flags: \JSON_THROW_ON_ERROR)
            : [];
        \assert(\is_array($stored));

        $data = [];
        foreach ($shape->getFields() as $field) {
            $data[$field->getKey()] = $this->fieldTypes
                ->get($field->getType())
                ->fromStorage($stored[$field->getKey()] ?? null, $field);
        }

        $parent = $row[CollectionDefinition::PARENT_COLUMN] ?? null;

        return new Record(
            data: $data,
            id: (int) $row['id'],
            ownerId: isset($row['owner_id']) ? (int) $row['owner_id'] : null,
            parentId: $parent === null ? null : (int) $parent,
            position: isset($row[CollectionDefinition::POSITION_COLUMN])
                ? (int) $row[CollectionDefinition::POSITION_COLUMN]
                : null,
            createdAt: self::toDateTime($row['created_at'] ?? null),
            updatedAt: self::toDateTime($row['updated_at'] ?? null),
            deletedAt: self::toDateTime($row['deleted_at'] ?? null),
        );
    }

    private static function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    /**
     * The table name comes from a definition row written by the installer, never
     * from user input — but quoting it costs nothing and means one less thing to
     * be sure about.
     */
    private function table(ShapeDefinition $shape): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($shape->getTableName());
    }
}

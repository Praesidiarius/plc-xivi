<?php

declare(strict_types=1);

namespace Xivi\Core\Record;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
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
     * @return list<Record>
     */
    public function findBy(ModuleDefinition $module, RecordQuery $query): array
    {
        $compiled = $this->queries->compile($module, $query);

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
     * two code paths built the same WHERE clause separately.
     */
    public function countBy(ModuleDefinition $module, RecordQuery $query): int
    {
        $compiled = $this->queries->compile($module, $query);

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
     * Ascending by id, not descending: these are a list a person edits in order —
     * the first address is the first one they typed — rather than a feed where
     * the newest belongs on top.
     *
     * @return list<Record>
     */
    public function findChildren(CollectionDefinition $collection, int $parentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s WHERE %s = :parent AND deleted_at IS NULL ORDER BY id ASC',
                $this->table($collection),
                CollectionDefinition::PARENT_COLUMN,
            ),
            ['parent' => $parentId],
            ['parent' => ParameterType::INTEGER],
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

        if ($record->isNew()) {
            $record->createdAt = $now;
            $record->updatedAt = $now;

            $id = $this->connection->fetchOne(
                sprintf(
                    'INSERT INTO %s (created_at, updated_at, %s, data) VALUES (:created, :updated, :link, :data) RETURNING id',
                    $this->table($shape),
                    $linkColumn,
                ),
                [
                    'created' => $now->format('Y-m-d H:i:s'),
                    'updated' => $now->format('Y-m-d H:i:s'),
                    'link' => $linkValue,
                    'data' => $payload,
                ],
                ['link' => ParameterType::INTEGER],
            );

            $record->id = (int) $id;

            return $record;
        }

        $record->updatedAt = $now;

        $this->connection->executeStatement(
            sprintf('UPDATE %s SET updated_at = :updated, %s = :link, data = :data WHERE id = :id', $this->table($shape), $linkColumn),
            [
                'updated' => $now->format('Y-m-d H:i:s'),
                'link' => $linkValue,
                'data' => $payload,
                'id' => $record->id,
            ],
            ['link' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
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
        $table = $this->table($shape);
        $violations = 0;

        if ($required) {
            $violations += (int) $this->connection->fetchOne(
                sprintf(
                    "SELECT COUNT(*) FROM %s WHERE deleted_at IS NULL AND (data->>:field IS NULL OR data->>:field = '')",
                    $table,
                ),
                ['field' => $field->getKey()],
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
                ['field' => $field->getKey()],
            );
        }

        return $violations;
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

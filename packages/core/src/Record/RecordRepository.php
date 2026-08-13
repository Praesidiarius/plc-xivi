<?php

declare(strict_types=1);

namespace Xivi\Core\Record;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;

/**
 * Reads and writes module records.
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
    ) {
    }

    public function find(ModuleDefinition $module, int $id, bool $includeDeleted = false): ?Record
    {
        $sql = sprintf('SELECT * FROM %s WHERE id = :id', $this->table($module));

        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $row = $this->connection->fetchAssociative($sql, ['id' => $id]);

        return $row === false ? null : $this->hydrate($module, $row);
    }

    /**
     * Deliberately minimal — ordering, filtering and pagination across JSONB and
     * real columns is §7.3, and guessing at it here would be the concatenated-SQL
     * mess that section exists to avoid.
     *
     * @return list<Record>
     */
    public function findAll(ModuleDefinition $module, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM %s WHERE deleted_at IS NULL ORDER BY id DESC LIMIT :limit OFFSET :offset', $this->table($module)),
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(fn (array $row): Record => $this->hydrate($module, $row), $rows);
    }

    public function countAll(ModuleDefinition $module): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE deleted_at IS NULL', $this->table($module)),
        );
    }

    public function save(ModuleDefinition $module, Record $record): Record
    {
        $now = new \DateTimeImmutable();
        $payload = $this->encode($module, $record->data);

        if ($record->isNew()) {
            $record->createdAt = $now;
            $record->updatedAt = $now;

            $id = $this->connection->fetchOne(
                sprintf(
                    'INSERT INTO %s (created_at, updated_at, owner_id, data) VALUES (:created, :updated, :owner, :data) RETURNING id',
                    $this->table($module),
                ),
                [
                    'created' => $now->format('Y-m-d H:i:s'),
                    'updated' => $now->format('Y-m-d H:i:s'),
                    'owner' => $record->ownerId,
                    'data' => $payload,
                ],
                ['owner' => ParameterType::INTEGER],
            );

            $record->id = (int) $id;

            return $record;
        }

        $record->updatedAt = $now;

        $this->connection->executeStatement(
            sprintf('UPDATE %s SET updated_at = :updated, owner_id = :owner, data = :data WHERE id = :id', $this->table($module)),
            [
                'updated' => $now->format('Y-m-d H:i:s'),
                'owner' => $record->ownerId,
                'data' => $payload,
                'id' => $record->id,
            ],
            ['owner' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );

        return $record;
    }

    /**
     * Soft delete, since §5 makes it a system column: a CRM that forgets on
     * command loses the audit trail with the record.
     */
    public function delete(ModuleDefinition $module, Record $record): void
    {
        if ($record->isNew()) {
            return;
        }

        $record->deletedAt = new \DateTimeImmutable();

        $this->connection->executeStatement(
            sprintf('UPDATE %s SET deleted_at = :deleted WHERE id = :id', $this->table($module)),
            ['deleted' => $record->deletedAt->format('Y-m-d H:i:s'), 'id' => $record->id],
            ['id' => ParameterType::INTEGER],
        );
    }

    /** Backs the unique-field constraint; `exceptId` is the record being edited. */
    public function existsWithValue(ModuleDefinition $module, FieldDefinition $field, mixed $value, ?int $exceptId = null): bool
    {
        if ($value === null) {
            // Two records with nothing in a field are not duplicates of each other.
            return false;
        }

        $sql = sprintf(
            'SELECT 1 FROM %s WHERE data->>:field = :value AND deleted_at IS NULL',
            $this->table($module),
        );
        $params = ['field' => $field->getKey(), 'value' => (string) $value];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except';
            $params['except'] = $exceptId;
        }

        return $this->connection->fetchOne($sql . ' LIMIT 1', $params) !== false;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return string JSON, ready for a jsonb column
     */
    private function encode(ModuleDefinition $module, array $data): string
    {
        $encoded = [];

        foreach ($module->getFields() as $field) {
            $value = $this->fieldTypes->get($field->getType())->toStorage($data[$field->getKey()] ?? null, $field);

            // Absent rather than null: a JSONB payload full of nulls is noise, and
            // "key missing" and "key set to null" should not become two states.
            if ($value !== null) {
                $encoded[$field->getKey()] = $value;
            }
        }

        return json_encode($encoded, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(ModuleDefinition $module, array $row): Record
    {
        $stored = \is_string($row['data'] ?? null)
            ? json_decode($row['data'], true, flags: \JSON_THROW_ON_ERROR)
            : [];
        \assert(\is_array($stored));

        $data = [];
        foreach ($module->getFields() as $field) {
            $data[$field->getKey()] = $this->fieldTypes
                ->get($field->getType())
                ->fromStorage($stored[$field->getKey()] ?? null, $field);
        }

        return new Record(
            data: $data,
            id: (int) $row['id'],
            ownerId: isset($row['owner_id']) ? (int) $row['owner_id'] : null,
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
    private function table(ModuleDefinition $module): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($module->getTableName());
    }
}

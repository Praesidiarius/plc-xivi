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

namespace Xivi\Core\Module;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * Installs a module into one tenant's database: its tables, and its definitions.
 *
 * This runs per customer rather than per deploy, which is why it is a service
 * and not a migration. Migrations describe a schema every tenant shares; a
 * module's table exists only where that module is enabled (§4).
 *
 * The system columns come from §5 and are the same for every shape — id,
 * timestamps, soft delete — with the custom long tail in a JSONB payload. A
 * module's table also carries an owner; a collection's carries a parent instead.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleInstaller
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private MetadataRepository $metadata,
        private FieldTypeRegistry $fieldTypes,
    ) {
    }

    /**
     * Idempotent: installing a module a customer already has returns what they
     * have, untouched — including when the blueprint has grown a field or a
     * collection since. §6.1 is explicit that a blueprint is a seed and the
     * customer's definitions are the truth afterwards, so retro-fitting changes
     * here would quietly overrule that. Bringing an existing installation up to a
     * newer blueprint is a different operation and needs §7.2 answered first.
     */
    public function install(ModuleBlueprint $blueprint, ?string $preset = null): ModuleDefinition
    {
        $existing = $this->metadata->find($blueprint->key);

        if ($existing !== null) {
            return $existing;
        }

        $this->assertTypesExist($blueprint);
        $this->assertTableNameFits($blueprint->table);

        $fields = $this->fieldsFor($blueprint, $preset);

        $this->createRecordTable($blueprint->table, parentTable: null);
        $this->createHistoryTable($blueprint->table);

        $module = new ModuleDefinition(
            $blueprint->key,
            $blueprint->label,
            $blueprint->table,
            $blueprint->icon,
            $blueprint->variantField,
        );
        $this->defineFields($module, $fields);

        foreach ($blueprint->collections as $collection) {
            $this->createRecordTable($collection->table, parentTable: $blueprint->table);

            $definition = new CollectionDefinition(
                parent: $module,
                key: $collection->key,
                label: $collection->label,
                tableName: $collection->table,
                position: $collection->position,
            );
            $this->defineFields($definition, $collection->fields);
        }

        $this->entityManager->persist($module);
        $this->entityManager->flush();

        return $module;
    }

    /**
     * Which of the blueprint's fields this installation gets (§6.1).
     *
     * A preset names a subset; every collection is installed either way, because
     * a customer can add a field back later in the editor and cannot add a
     * collection back at all — see ModulePreset.
     *
     * Order comes from the blueprint, not from the preset: the module author
     * decided what sits next to what, and a preset is choosing which of those to
     * take, not rearranging them.
     *
     * @return list<FieldBlueprint>
     */
    private function fieldsFor(ModuleBlueprint $blueprint, ?string $preset): array
    {
        $preset ??= $blueprint->defaultPreset;

        if ($preset === null) {
            return $blueprint->fields;
        }

        $chosen = $blueprint->preset($preset) ?? throw new \RuntimeException(sprintf(
            'Module "%s" has no preset "%s". It offers: %s.',
            $blueprint->key,
            $preset,
            implode(', ', $blueprint->presetKeys()) ?: 'none',
        ));

        $fields = array_values(array_filter(
            $blueprint->fields,
            static fn (FieldBlueprint $field): bool => \in_array($field->key, $chosen->fields, true),
        ));

        // A preset naming a field the module does not have is the module author's
        // typo, and it would install a shape quietly missing something. Caught
        // here because nothing downstream would ever notice.
        $missing = array_diff($chosen->fields, array_map(
            static fn (FieldBlueprint $field): string => $field->key,
            $fields,
        ));

        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                'Preset "%s" of module "%s" names fields that do not exist: %s.',
                $chosen->key,
                $blueprint->key,
                implode(', ', $missing),
            ));
        }

        return $fields;
    }

    /**
     * Fails loudly before anything is written, rather than at the first save,
     * when the definitions would already exist and the data be unreadable.
     */
    private function assertTypesExist(ModuleBlueprint $blueprint): void
    {
        foreach ($blueprint->fields as $field) {
            $this->fieldTypes->get($field->type);
        }

        foreach ($blueprint->collections as $collection) {
            foreach ($collection->fields as $field) {
                $this->fieldTypes->get($field->type);

                if ($field->unique) {
                    throw new \RuntimeException(sprintf(
                        'Field "%s" of collection "%s" is marked unique. Unique across the whole table and '
                        . 'unique within one parent record are different rules, and the engine will not guess '
                        . 'which was meant. See docs/architecture.md §7.',
                        $field->key,
                        $collection->key,
                    ));
                }
            }
        }
    }

    /**
     * Postgres truncates identifiers at 63 characters, and a module's history
     * table is its own name plus a suffix. Truncation would silently point two
     * modules at one table, so it is refused here where the name is still the
     * author's to change.
     */
    private function assertTableNameFits(string $table): void
    {
        $longest = \strlen($table . ModuleDefinition::HISTORY_SUFFIX);

        if ($longest > 63) {
            throw new \RuntimeException(sprintf(
                'Table name "%s" leaves no room for its history table: "%s" is %d characters and Postgres '
                . 'allows 63.',
                $table,
                $table . ModuleDefinition::HISTORY_SUFFIX,
                $longest,
            ));
        }
    }

    /**
     * A module's history (§5.2): fixed columns, one table per module, and a real
     * foreign key — which is the whole point of not having one shared table.
     */
    private function createHistoryTable(string $recordTable): void
    {
        $name = $recordTable . ModuleDefinition::HISTORY_SUFFIX;
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist([$name])) {
            throw new \RuntimeException(sprintf(
                'Table "%s" already exists but nothing here has definitions for it. Refusing to adopt a '
                . 'table this installer did not create.',
                $name,
            ));
        }

        $table = new Table($name);
        // bigint from the start. This is the table that grows without bound, and
        // widening a primary key later is not a migration anyone enjoys.
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $table->addColumn('record_id', Types::INTEGER);
        $table->addColumn('occurred_at', Types::DATETIMETZ_IMMUTABLE);
        // No foreign key on the user, for the same reason records have none: core
        // does not know what a user is. The label beside it is who they were at
        // the time, so a rename cannot rewrite the past.
        $table->addColumn('user_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('user_label', Types::TEXT, ['notnull' => false]);
        $table->addColumn('action', Types::STRING, ['length' => 31]);
        $table->addColumn('changes', Types::JSON, ['platformOptions' => ['jsonb' => true]]);
        $table->setPrimaryKey(['id']);

        // The only question this table is ever asked: one record's timeline,
        // newest first. Postgres reads a btree backwards, so ascending serves
        // ORDER BY id DESC without a second index. Nothing else is indexed until
        // something actually asks for it — over-indexing is half of what makes
        // these tables hurt.
        $table->addIndex(['record_id', 'id'], sprintf('idx_%s_record', $name));

        $schemaManager->createTable($table);

        // The foreign key is added here rather than on the Table above, and that
        // is not a style choice. DBAL indexes a key's columns for you unless an
        // existing index *of the same width* covers them — so the composite
        // index leading with record_id does not count, and going through the
        // schema API would leave a second index on (record_id) alone. On the
        // fastest-growing table in the system, that is a write paid on every
        // insert to answer a question nothing asks.
        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (record_id) REFERENCES %s (id) ON DELETE CASCADE',
            $name,
            sprintf('fk_%s_record', $name),
            $recordTable,
        ));
    }

    /** @param list<FieldBlueprint> $fields */
    private function defineFields(ShapeDefinition $shape, array $fields): void
    {
        foreach ($fields as $field) {
            $definition = new FieldDefinition(
                shape: $shape,
                key: $field->key,
                label: $field->label,
                type: $field->type,
                required: $field->required,
                unique: $field->unique,
                filterable: $field->filterable,
                listed: $field->listed,
                title: $field->title,
                position: $field->position,
                system: true,
            );
            $definition->setOptions($field->options);
            $definition->setVariants($field->variants);
        }
    }

    /**
     * One table shape for both kinds of shape. A collection differs by two
     * columns: it gains a parent, and it loses the owner — an address is not
     * owned by somebody other than the contact it belongs to, and giving it its
     * own owner column would invite exactly that. Record-level access to a child
     * resolves through its parent, which is also what §7.5 will need.
     */
    private function createRecordTable(string $name, ?string $parentTable): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist([$name])) {
            throw new \RuntimeException(sprintf(
                'Table "%s" already exists but nothing here has definitions for it. Refusing to adopt a '
                . 'table this installer did not create.',
                $name,
            ));
        }

        $table = new Table($name);
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        // jsonb rather than json: it is the one Postgres can index and query.
        $table->addColumn('data', Types::JSON, ['platformOptions' => ['jsonb' => true]]);
        $table->setPrimaryKey(['id']);
        // Every read filters on it, so it is not an optimisation to add later.
        $table->addIndex(['deleted_at'], sprintf('idx_%s_deleted_at', $name));

        if ($parentTable === null) {
            // No foreign key: core does not know what a user is, and inventing a
            // dependency on the application's tables to get one would be worse.
            $table->addColumn('owner_id', Types::INTEGER, ['notnull' => false]);
        } else {
            $table->addColumn(CollectionDefinition::PARENT_COLUMN, Types::INTEGER);
            // A real foreign key, per §5. The cascade is the backstop for a hard
            // delete; the ordinary path is a soft delete, which the repository
            // cascades itself so that children disappear with their parent.
            $table->addForeignKeyConstraint(
                $parentTable,
                [CollectionDefinition::PARENT_COLUMN],
                ['id'],
                ['onDelete' => 'CASCADE'],
                sprintf('fk_%s_parent', $name),
            );
            // Every read of a collection is "the rows belonging to this record".
            $table->addIndex([CollectionDefinition::PARENT_COLUMN], sprintf('idx_%s_parent', $name));
        }

        $schemaManager->createTable($table);
    }
}

<?php

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
    public function install(ModuleBlueprint $blueprint): ModuleDefinition
    {
        $existing = $this->metadata->find($blueprint->key);

        if ($existing !== null) {
            return $existing;
        }

        $this->assertTypesExist($blueprint);

        $this->createRecordTable($blueprint->table, parentTable: null);

        $module = new ModuleDefinition($blueprint->key, $blueprint->label, $blueprint->table);
        $this->defineFields($module, $blueprint->fields);

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
                position: $field->position,
                system: true,
            );
            $definition->setOptions($field->options);
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

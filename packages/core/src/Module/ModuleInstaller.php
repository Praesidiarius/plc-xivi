<?php

declare(strict_types=1);

namespace Xivi\Core\Module;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * Installs a module into one tenant's database: its table, and its definitions.
 *
 * This runs per customer rather than per deploy, which is why it is a service
 * and not a migration. Migrations describe a schema every tenant shares; a
 * module's table exists only where that module is enabled (§4).
 *
 * The system columns come from §5 and are the same for every module — id,
 * timestamps, owner, soft delete — with the custom long tail in a JSONB payload.
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
     * have. Bringing an existing installation up to a newer blueprint is a
     * different operation and needs §7.2 answered first.
     */
    public function install(ModuleBlueprint $blueprint): ModuleDefinition
    {
        $existing = $this->metadata->find($blueprint->key);

        if ($existing !== null) {
            return $existing;
        }

        // Fails loudly here rather than at the first save, when the definitions
        // would already be written and the data unreadable.
        foreach ($blueprint->fields as $field) {
            $this->fieldTypes->get($field->type);
        }

        $this->createTable($blueprint);

        $module = new ModuleDefinition($blueprint->key, $blueprint->label, $blueprint->table);

        foreach ($blueprint->fields as $field) {
            $definition = new FieldDefinition(
                module: $module,
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

        $this->entityManager->persist($module);
        $this->entityManager->flush();

        return $module;
    }

    private function createTable(ModuleBlueprint $blueprint): void
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist([$blueprint->table])) {
            throw new \RuntimeException(sprintf(
                'Table "%s" already exists but module "%s" has no definitions here. Refusing to adopt a '
                . 'table this installer did not create.',
                $blueprint->table,
                $blueprint->key,
            ));
        }

        $table = new Table($blueprint->table);
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        // No foreign key: core does not know what a user is, and inventing a
        // dependency on the application's tables to get one would be worse.
        $table->addColumn('owner_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        // jsonb rather than json: it is the one Postgres can index and query.
        $table->addColumn('data', Types::JSON, ['platformOptions' => ['jsonb' => true]]);
        $table->setPrimaryKey(['id']);
        // Every read filters on it, so it is not an optimisation to add later.
        $table->addIndex(['deleted_at'], sprintf('idx_%s_deleted_at', $blueprint->table));

        $schemaManager->createTable($table);
    }
}

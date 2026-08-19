<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Collection rows carry a position (XIV-21).
 *
 * Data-driven, and it has to be: a collection's table is created per customer by
 * the installer rather than by a migration (docs/architecture/data-model.md §5), so which tables exist
 * here is a question only this database can answer. `shape_definition` knows —
 * every row with `shape_kind = 'collection'` names one.
 *
 * Expand only: the column defaults to zero, so every existing row sorts by its id
 * exactly as it did before, and the order nobody has arranged yet is the order
 * they were typed in.
 */
final class Version20260815260000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add position to every collection table, so rows keep the order the customer put them in';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->collectionTables() as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD position INT DEFAULT 0 NOT NULL', $table));
            // Replaces the parent-only index: every read of a collection is
            // "the rows of this record, in order".
            $this->addSql(sprintf('DROP INDEX IF EXISTS idx_%s_parent', $table));
            $this->addSql(sprintf('CREATE INDEX idx_%s_parent ON %s (parent_id, position)', $table, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->collectionTables() as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP position', $table));
            $this->addSql(sprintf('DROP INDEX IF EXISTS idx_%s_parent', $table));
            $this->addSql(sprintf('CREATE INDEX idx_%s_parent ON %s (parent_id)', $table, $table));
        }
    }

    /** @return list<string> */
    private function collectionTables(): array
    {
        /** @var list<string> $tables */
        $tables = $this->connection->fetchFirstColumn(
            "SELECT table_name FROM shape_definition WHERE shape_kind = 'collection'",
        );

        return $tables;
    }
}

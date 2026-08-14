<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let a field say whether the list shows a column for it (§5.4).
 *
 * Without it, every field a customer adds widens the table until nothing is
 * readable — a strange punishment for using the engine as intended. It is a UI
 * hint and nothing else: the value is still on the record, still in the form,
 * still filterable and still queryable.
 *
 * Existing fields default to shown, because they are shown today and a migration
 * that empties somebody's list is a migration that broke it.
 */
final class Version20260814143012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add field_definition.is_listed, deciding which fields appear as list columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_definition ADD is_listed BOOLEAN DEFAULT TRUE NOT NULL');
        // The default existed to backfill; the mapping always writes the column.
        $this->addSql('ALTER TABLE field_definition ALTER COLUMN is_listed DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_definition DROP COLUMN is_listed');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let a field say it is part of what a record is called (§5.4).
 *
 * Until now the heading on a record page was guessed from the required fields,
 * first two, in position order. That is right for a contact and wrong for an
 * invoice, and it tied the name to field order — so reordering fields in the
 * editor silently renamed every record.
 *
 * Nothing is backfilled, on purpose. A guess written into a customer's
 * definitions looks like a decision they made; leaving the column false means
 * the old heuristic still answers for anyone who has not chosen, and the moment
 * somebody ticks a box their choice takes over. Modules declare it in their
 * blueprint, so new installations are explicit from the start.
 */
final class Version20260814161540 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add field_definition.is_title, naming which fields a record is called by';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_definition ADD is_title BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE field_definition ALTER COLUMN is_title DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_definition DROP COLUMN is_title');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Let a module carry an icon, beside its label.
 *
 * Stored per customer for the same reason the label is: this is their copy of
 * the module, so what it is called and what it looks like are both theirs to
 * change. Nullable, and the entity falls back, so a module that never declared
 * one still renders — including every module installed before this column
 * existed.
 */
final class Version20260814172455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shape_definition.icon';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shape_definition ADD icon VARCHAR(63) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shape_definition DROP COLUMN icon');
    }
}

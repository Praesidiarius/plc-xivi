<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A field can be computed rather than typed (XIV-20).
 *
 * A line's total and a subtotal's figure are values the record carries and
 * nobody enters. The flag is what the form reads to show one without offering to
 * edit it — a derived value somebody can type over is a default with extra steps.
 *
 * Expand only (docs/architecture/deployment.md §4): the column defaults to false, so every field that
 * exists keeps behaving exactly as it did.
 */
final class Version20260815250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add field_definition.is_derived: values the record computes rather than takes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_definition ADD is_derived BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_definition DROP is_derived');
    }
}

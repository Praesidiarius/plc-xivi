<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Variants: one shape whose records are not all the same thing (§5.5).
 *
 * A contact is a person or a company. They share an email, a phone and an
 * address; they do not share a first name. Two modules would have made
 * "select a contact or a company" a polymorphic reference — the design that
 * cannot carry a foreign key, and the one §5.2 already refused once.
 *
 * `shape_definition.variant_field` names the choice field that decides which
 * variant a record is; the variants themselves are that field's options, so
 * there is no second list to keep in step. `field_definition.variants` scopes a
 * field to some of them, and empty — the default, and the common case — means
 * all.
 */
final class Version20260814190233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shape_definition.variant_field and field_definition.variants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shape_definition ADD variant_field VARCHAR(63) DEFAULT NULL');
        // Every existing field belongs to every variant, which is what an empty
        // list means — so nothing changes for a shape that has none.
        $this->addSql("ALTER TABLE field_definition ADD variants JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE field_definition ALTER COLUMN variants DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_definition DROP COLUMN variants');
        $this->addSql('ALTER TABLE shape_definition DROP COLUMN variant_field');
    }
}

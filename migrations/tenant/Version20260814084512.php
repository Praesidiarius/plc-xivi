<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Generalise module_definition into shape_definition, so that a module and a
 * child collection are the same kind of thing (docs/architecture.md §5).
 *
 * A collection — a contact's addresses — is a table of rows described by field
 * definitions, which is exactly what a module is. The only differences are that
 * a collection names a parent and is not browsable on its own. Giving the two a
 * shared table is what lets the record repository, the validator and the form
 * builder stay single-code-path.
 *
 * The rename is a rename: every existing row becomes a module, and no data
 * moves. Two partial unique indexes replace the old global one, because
 * "addresses" must be free for every module to use while module keys stay unique
 * per customer — something Postgres will enforce and the ORM cannot express.
 */
final class Version20260814084512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generalise module_definition into shape_definition, adding child collections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE field_definition DROP CONSTRAINT FK_65B84660AFC2B591');

        $this->addSql('ALTER TABLE module_definition RENAME TO shape_definition');
        $this->addSql('ALTER TABLE shape_definition RENAME COLUMN module_key TO shape_key');

        // Existing rows are all modules; the default only serves this backfill,
        // since the mapping always writes the discriminator explicitly.
        $this->addSql("ALTER TABLE shape_definition ADD shape_kind VARCHAR(31) DEFAULT 'module' NOT NULL");
        $this->addSql('ALTER TABLE shape_definition ALTER COLUMN shape_kind DROP DEFAULT');

        // Nullable because single-table inheritance puts both kinds in one table:
        // a module row has no parent and no position among siblings.
        $this->addSql('ALTER TABLE shape_definition ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE shape_definition ADD position INT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE shape_definition ADD CONSTRAINT fk_shape_definition_parent '
            . 'FOREIGN KEY (parent_id) REFERENCES shape_definition (id) ON DELETE CASCADE NOT DEFERRABLE',
        );
        $this->addSql('CREATE INDEX idx_shape_definition_parent ON shape_definition (parent_id)');

        $this->addSql('DROP INDEX uniq_module_definition_key');
        // Module keys are unique per customer; collection keys only have to be
        // unique among the collections of one module.
        $this->addSql('CREATE UNIQUE INDEX uniq_shape_definition_module_key ON shape_definition (shape_key) WHERE parent_id IS NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_shape_definition_collection_key ON shape_definition (parent_id, shape_key)');
        // Two shapes sharing a table would each think they owned its rows.
        $this->addSql('CREATE UNIQUE INDEX uniq_shape_definition_table ON shape_definition (table_name)');

        $this->addSql('ALTER TABLE field_definition RENAME COLUMN module_id TO shape_id');
        $this->addSql('DROP INDEX IDX_65B84660AFC2B591');
        $this->addSql('DROP INDEX uniq_field_definition_module_key');
        $this->addSql('CREATE INDEX idx_field_definition_shape ON field_definition (shape_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_field_definition_shape_key ON field_definition (shape_id, field_key)');
        $this->addSql(
            'ALTER TABLE field_definition ADD CONSTRAINT fk_field_definition_shape '
            . 'FOREIGN KEY (shape_id) REFERENCES shape_definition (id) ON DELETE CASCADE NOT DEFERRABLE',
        );
    }

    /**
     * Only reversible while nothing has used the new capability: collections
     * would violate the restored global unique index, and their tables would be
     * left behind pointing at definitions that no longer exist. Refusing beats
     * dropping a customer's addresses to satisfy a downgrade.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(
            "DO $$ BEGIN IF EXISTS (SELECT 1 FROM shape_definition WHERE shape_kind <> 'module') "
            . "THEN RAISE EXCEPTION 'Cannot reverse: this database has child collections.'; END IF; END $$",
        );

        $this->addSql('ALTER TABLE field_definition DROP CONSTRAINT fk_field_definition_shape');
        $this->addSql('DROP INDEX uniq_field_definition_shape_key');
        $this->addSql('DROP INDEX idx_field_definition_shape');
        $this->addSql('ALTER TABLE field_definition RENAME COLUMN shape_id TO module_id');
        $this->addSql('CREATE INDEX IDX_65B84660AFC2B591 ON field_definition (module_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_field_definition_module_key ON field_definition (module_id, field_key)');

        $this->addSql('DROP INDEX uniq_shape_definition_table');
        $this->addSql('DROP INDEX uniq_shape_definition_collection_key');
        $this->addSql('DROP INDEX uniq_shape_definition_module_key');
        $this->addSql('DROP INDEX idx_shape_definition_parent');
        $this->addSql('ALTER TABLE shape_definition DROP CONSTRAINT fk_shape_definition_parent');
        $this->addSql('ALTER TABLE shape_definition DROP COLUMN position');
        $this->addSql('ALTER TABLE shape_definition DROP COLUMN parent_id');
        $this->addSql('ALTER TABLE shape_definition DROP COLUMN shape_kind');
        $this->addSql('ALTER TABLE shape_definition RENAME COLUMN shape_key TO module_key');
        $this->addSql('ALTER TABLE shape_definition RENAME TO module_definition');
        $this->addSql('CREATE UNIQUE INDEX uniq_module_definition_key ON module_definition (module_key)');

        $this->addSql(
            'ALTER TABLE field_definition ADD CONSTRAINT FK_65B84660AFC2B591 '
            . 'FOREIGN KEY (module_id) REFERENCES module_definition (id) ON DELETE CASCADE NOT DEFERRABLE',
        );
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The .docx templates a customer's documents are made from (XIV-4).
 *
 * The file lives in this column, in the customer's own database. That is the
 * whole of the file-storage decision for now (docs/architecture.md §5.7): templates are
 * small and few, and keeping them here means the isolation §4 already provides
 * costs nothing extra — no shared volume, no path to get wrong, and backup and
 * export-on-churn keep working per customer with nothing added.
 *
 * `module_key` is a string rather than a foreign key, like a permission grant: a
 * template for a module the customer uninstalls goes inert instead of being
 * deleted, and reinstalling brings the stationery back.
 */
final class Version20260815240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add document_template: the .docx templates records are generated from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE document_template (
                id SERIAL NOT NULL,
                module_key VARCHAR(63) NOT NULL,
                variant VARCHAR(63) DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                content BYTEA NOT NULL,
                uploaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                uploaded_by VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // The only question this table is asked: what has this module got.
        $this->addSql('CREATE INDEX idx_document_template_module ON document_template (module_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE document_template');
    }
}

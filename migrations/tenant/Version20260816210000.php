<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a customer's emails say (XIV-38).
 *
 * The counterpart to document_template one table over, and the difference in the
 * columns is the whole design difference: there is no `content BYTEA` here,
 * because there is no file. An email template is written in this application —
 * a name, a subject and a Markdown body — rather than authored in Word and
 * uploaded, since an email has no layout worth designing and re-uploading a
 * .docx to fix a typo would be ceremony bought with nothing
 * (docs/architecture.md §5.13).
 *
 * `body` is `TEXT` rather than a length: nobody can say in advance how long a
 * dunning letter runs, and Postgres stores a short text exactly as it stores a
 * short varchar, so the generosity costs nothing. `subject` keeps a length
 * because a subject line no mail client will show the end of is not a subject.
 *
 * `module_key` is a string rather than a foreign key, like a permission grant
 * and like the document templates: wording for a module the customer uninstalls
 * goes inert instead of being deleted, and reinstalling brings it back.
 *
 * Additive, like every tenant migration has to be (§4): a new table destroys
 * nothing, so this lands for every customer without an expand/contract dance.
 */
final class Version20260816210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email_template: the subject and Markdown body emails are written from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE email_template (
                id SERIAL NOT NULL,
                module_key VARCHAR(63) NOT NULL,
                variant VARCHAR(63) DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_by VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // The only question this table is asked: what has this module got.
        $this->addSql('CREATE INDEX idx_email_template_module ON email_template (module_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_template');
    }
}

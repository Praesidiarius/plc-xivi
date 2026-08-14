<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remember which records a demo generator made, so they can be removed again.
 *
 * A generator with no undo is a one-way door on a database: the only way back is
 * dropping the tenant, which also takes everything real that was in it. This
 * table is what makes "remove the demo data" precise rather than a guess about
 * which rows look synthetic.
 *
 * Deliberately not a flag on the record tables themselves. That would be a
 * system column on every module a customer installs — a permanent change to the
 * storage shape (§5) for the benefit of a development tool. This is one table,
 * empty everywhere it is not being used.
 *
 * No foreign keys: it points at rows in tables that only exist where the module
 * is installed, and it has to survive the record it names being deleted by
 * something else.
 */
final class Version20260814230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add demo_record, the ledger of generated demo data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE demo_record (
                id BIGSERIAL NOT NULL,
                shape_key VARCHAR(63) NOT NULL,
                record_id INT NOT NULL,
                generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // Cleanup reads every row for one shape and deletes them together, which
        // is the only way this table is ever read.
        $this->addSql('CREATE INDEX idx_demo_record_shape ON demo_record (shape_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE demo_record');
    }
}

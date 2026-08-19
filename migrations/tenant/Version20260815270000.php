<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where document numbers come from (XIV-15).
 *
 * One row per counter — per shape, per field, per period — holding the next
 * value it will give out. A counter table rather than a Postgres sequence,
 * because a sequence cannot be made to restart each year without an `ALTER` that
 * two January transactions would race each other through, and because `nextval`
 * survives a rollback: a save that failed would leave a hole in the invoice
 * numbers that somebody has to explain to an auditor.
 *
 * The unique index is the whole safety mechanism. Allocation is one statement —
 * `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` — and the index is what turns
 * the second of two simultaneous inserts into an update of the first one's row,
 * under a lock the database takes and PHP never sees (docs/architecture/data-model.md §5.10).
 *
 * Per tenant, because the sequence belongs to the customer's bookkeeping rather
 * than to the platform (§4).
 */
final class Version20260815270000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add number_sequence: the counters document numbers are drawn from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE number_sequence (
                id SERIAL NOT NULL,
                shape_key VARCHAR(63) NOT NULL,
                field_key VARCHAR(63) NOT NULL,
                period VARCHAR(15) NOT NULL,
                next_value BIGINT NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // Not merely an index: the ON CONFLICT target, and therefore the thing
        // that makes two simultaneous allocations take turns instead of both
        // reading the same value. Empty period for a sequence that never resets.
        $this->addSql('CREATE UNIQUE INDEX uniq_number_sequence ON number_sequence (shape_key, field_key, period)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE number_sequence');
    }
}

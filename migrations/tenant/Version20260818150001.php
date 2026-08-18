<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Xivi\Core\Numbering\NumberFormat;
use Xivi\Core\Record\UniqueIndex;

/**
 * Index every unique field, so uniqueness is enforced by the database (XIV-109).
 *
 * ## What this catches up
 *
 * A field marked `unique` used to be enforced by a validator that queried the
 * table and then let the save proceed — a read followed by a write with nothing
 * holding the gap, so two saves arriving together both found nothing and both
 * inserted. From this release the flag builds a unique expression index over
 * `data ->> 'key'` ({@see UniqueIndex}), created and dropped as the flag moves.
 *
 * That covers every flag set from now on and none of the ones already set. This
 * migration is the other half: it reads each customer's **own definitions** —
 * which are the truth about their installation, not the module blueprints (§6.1)
 * — and builds the index each of them has been implying all along. Nobody edits
 * a field by hand, and a tenant that has renamed a module or added six unique
 * fields of their own gets exactly their six.
 *
 * ## Numbered fields are indexed too, and that is a decision
 *
 * The `WHERE` below also picks up any field carrying a numbering pattern,
 * whether or not somebody ticked `unique`, and marks it unique on the way past.
 *
 * §5.10's first paragraph says two documents carrying the same number is one of
 * the two fatal failures of that feature. That promise has always been kept by
 * arithmetic — a counter that moves in one statement and only forward — and
 * arithmetic is not a constraint: it is complete about the numbers the counter
 * gave out and blind to everything else that can reach the column. So a numbered
 * field *is* a unique field, the definition should say so, and from XIV-109 the
 * engine writes both flags together when numbering is turned on
 * ({@see \Xivi\Core\Metadata\NumberingChange::start()}). This brings the fields
 * that were numbered before that rule existed into line with it.
 *
 * It is the one part of this migration that changes a definition rather than
 * only adding to the schema, and it is worth being plain about what that means
 * for §4.2's window: code still running reads `is_unique` and runs a validator
 * off the back of it, which is a query it was already making for other fields.
 * Nothing old breaks on finding the flag set; the worst it does is refuse a
 * duplicate slightly earlier than it used to.
 *
 * ## Why not `CREATE INDEX CONCURRENTLY`
 *
 * §4.2 decided that the instance stays up while `bin/deploy` walks the tenants,
 * which makes "an index build takes a lock" a real question rather than a
 * theoretical one. `CONCURRENTLY` is the usual answer and it is refused here for
 * two reasons that compound.
 *
 * It **cannot run inside a transaction**, so this migration would have to
 * declare itself non-transactional and give up being all-or-nothing — a run
 * interrupted half way would leave a tenant with some of its indexes and no
 * record of which. And it **fails soft**: a concurrent build that meets a
 * duplicate leaves an `INVALID` index behind, which exists, enforces nothing, is
 * not used by the planner, and is discoverable only by going looking. A silent
 * hole in a uniqueness guarantee is exactly what this migration exists to close,
 * so buying availability with one would be paying in the currency being
 * defended.
 *
 * What the plain build costs is a `SHARE` lock per record table for the length
 * of that table's build: reads carry on, writes to that one table wait. These
 * are a single customer's own record tables, at CRM sizes, in a database being
 * migrated as part of a release — measured in milliseconds, and paid once.
 *
 * ## A tenant that already holds a duplicate stops, loudly
 *
 * The one way this can fail is a column that already holds the same value twice,
 * which can only have got there through the race this release closes. The
 * `CREATE UNIQUE INDEX` refuses, the transaction rolls back, and that tenant is
 * left exactly as it was while `tenant:migrate` carries on to the others and
 * reports code 3 with the slug (§4.2).
 *
 * That is the right failure. The alternative — skip the field, log a line, carry
 * on — leaves the customer most likely to be bitten by duplicates as the one
 * customer with nothing enforcing them, and the operator with a warning in a log
 * nobody reads. Postgres names the index and the duplicated key in its error, so
 * the message says which field and which value; fix those records and re-run
 * with `--slug`.
 *
 * ## It imports two engine classes, on purpose
 *
 * A migration is usually frozen SQL that depends on nothing, and this one
 * reaches for {@see UniqueIndex::nameFor()} and {@see NumberFormat::OPTION}. The
 * rule it is breaking is worth less here than the one it is keeping: an index
 * this creates under a name the engine does not expect is an index the engine
 * will never drop, never rebuild and never recognise in a constraint violation,
 * and copying the naming rule into this file is precisely how the two would come
 * to disagree. Both are constants — one a pure function of two strings, one a
 * literal — so what is imported is a *spelling*, not behaviour that can change
 * underneath a migration that has already run somewhere.
 *
 * `down()` drops what this created and leaves the `is_unique` flags alone. A
 * rollback that also unticked them would be deciding, on somebody's behalf, that
 * their numbered fields are not unique after all — and the flag on its own is
 * harmless, since the validator that reads it predates all of this.
 */
final class Version20260818150001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index every unique field, so uniqueness is enforced by the database';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->uniqueFields() as $field) {
            $table = (string) $field['table_name'];
            $key = (string) $field['field_key'];

            // A definition may name a table this installation has not got: a
            // module removed by hand, or a database restored in pieces. Skipped
            // rather than fatal, because a missing record table is a fact about
            // that tenant that this migration is in no position to fix, and
            // stopping over it would hold back the indexes of every other module
            // they do have.
            if (!$this->connection->createSchemaManager()->tablesExist([$table])) {
                continue;
            }

            $value = sprintf('data ->> %s', $this->connection->quote($key));

            $this->addSql(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s ((%s)) WHERE deleted_at IS NULL AND (%s) IS NOT NULL',
                UniqueIndex::nameFor($table, $key),
                $this->connection->getDatabasePlatform()->quoteSingleIdentifier($table),
                $value,
                $value,
            ));
        }

        // The flag on the numbered fields, so that the definitions say what the
        // engine now enforces. After the indexes rather than before, so that a
        // tenant whose column holds a duplicate fails without having claimed
        // anything about it first.
        $this->addSql(sprintf(
            "UPDATE field_definition SET is_unique = TRUE
             WHERE is_unique = FALSE
               AND options->>'%s' IS NOT NULL
               AND shape_id IN (SELECT id FROM shape_definition WHERE shape_kind = 'module')",
            NumberFormat::OPTION,
        ));
    }

    public function down(Schema $schema): void
    {
        foreach ($this->uniqueFields() as $field) {
            $this->addSql(sprintf(
                'DROP INDEX IF EXISTS %s',
                UniqueIndex::nameFor((string) $field['table_name'], (string) $field['field_key']),
            ));
        }
    }

    /**
     * Every field whose definition implies an index, with the table it lives in.
     *
     * **Read here rather than assumed**, because what a customer has is not what
     * a blueprint says (§6.1): a field this engine ships as unique may have been
     * relaxed, and a field nobody shipped at all may have been made unique in
     * the metadata editor three months ago.
     *
     * Modules only. `unique` on a collection's field is refused by the installer
     * — unique across the whole table and unique within one parent are different
     * rules and the engine will not guess (§7) — so a row saying otherwise would
     * be data this schema was never able to produce.
     *
     * @return list<array<string, mixed>>
     */
    private function uniqueFields(): array
    {
        return $this->connection->fetchAllAssociative(sprintf(
            "SELECT s.table_name, f.field_key
             FROM field_definition f
             JOIN shape_definition s ON s.id = f.shape_id
             WHERE s.shape_kind = 'module' AND (f.is_unique = TRUE OR f.options->>'%s' IS NOT NULL)
             ORDER BY s.table_name, f.field_key",
            NumberFormat::OPTION,
        ));
    }
}

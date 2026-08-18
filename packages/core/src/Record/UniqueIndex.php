<?php

/*
 * This file is part of the Xivi package.
 *
 * (c) Praesidiarius <praesidiarius@proton.me>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Xivi\Core\Record;

use Doctrine\DBAL\Connection;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;

/**
 * The index that makes `unique` true rather than merely checked (XIV-109).
 *
 * ### What was wrong
 *
 * A field marked unique was enforced by a validator that ran
 * `SELECT 1 … WHERE data->>'email' = …` and, finding nothing, let the save
 * proceed. That is a read followed by a write with nothing holding the gap
 * open. Under Postgres' READ COMMITTED — the default, and what this application
 * runs on — two saves arriving in the same moment both read, neither sees the
 * other's uncommitted row, and both insert. It needs no unusual conditions: two
 * people pressing Save is enough, and every field any customer has ever marked
 * unique was open to it.
 *
 * The whole class of read-then-write races has exactly one fix, and this
 * codebase has already applied it twice — {@see \Xivi\Core\Numbering\NumberAllocator}
 * allocates in one statement rather than reading and incrementing, and refuses a
 * wind-back inside the same statement that performs it. Uniqueness is the third
 * instance and its version of "one statement" is an index: the database checks
 * at the moment of the write, holding the row it is writing, and there is no
 * window for a second writer to be in.
 *
 * ### Why it was a validator in the first place
 *
 * Because records are not Doctrine entities (§5). A field is a key inside a
 * JSONB payload whose shape is decided per tenant at runtime, so there is no
 * column to put a `UNIQUE` on — the index is an **expression** index over
 * `data ->> 'key'`, created because a customer ticked a box, in that customer's
 * database only, and dropped when they untick it. That is real work and it is
 * the reason the shortcut was taken; it is not a reason the shortcut was right.
 *
 * This class is therefore the third place in the engine that knows *where* a
 * custom field physically lives, after {@see RecordRepository} and
 * {@see \Xivi\Core\Query\QueryCompiler}. That is worth saying out loud rather
 * than hiding: when column promotion arrives (§5) all three move together, and
 * this one gets simpler than the other two, because a promoted column takes an
 * ordinary `UNIQUE` and the expression disappears.
 *
 * ### The index is partial, twice over
 *
 * `WHERE deleted_at IS NULL AND (data ->> 'key') IS NOT NULL`.
 *
 * **Empty is not a duplicate of empty.** Twenty contacts with no email are not
 * twenty contacts colliding, and a plain `UNIQUE` would say they were — it would
 * make "unique" mean "unique and mandatory", which is a second rule the customer
 * did not ask for and cannot see. The validator has always taken this view
 * ({@see \Xivi\Core\Validation\UniqueFieldValueValidator} returns early on
 * null), so the index taking the other one would be the database and the form
 * disagreeing about the same field. Note that an empty string cannot reach the
 * column at all: field types normalise `''` to null on the way in and
 * {@see RecordRepository} drops nulls out of the payload, so "absent" is the
 * single representation of empty and this predicate catches all of it.
 *
 * **A deleted record is not in the way.** Records are soft-deleted (§5) and keep
 * their values, so a customer who deletes a contact and types the same email
 * again must be allowed to — which is what the validator already did, with its
 * `AND deleted_at IS NULL`. Indexing deleted rows would make deletion a thing
 * that silently reserves a value for ever, discoverable only by hitting it.
 *
 * The two predicates together are exactly the validator's WHERE clause, which is
 * the property that matters: the readable message and the enforced truth are
 * about the same set of rows, so the index can never refuse something the form
 * would have accepted while looking correct.
 *
 * ### Built in the transaction that changes the definition, not concurrently
 *
 * `CREATE INDEX CONCURRENTLY` is the usual advice for a live table and it is
 * refused here, for two reasons that compound.
 *
 * It **cannot run inside a transaction**, so the definition row and the index
 * could not be written as one act. Every path that turns this flag on — the
 * metadata editor, the module installer, {@see \Xivi\Core\Metadata\NumberingChange}
 * — has something else to write beside the index, and a definition saying
 * "unique" beside a table with no index is precisely the state this ticket
 * exists to end.
 *
 * And it **fails soft**. A concurrent build that meets a duplicate leaves an
 * `INVALID` index behind: an index that exists, enforces nothing, is not used by
 * the planner, and has to be found and dropped by hand. A silent hole in a
 * uniqueness guarantee is the exact failure this class was written to close, so
 * buying availability with it would be paying in the currency being defended.
 *
 * What the plain build costs is a `SHARE` lock on one shape's table for the
 * length of the build — reads carry on, writes to that one table wait. At the
 * scale a customer's own module runs at that is milliseconds, and it is taken
 * during a deliberate administrative act by somebody who is watching. §4.2's
 * migration window has the same argument in its own words for the deploy-time
 * half of this.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class UniqueIndex
{
    /**
     * Postgres truncates an identifier past this and would then quietly point
     * two indexes at one name.
     */
    private const int MAX_IDENTIFIER = 63;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * What this field's index is called, derived rather than stored.
     *
     * **Derived, so that nothing has to remember it.** The alternative is a
     * column on `field_definition` holding the name, and it would be one more
     * thing that can disagree with the database — a row saying an index exists
     * is not an index. A pure function of the table and the key can be computed
     * by anything that needs it: the creator, the dropper, the migration that
     * catches existing tenants up, and {@see RecordWriter} turning a constraint
     * violation back into the name of a field.
     *
     * `uniq_<table>_<field>` while it fits, because a name somebody meets in
     * `\d contact` or in a Postgres error should say what it is about. Past 63
     * characters it is truncated and given eight hex of a digest of the full
     * logical name — still deterministic, still unique in practice, and still
     * carrying enough of the table name to be recognised. The truncation is not
     * hypothetical: a table name may run to 63 characters on its own and a field
     * key to 63 more.
     */
    public static function nameFor(string $table, string $fieldKey): string
    {
        $name = sprintf('uniq_%s_%s', $table, $fieldKey);

        if (\strlen($name) <= self::MAX_IDENTIFIER) {
            return $name;
        }

        // The digest is of the logical pair rather than of the truncated string,
        // so two fields whose names differ only past the cut still differ here.
        // The separator is a NUL because it cannot occur in either half, which
        // stops `ab` + `c` and `a` + `bc` hashing the same.
        return substr($name, 0, self::MAX_IDENTIFIER - 9)
            . '_' . substr(hash('sha256', $table . "\0" . $fieldKey), 0, 8);
    }

    /**
     * Make the database agree with the definition: an index while the field is
     * unique, none once it is not.
     *
     * One method rather than two call sites choosing between them, because
     * "which way did this change go" is a question every caller would answer the
     * same way and one of them would eventually answer wrongly. The field is
     * asked what it is now, and the database is brought to that.
     *
     * **Collections are not indexed and are not an oversight.** The installer
     * refuses `unique` on a collection's field outright, because unique across
     * the whole table and unique within one parent record are different rules
     * and the engine will not guess (§7). Until that question has an answer
     * there is nothing here to enforce, and quietly creating a whole-table index
     * would be picking one of the two answers by accident.
     */
    public function follow(ShapeDefinition $shape, FieldDefinition $field): void
    {
        if (!$shape instanceof ModuleDefinition) {
            return;
        }

        if ($field->isUnique()) {
            $this->create($shape, $field);

            return;
        }

        $this->drop($shape, $field);
    }

    /**
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException when the
     *                                                                     column already holds a value twice — a build
     *                                                                     that meets a duplicate refuses rather than
     *                                                                     leaving an index that enforces nothing
     */
    public function create(ModuleDefinition $module, FieldDefinition $field): void
    {
        $value = $this->storedValue($field);

        // `IF NOT EXISTS` so that every path into here is idempotent: a module
        // reinstalled, a migration re-run against a tenant that already has it,
        // a customer ticking a box that was already ticked. None of those are
        // errors and all of them are reachable.
        $this->connection->executeStatement(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s ((%s)) WHERE deleted_at IS NULL AND (%s) IS NOT NULL',
            self::nameFor($module->getTableName(), $field->getKey()),
            $this->table($module),
            $value,
            $value,
        ));
    }

    /**
     * Any shape, unlike {@see self::create()}, and the asymmetry is deliberate.
     *
     * Creating an index is a decision and takes a lock, so it is worth being
     * strict about what may be handed one. Dropping is the *safe* direction —
     * the caller that reaches for it is usually saying "whatever was there, take
     * it away", which is what {@see \Xivi\Core\Metadata\MetadataEditor::removeField()}
     * means — and a collection simply has none, so widening the parameter here
     * saves every caller a check that could only ever have one answer.
     */
    public function drop(ShapeDefinition $shape, FieldDefinition $field): void
    {
        if (!$shape instanceof ModuleDefinition) {
            return;
        }

        // `IF EXISTS` for the fields that were never unique, which is most of
        // them: every save of the field editor comes through here and only the
        // ones that changed have anything to do.
        $this->connection->executeStatement(sprintf(
            'DROP INDEX IF EXISTS %s',
            self::nameFor($shape->getTableName(), $field->getKey()),
        ));
    }

    /**
     * The stored value, as text — the same expression the query layer sorts and
     * filters by, written out here for the same reason it is written out there:
     * a key is a definition row rather than a bound parameter, and an index
     * expression cannot take a parameter at all.
     *
     * Quoted as a SQL literal by the platform rather than concatenated raw. The
     * key comes from a definition row that only {@see \Xivi\Core\Metadata\MetadataEditor}
     * writes, and which it holds to `[a-z][a-z0-9_]*`, so there is nothing here
     * to escape — quoting it anyway costs nothing and means the safety of this
     * string does not depend on a rule enforced in another file.
     */
    private function storedValue(FieldDefinition $field): string
    {
        return sprintf('data ->> %s', $this->connection->quote($field->getKey()));
    }

    private function table(ModuleDefinition $module): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($module->getTableName());
    }
}

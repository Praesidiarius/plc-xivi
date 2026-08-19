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
use Xivi\Core\Field\ExcludesOverlaps;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Period\ExclusiveWithin;

/**
 * The constraint that makes "these two cannot overlap" true rather than checked
 * (XIV-136).
 *
 * ### This is XIV-109 one level harder, and its conclusion transfers exactly
 *
 * That ticket found a validator that ran `SELECT 1 … WHERE data->>'email' = …`
 * and, finding nothing, let the save proceed — a read followed by a write with
 * nothing holding the gap open. Under READ COMMITTED two saves arriving in the
 * same moment both read, neither sees the other's uncommitted row, and both
 * insert. The fix was to stop asking and start *constraining*: a unique
 * expression index, so that the database checks at the moment of the write while
 * holding the row it is writing, and there is no window for a second writer to be
 * in.
 *
 * Two bookings for one room on one night is the same defect with a harder
 * predicate. "Is this room free next week" is a read; the booking that follows it
 * is a write; and between them is the millisecond in which the other guest books.
 * No amount of care in PHP closes it — the second reader cannot see the first
 * writer's uncommitted row, by definition — so the answer is the same and only
 * the tool changes: **`EXCLUDE USING gist`**, which is a unique index whose
 * equality has been replaced by "any operator you like". Here that is `=` on the
 * scope and `&&` on the range: *no two live rows may name the same room and
 * ranges that share a moment.*
 *
 * ### What it is exclusive within is a field, and there is no global version
 *
 * The scope comes from the field's own options ({@see ExclusiveWithin}) because
 * "no overlaps" is never a statement about a module — it is a statement about a
 * resource, and which field names the resource is exactly what the engine cannot
 * guess. That option is also the on switch: a period field with no scope has no
 * constraint, which is what a project's duration or an employment's dates want.
 *
 * ### The predicate is partial, three times over
 *
 * `WHERE deleted_at IS NULL AND <scope> IS NOT NULL AND <period> IS NOT NULL`.
 *
 * **A deleted record is not in the way.** Records are soft-deleted and keep their
 * values (§5), so cancelling a booking and rebooking the room must be allowed —
 * the same predicate {@see UniqueIndex} carries, for the same reason, and with
 * the same consequence if it were left out: a cancellation would silently reserve
 * a room for ever.
 *
 * **A record with no scope is nobody's conflict.** A booking with no room chosen
 * yet is a draft, and drafts do not occupy anything. Note that Postgres would
 * take this view anyway — `NULL = NULL` is unknown, so an exclusion constraint
 * never fires on a null scope — but the predicate is written out, because an
 * index that skips those rows is smaller and because the rule should be readable
 * rather than inferred from three-valued logic.
 *
 * **A record with no period is not a period.** An empty field would otherwise be
 * indexed as the range `xivi_date_range(NULL)`, which is NULL and again never
 * conflicts; same argument, same predicate.
 *
 * ### One expression, shared with the query that filters by it
 *
 * The range comes from {@see \Xivi\Core\Field\FieldType::comparableSql()} — the
 * same method the query compiler asks for "which of these overlap today". That is
 * not tidiness: an index is only usable by a query whose expression matches it
 * *textually*, so building the constraint from anything else would leave the
 * filter unable to use the index the constraint had just built, and would let the
 * two drift apart about what a period is.
 *
 * ### Built in the transaction that changes the definition, not concurrently
 *
 * The whole of {@see UniqueIndex}'s argument applies unchanged. `CONCURRENTLY`
 * cannot run inside a transaction, so the definition row and the constraint could
 * not be written as one act — and `ALTER TABLE … ADD CONSTRAINT` has no
 * concurrent form at all, so the choice does not even arise here. What it costs is
 * an `ACCESS EXCLUSIVE` lock on one customer's record table for the length of one
 * build.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class OverlapExclusion
{
    /** Postgres truncates identifiers at 63 characters; see {@see UniqueIndex::MAX_IDENTIFIER}. */
    private const int MAX_IDENTIFIER = 63;

    public function __construct(
        private Connection $connection,
        // Which types are ranges at all, and what their SQL looks like. The one
        // dependency UniqueIndex does not have, and the reason is the difference
        // between the two features: uniqueness is a flag any type may carry, and
        // a period is a kind of value only some types hold.
        private FieldTypeRegistry $fieldTypes,
    ) {
    }

    /**
     * A stable name for the constraint on one field of one table.
     *
     * A pure function of the two, exactly as {@see UniqueIndex::nameFor()} is and
     * for the same reason: {@see RecordWriter} reads the name back out of a
     * Postgres error to work out which field refused a save, and a name that were
     * stored anywhere would be a second copy of the truth that could disagree
     * with the schema.
     *
     * The scope field is deliberately **not** in the name. Changing what a period
     * is exclusive within has to drop the old constraint and build a new one, and
     * a name that moved with the scope would leave the old one standing under a
     * name nothing knows to look for.
     */
    public static function nameFor(string $table, string $fieldKey): string
    {
        $name = sprintf('excl_%s_%s', $table, $fieldKey);

        if (\strlen($name) <= self::MAX_IDENTIFIER) {
            return $name;
        }

        return substr($name, 0, self::MAX_IDENTIFIER - 9)
            . '_' . substr(hash('sha256', $table . "\0" . $fieldKey), 0, 8);
    }

    /**
     * Make the database agree with the definition: a constraint while the field
     * says what it is exclusive within, none once it does not.
     *
     * One method rather than two call sites choosing between them — the same
     * shape {@see UniqueIndex::follow()} has, and the same argument: "which way
     * did this change go" is a question every caller would answer the same way
     * and one of them would eventually answer wrongly.
     *
     * **Dropped and rebuilt rather than altered**, every time, because a change to
     * the scope changes the constraint's *definition* and Postgres has no way to
     * amend one in place. It costs a drop that usually finds nothing.
     *
     * **Collections are not constrained**, on exactly the terms `unique` is
     * refused on one: within one parent and across the whole table are different
     * rules, and the engine will not guess (§7).
     *
     * @throws \Doctrine\DBAL\Exception when the table already holds two rows this would refuse —
     *                                  a build that meets a conflict refuses rather than leaving
     *                                  a constraint that enforces nothing
     */
    public function follow(ShapeDefinition $shape, FieldDefinition $field): void
    {
        if (!$shape instanceof ModuleDefinition) {
            return;
        }

        $this->drop($shape, $field);

        $key = $this->scopeKey($shape, $field);

        if ($key === null) {
            return;
        }

        $scope = $this->scope($key, '');
        $range = $this->fieldTypes->get($field->getType())->comparableSql($this->storedValue($field));

        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s EXCLUDE USING gist (%s WITH =, %s WITH &&) '
            . 'WHERE (deleted_at IS NULL AND %s IS NOT NULL AND %s IS NOT NULL)',
            $this->table($shape),
            self::nameFor($shape->getTableName(), $field->getKey()),
            $scope,
            $range,
            $scope,
            $range,
        ));
    }

    /**
     * Any shape, unlike {@see self::follow()}'s build half, and the asymmetry is
     * {@see UniqueIndex::drop()}'s: dropping is the safe direction, and a caller
     * saying "whatever was there, take it away" — which is what removing a field
     * means — should not have to check what kind of shape it holds.
     */
    public function drop(ShapeDefinition $shape, FieldDefinition $field): void
    {
        if (!$shape instanceof ModuleDefinition) {
            return;
        }

        // `IF EXISTS` for the fields that never had one, which is most of them:
        // every save of the field editor comes through here.
        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s',
            $this->table($shape),
            self::nameFor($shape->getTableName(), $field->getKey()),
        ));
    }

    /**
     * The pairs of live records this constraint would refuse, if it were built.
     *
     * **Asked before building, so that a customer ticking this on gets a sentence
     * instead of a driver exception** — the same courtesy {@see RecordRepository}
     * does for duplicates when a field is made unique (XIV-109, §5.4). The
     * constraint is what is *true*; this is what is *readable*, and the two are
     * asking the same question of the same rows because both are built from
     * `comparableSql()`.
     *
     * A self-join rather than a window: the question is genuinely about pairs, and
     * `a.id < b.id` names each pair once so a conflict between two records is one
     * line rather than two.
     *
     * **The scope is passed in rather than read off the field**, because the
     * caller is asking a question about a scope the field has not got *yet*: the
     * editor checks before it writes, so what it holds is the answer somebody has
     * typed and the definition still says whatever it said this morning.
     *
     * @param string $scopeKey the field the period would be exclusive within
     *
     * @return list<array{scope: string, first: int, second: int}>
     */
    public function conflicts(ModuleDefinition $module, FieldDefinition $field, string $scopeKey, int $limit): array
    {
        if (!$this->fieldTypes->get($field->getType()) instanceof ExcludesOverlaps
            || $module->getField($scopeKey) === null) {
            return [];
        }

        $type = $this->fieldTypes->get($field->getType());

        /** @var list<array{scope: string, first: int, second: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT %s AS scope, a.id AS first, b.id AS second
             FROM %s a JOIN %s b ON %s = %s AND %s && %s AND a.id < b.id
             WHERE a.deleted_at IS NULL AND b.deleted_at IS NULL
             ORDER BY a.id, b.id
             LIMIT %d',
            $this->scope($scopeKey, 'a'),
            $this->table($module),
            $this->table($module),
            $this->scope($scopeKey, 'a'),
            $this->scope($scopeKey, 'b'),
            $type->comparableSql($this->storedValue($field, 'a')),
            $type->comparableSql($this->storedValue($field, 'b')),
            $limit,
        ));

        return $rows;
    }

    /**
     * The field this period is exclusive within, or null when it is exclusive
     * within nothing.
     *
     * Three ways to get null and all of them mean "no constraint": the field is
     * not a range at all, nobody has named a scope, or the scope names a field
     * this shape has not got. The last is the interesting one — it happens when a
     * scope field is removed from the definitions, and treating it as an error
     * would make removing a field fail on a page that has nothing to do with
     * periods. What it means is that the rule has lost the thing it was about, and
     * an unenforced rule is the honest state until somebody points it at another
     * field.
     */
    private function scopeKey(ModuleDefinition $module, FieldDefinition $field): ?string
    {
        if (!$this->fieldTypes->get($field->getType()) instanceof ExcludesOverlaps) {
            return null;
        }

        $key = ExclusiveWithin::of($field);

        return $key !== null && $module->getField($key) !== null ? $key : null;
    }

    /**
     * What the scope looks like in SQL.
     *
     * The scope's *stored* text, rather than the scope type's `comparableSql()`.
     * Equality of stored values is the rule that is wanted — two records are in
     * the same scope when they hold the same value — and every type stores
     * canonically, so there is nothing a cast would fix and one more operator
     * class for `btree_gist` to have to support.
     */
    private function scope(string $key, string $alias): string
    {
        return sprintf('(%s)', self::accessor($this->connection->quote($key), $alias));
    }

    /**
     * The stored value, as text — the same expression the query layer filters by,
     * written out here for the same reason it is written out there: an index
     * expression cannot take a bound parameter.
     *
     * Quoted as a SQL literal by the platform rather than concatenated raw. The key
     * comes from a definition row that only {@see \Xivi\Core\Metadata\MetadataEditor}
     * writes, and which it holds to `[a-z][a-z0-9_]*`.
     *
     * The alias is empty for the constraint — an index expression names no table —
     * and set for {@see self::conflicts()}, which joins the table to itself and
     * has to say which side each half of the comparison is about.
     */
    private function storedValue(FieldDefinition $field, string $alias = ''): string
    {
        return self::accessor($this->connection->quote($field->getKey()), $alias);
    }

    private static function accessor(string $quotedKey, string $alias): string
    {
        return sprintf('%sdata ->> %s', $alias === '' ? '' : $alias . '.', $quotedKey);
    }

    private function table(ModuleDefinition $module): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($module->getTableName());
    }
}

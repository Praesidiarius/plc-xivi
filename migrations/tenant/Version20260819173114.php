<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Xivi\Core\Period\PeriodPrecision;
use Xivi\Core\Period\PeriodSql;

/**
 * Install the two functions and the extension a period needs before it can be
 * constrained (XIV-136).
 *
 * ## What this is for
 *
 * A period field may say what it is exclusive within — a room, a machine, a
 * person — and the engine then refuses two overlapping periods for the same
 * thing **in the database**, with `EXCLUDE USING gist`
 * ({@see \Xivi\Core\Record\OverlapExclusion}). That constraint is an index, and
 * an index needs two things this database has not got yet:
 *
 *  * **`btree_gist`**, because a GiST index cannot compare a scope for plain
 *    equality without it — "the same room *and* an overlapping period" is half
 *    equality and half range, and the two have to sit in one index. A *trusted*
 *    extension since Postgres 13, so the tenant's own role may install it and no
 *    operator has to walk the cluster by hand.
 *  * **an `IMMUTABLE` way to read a stored period as a range.** Every expression
 *    in an index must be immutable, and `(data ->> 'stay')::date` is not —
 *    `date_in` is only *stable*, because it reads `DateStyle` and accepts `today`.
 *    {@see PeriodSql} is where that is worked around without lying to Postgres.
 *
 * Both are installed for every tenant, whether or not that customer has a period
 * field today. The alternative was to install them the first time somebody needs
 * one, which would mean a `CREATE EXTENSION` inside the transaction of a metadata
 * edit — a DDL statement most of the way through a save, on a connection whose
 * role may or may not still be allowed to run it. This costs a few kilobytes per
 * database and is done once, in the place where schema changes belong.
 *
 * ## Nothing is created over anybody's data, and nothing can fail
 *
 * There is no table here, no column, no index and no constraint: this migration
 * adds two functions and an extension and touches not one row. A tenant with ten
 * million records is migrated in the time it takes to parse the SQL. The
 * constraints themselves arrive later and one at a time, when a customer marks a
 * period exclusive within something — and *that* is where a conflict can be met,
 * which is why {@see \Xivi\Core\Metadata\MetadataEditor} counts overlapping
 * records first and refuses with the pairs named.
 *
 * ## It imports an engine class, on purpose
 *
 * The same trade [XIV-109]'s indexing migration made with
 * `UniqueIndex::nameFor()`: what is shared is a **spelling** that two places must
 * agree on — the function bodies here and the expressions
 * {@see \Xivi\Core\Field\Type\PeriodFieldType::comparableSql()} builds — and a
 * copy is exactly how they come to disagree. `CREATE OR REPLACE` makes re-running
 * it harmless.
 *
 * **What that trade costs, and it is worth naming precisely.** Postgres does not
 * re-evaluate an index when a function it was built over changes. If a body in
 * `PeriodSql` is ever edited, this migration will install the new one on
 * databases migrated after the edit, every exclusion constraint built before it
 * will go on enforcing the *old* rule, and nothing will report the disagreement.
 * So a change there is not an edit: it is a new migration that replaces the
 * function **and rebuilds every constraint over it**.
 *
 * `down()` drops both functions and leaves the extension. Dropping a function an
 * exclusion constraint depends on fails outright — Postgres refuses, because the
 * index would be left with nothing to compute — which is the right way round: a
 * rollback that quietly took a booking rule away with it would be the worst thing
 * this file could do. Remove the constraints first, or do not go back. The
 * extension stays because it is shared, harmless and may have been installed by
 * somebody else entirely.
 */
final class Version20260819173114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Install the period range functions every exclusion constraint is built over';
    }

    public function up(Schema $schema): void
    {
        foreach (PeriodSql::definitions() as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (PeriodPrecision::cases() as $precision) {
            $this->addSql(sprintf('DROP FUNCTION IF EXISTS %s(text)', $precision->rangeFunction()));
        }
    }
}

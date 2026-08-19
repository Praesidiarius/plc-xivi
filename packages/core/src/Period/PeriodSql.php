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

namespace Xivi\Core\Period;

/**
 * The two functions a customer's database needs before a period can be indexed
 * (XIV-136).
 *
 * ### Why there are functions in a tenant database at all
 *
 * An exclusion constraint is an index, and **every expression in an index must
 * be `IMMUTABLE`** — the same value for the same input, for ever, or the index
 * quietly stops agreeing with the table. Postgres enforces that at creation, and
 * it is what rules out the obvious spelling: `(data ->> 'stay')::date` is not
 * immutable, because `date_in` is only *stable*. It reads `DateStyle` and accepts
 * `today` and `now`, so the same text can mean different days on different
 * sessions and on different afternoons. `timestamp_in` and `timestamptz_in` are
 * stable for the same reason, so the datetime half has no shortcut either.
 *
 * The way out is to stop asking Postgres to *parse* anything. A stored period is
 * a fixed-width ISO string — that is what {@see PeriodPrecision::pattern()}
 * guarantees — so the year, the month and the day are known offsets, and
 * `make_date`/`make_timestamp` build the value from integers with nothing to
 * interpret. All of those are genuinely immutable; nothing here is declared
 * immutable while being something else, which is the usual and much worse
 * workaround.
 *
 * That leaves a long expression, and the choice was between repeating it inside
 * every constraint definition and naming it once. It is named once, for three
 * reasons: an `EXCLUDE` clause is unreadable enough already; the same expression
 * has to appear in the *query* that filters by overlap
 * ({@see \Xivi\Core\Field\Type\PeriodFieldType::comparableSql()}) and the two
 * must be the same expression or the index cannot serve the filter; and `STRICT`
 * — which a bare expression cannot be — is what makes a row with no period
 * return `NULL` rather than an unbounded range that overlaps everything.
 *
 * ### It refuses to guess, and that is what keeps a bad row from taking the page
 *
 * The regular expression at the front is not decoration. A value that is not a
 * canonical period comes back as `NULL`, so one malformed row cannot raise an
 * error that fails the whole list — the failure mode
 * {@see \Xivi\Core\Field\Type\DateFieldType::comparableSql()} avoids by never
 * casting at all, which a range has no way of avoiding. Nothing should be able to
 * store such a value ({@see \Xivi\Core\Validation\ValidPeriod} refuses it before
 * the write), but "should be able to" is not the standard a query gets to hold
 * itself to.
 *
 * ### Changing a body here is a migration, not an edit
 *
 * **Postgres does not re-evaluate an index when a function it was built over
 * changes.** Editing one of these in place would leave every exclusion
 * constraint enforcing the old rule against new rows — a silent, unrecoverable
 * disagreement. So these definitions are frozen: a change means a new migration
 * that redefines the function *and* rebuilds every constraint over it, and the
 * comment on the migration should say so. The bodies live here rather than being
 * copied into the migration for the same reason
 * {@see \Xivi\Core\Record\UniqueIndex::nameFor()} is imported by one (XIV-109):
 * what is shared is a spelling that two places must agree on, and a copy is how
 * they come to disagree.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PeriodSql
{
    /**
     * `btree_gist`, which is what lets a scope sit in the same index as a range.
     *
     * An exclusion constraint is a GiST index, and GiST has no operator class for
     * plain equality on text out of the box — so "the same room *and* an
     * overlapping period" needs this extension to express its first half. It is
     * a *trusted* extension from Postgres 13, so the tenant's own role may
     * install it without being a superuser, which is why this can be an ordinary
     * migration rather than something an operator has to do by hand for every
     * customer.
     */
    public const string EXTENSION = 'CREATE EXTENSION IF NOT EXISTS btree_gist';

    /**
     * Every function a tenant database needs, ready for a migration to run.
     *
     * `CREATE OR REPLACE` so that provisioning, a re-run migration and a restored
     * database all converge on the same definition rather than one of them
     * failing — the idempotence {@see \Xivi\Core\Record\UniqueIndex} gets from
     * `IF NOT EXISTS`.
     *
     * @return list<string>
     */
    public static function definitions(): array
    {
        return [
            self::EXTENSION,
            self::dateRange(),
            self::datetimeRange(),
        ];
    }

    /**
     * Days: `2026-08-01/2026-08-05` and `2026-08-01/..`.
     *
     * `[)` written out even though `daterange` canonicalises to it anyway,
     * because the bound is the decision this feature turns on ({@see Period})
     * and a reader of this function should not have to know a canonicalisation
     * rule to find out what it is.
     */
    private static function dateRange(): string
    {
        $endpoint = PeriodPrecision::Date->pattern();
        $open = preg_quote(PeriodPrecision::OPEN, '/');

        return sprintf(
            <<<'SQL'
                CREATE OR REPLACE FUNCTION %s(text) RETURNS daterange
                LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $fn$
                    SELECT CASE WHEN $1 ~ '^%s%s(%s|%s)$' THEN daterange(
                        make_date(substr($1, 1, 4)::int, substr($1, 6, 2)::int, substr($1, 9, 2)::int),
                        CASE WHEN substr($1, 12, 2) = '%s' THEN NULL
                             ELSE make_date(substr($1, 12, 4)::int, substr($1, 17, 2)::int, substr($1, 20, 2)::int)
                        END,
                        '[)')
                    END
                $fn$
                SQL,
            PeriodPrecision::Date->rangeFunction(),
            $endpoint,
            PeriodPrecision::SEPARATOR,
            $endpoint,
            $open,
            PeriodPrecision::OPEN,
        );
    }

    /**
     * Moments: `2026-08-01T09:00:00Z/2026-08-01T11:00:00Z`.
     *
     * **`tsrange` over naive timestamps rather than `tstzrange`, and that is a
     * decision** (§8.4.4). Everything the engine stores is UTC — the `Z` is part
     * of the stored spelling and {@see PeriodPrecision::write()} is what puts it
     * there — so the wall clock in these strings *is* the instant, and comparing
     * them as zoneless timestamps compares instants exactly. Going through
     * `tstzrange` would mean `make_timestamptz`, which reads the session's
     * `TimeZone` and is therefore not immutable: the index would depend on the
     * setting of whichever connection last wrote to it, which is the failure this
     * whole file exists to avoid. The zone is not lost — it is known, and it is
     * the same one for every row.
     */
    private static function datetimeRange(): string
    {
        $endpoint = PeriodPrecision::DateTime->pattern();
        $open = preg_quote(PeriodPrecision::OPEN, '/');

        return sprintf(
            <<<'SQL'
                CREATE OR REPLACE FUNCTION %s(text) RETURNS tsrange
                LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $fn$
                    SELECT CASE WHEN $1 ~ '^%s%s(%s|%s)$' THEN tsrange(
                        make_timestamp(substr($1, 1, 4)::int, substr($1, 6, 2)::int, substr($1, 9, 2)::int,
                                       substr($1, 12, 2)::int, substr($1, 15, 2)::int, substr($1, 18, 2)::int),
                        CASE WHEN substr($1, 22, 2) = '%s' THEN NULL
                             ELSE make_timestamp(substr($1, 22, 4)::int, substr($1, 27, 2)::int, substr($1, 30, 2)::int,
                                                 substr($1, 33, 2)::int, substr($1, 36, 2)::int, substr($1, 39, 2)::int)
                        END,
                        '[)')
                    END
                $fn$
                SQL,
            PeriodPrecision::DateTime->rangeFunction(),
            $endpoint,
            PeriodPrecision::SEPARATOR,
            $endpoint,
            $open,
            PeriodPrecision::OPEN,
        );
    }
}

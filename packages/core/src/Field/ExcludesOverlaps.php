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

namespace Xivi\Core\Field;

/**
 * A field type whose values are ranges, and whose fields may say what they are
 * exclusive within (XIV-136).
 *
 * The **sixth** capability of this kind, after {@see Autocompletes} (XIV-36),
 * {@see Numbers} (XIV-27), {@see AssumesACountry} (XIV-114), {@see Enumerates}
 * and {@see PointsAtAModule} (XIV-144). It is optional, like the first three: a
 * period field that says nothing is an ordinary field holding an ordinary value,
 * and most of them are — a project's duration overlaps another project's
 * constantly and should.
 *
 * What declaring it means is two things, and the second is the one with teeth.
 *
 * **The editor may offer this field a scope**, which is the ordinary half: one
 * line in {@see \App\Controller\FieldController}'s `PER_TYPE`, one control in the
 * field table, and no branch anywhere ({@see \Xivi\Core\Period\ExclusiveWithin}).
 *
 * **And {@see FieldType::comparableSql()} returns a Postgres *range*.** That is a
 * narrowing of the base contract rather than an addition to it, and everything
 * built on this rests on it:
 *
 *  * the expression must be of a range type, so that `&&` over it means overlap
 *    — which is what {@see \Xivi\Core\Query\QueryCompiler} emits for
 *    {@see \Xivi\Core\Query\Operator::Overlaps}, without knowing what kind of
 *    field it is compiling;
 *  * it must be `IMMUTABLE`, because {@see \Xivi\Core\Record\OverlapExclusion}
 *    builds an index over the same expression and Postgres refuses anything else
 *    ({@see \Xivi\Core\Period\PeriodSql} is where that turned out to be hard);
 *  * and it must return `NULL` for a row with no value, or a record with an empty
 *    field would hold an unbounded range that overlaps every question anybody
 *    ever asks.
 *
 * **One expression, two readers, and that is the point.** The constraint that
 * refuses an overlap and the filter that finds one are the same SQL, so a filter
 * can be answered by the constraint's own index and the two can never come to
 * different conclusions about what "overlap" means.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface ExcludesOverlaps extends FieldType
{
}

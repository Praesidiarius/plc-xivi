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
 * A field type whose values are a list the customer keeps (XIV-144).
 *
 * The **fourth** capability of this kind and the first one that is not optional,
 * which is why it extends {@see NeedsAnAnswer}: a `choice` field with no options
 * is not a field with a default, it is a field that does nothing. What declaring
 * it buys is what the three before it bought — one line in the editor's per-type
 * list and one control, with no branch anywhere — and what it costs is the two
 * questions a list nobody had asked before now had to answer:
 *
 *  * **the value is not the label.** What every record holds is a stable key and
 *    what the page shows is text the customer may rename, so the editor derives
 *    a key from the first label it is given and then never touches it again.
 *    Renaming "Pallet" to "Palette" must not move a single record
 *    ({@see Type\ChoiceFieldType::valueFor()}).
 *  * **taking an option away is not the opposite of adding one.** Adding is
 *    instantaneous and reversible; removing one that records hold leaves records
 *    that no longer validate, which is what §5.4 refuses in general terms. So it
 *    is counted first and refused, exactly like making a field unique.
 *
 * **Not a shared list.** [XIV-127] proposes lists a customer maintains once and
 * several fields across several modules point at, and it is the right answer for
 * "our units", "our topics" and "our payment terms" — this is one field's own
 * closed set, which is what a `choice` field has always been. Whoever builds
 * that has to keep this answer to the second question above: a list somebody's
 * records point into cannot quietly lose an entry, whether the list lives in the
 * field or beside it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface Enumerates extends NeedsAnAnswer
{
}

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

use Xivi\Core\Query\Operator;

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
    /**
     * The comparison that finds the records still holding one of these options
     * ([XIV-169]).
     *
     * **The second question of the two above, asked of the type rather than
     * assumed by the caller**, and it had to become a question the day a second
     * type started enumerating. Removing an option is counted first and refused
     * while records hold it, and until [XIV-169] that count could be written as
     * `data ->> 'key' = 'pallet'` and be right, because the only type with
     * options held exactly one of them.
     *
     * A field holding several holds a JSON array, and that expression hands back
     * the array's own *text*: `["pallet", "crate"]`, which equals no option
     * anybody has ever had. So the count would come back zero, the refusal would
     * not fire, and the option would come off the list from under every record
     * holding it. **Nothing would report that.** §5.4's whole rule is enforced
     * by a number, and a number that is silently always zero is a rule switched
     * off rather than a rule that passed.
     *
     * Exactly {@see PointsAtAModule::findsTargetBy()}'s shape, one capability
     * over and for the same reason: a `switch` in the editor would be the switch
     * on field type this design exists to prevent, and a *silent* one. The
     * callers that count held values ask this and hand the answer to
     * {@see \Xivi\Core\Record\RecordRepository}, which builds the comparison
     * and knows nothing about which type asked for it.
     *
     * It is on this interface rather than on {@see FieldType} because it is only
     * answerable by a type whose values are a set somebody keeps: nothing else
     * has options for the question to be about.
     */
    public function findsHoldersBy(): Operator;
}

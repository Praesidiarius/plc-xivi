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

use Xivi\Core\Entity\FieldDefinition;

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
 * ## Why the list itself is on the interface (XIV-168)
 *
 * This started life as a bare marker. It said *that* a field of this type has a
 * closed set of values, and the one method that could hand the set over sat on
 * {@see Type\ChoiceFieldType}, where only a caller willing to name the concrete
 * class could reach it. That was enough while the only readers were the widget,
 * the validator and the display, all of which are the type's own code.
 *
 * The grouped index (§5.3) is the first reader from outside. It asks a question
 * that is about the *capability* rather than about a type: "what are the values
 * this field enumerates, so that each one can be a card". There are two ways to
 * answer it. Asking the registry for the type and testing the answer against
 * `ChoiceFieldType` would work today, and is the thing this codebase refuses
 * everywhere else, because it is `if type == 'choice'` with an extra step and
 * the second type that enumerates something would have to be added to a list
 * nobody would think to look for. Putting the method here costs one line in the
 * one implementor, which already had it, and means the next enumerating type is
 * groupable by declaring an interface it would have declared anyway.
 *
 * **The labels come with the values, and that is deliberate.** A caller drawing
 * a heading needs the customer's word for the value, and a caller handed the
 * values alone would have to go back to the type for each one, which is the
 * shape that turns a page into a query per card.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface Enumerates extends NeedsAnAnswer
{
    /**
     * What this field is a choice between, in the order it should be offered and
     * read.
     *
     * **The order is the customer's**, not the code's. Options are arranged in
     * the field editor (§5.20) and whatever comes back here is the arrangement
     * they made. Anything drawing one card, one radio or one column per value
     * follows it rather than sorting.
     *
     * **The current answer, never the blueprint's.** A module ships a list and
     * from the moment it is installed the customer's definition is the truth
     * (§6.1). Options added since are in here, and options the module never
     * shipped are too, which is what lets a topic somebody invented last week
     * have a card without anybody writing code.
     *
     * @return array<string, string> stored value => the label to show for it
     */
    public function optionsOf(FieldDefinition $field): array;
}

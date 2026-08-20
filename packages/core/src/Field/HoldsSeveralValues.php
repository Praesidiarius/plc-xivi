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
 * A field type whose stored value is a list rather than one thing (XIV-113).
 *
 * **Not one of the numbered capabilities**, and the difference is the reason it
 * is worth a file. Those say which question the editor asks a customer (the
 * module a reference points at, the list a choice takes its values from, the
 * region a phone number assumes), and each one costs a line in
 * {@see \App\Controller\FieldController::PER_TYPE} and a control. This one says
 * something about the **shape of what is stored**, so it has no line there and
 * never will: there is nothing here for anybody to tick, and a control for it
 * would be a control that changes what every record already holds, which is
 * §5.21's whole objection.
 *
 * ## Why the shape needs saying out loud at all
 *
 * Three things in this engine were written when every stored value was a scalar,
 * and each of them is wrong in a different way for a list. None of them can ask
 * "is this a `multi_reference`" without becoming the switch on field type the
 * whole design exists to prevent, so each asks this instead:
 *
 *  * **`unique`** is a partial index over `data ->> 'key'` (§7.2). For a JSON
 *    array that expression is the array's own *text*, so the index would build
 *    perfectly and quietly enforce "no two records hold the same whole set":
 *    a rule nobody asked for, which the validator in front of it does not check,
 *    and which is not the question somebody ticking the box is asking. The
 *    editor refuses the flag on a type that declares this, and does not draw the
 *    checkbox.
 *  * **Sorting** ends every ordering on the record id so a LIMIT has a total
 *    order (§5.3), and a set of ids has no place in one. §5.3 already refuses
 *    sorting by a collection because a contact with two addresses has two cities
 *    and no answer; a field holding four links is the same sentence with fewer
 *    tables in it, so the compiler refuses it and the list header does not offer
 *    the link.
 *  * **The export** writes storage form into a spreadsheet cell (§5.6), and a
 *    cell holds text. {@see self::SEPARATOR} is what a list becomes on the way
 *    out and what the type reads back on the way in.
 *
 * ## What it deliberately does not promise
 *
 * Nothing about what the items *are*. A list of record ids and a list of choice
 * values would both declare this and would agree on none of their validation,
 * their display or their filtering, which is exactly the division of labour
 * everywhere else here: the shape is common, the meaning is the type's.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface HoldsSeveralValues extends FieldType
{
    /**
     * What separates the items in one spreadsheet cell (§5.6).
     *
     * **A comma, and no escape, because the alphabet is closed.** The one type
     * that holds a list today holds record ids, which are digits; nothing an id
     * can contain is this character, so there is no ambiguity for an escape to
     * resolve. An item that is not a record id is not escaped either. It is
     * kept as it is and refused by the type's own constraints, with the offending
     * item named, which is the actionable error a silent drop would not have
     * been.
     *
     * That reasoning is the type's rather than this interface's, and a second
     * type holding a list of free text would have to bring its own answer. It is
     * a constant here because the export and the import must agree on it and
     * neither of them may know which type they are looking at.
     */
    public const string SEPARATOR = ',';
}

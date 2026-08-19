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

use Xivi\Core\Entity\ValueList;

/**
 * A field type whose values may come from a list the customer keeps beside the
 * field rather than inside it (XIV-127).
 *
 * The **sixth** capability, and it is the first that does not add a question —
 * it adds a **second answer to one that was already there**. {@see Enumerates}
 * says a `choice` field is not a field until somebody has said what it is a
 * choice between; this says one of the ways of saying so is to point at a
 * {@see ValueList}. Which is why it extends `Enumerates` rather than sitting
 * beside it: a type that takes its values from a list is a type whose values are
 * a list, and the editor, the validator, the widget and the exporter should not
 * be able to tell the difference.
 *
 * ## What pointing at one buys, and why it is not a field's own options
 *
 * Three things a `choice` field's own options cannot have, and the third is the
 * one this exists for:
 *
 *  * an entry may carry a **colour** and an **icon** ({@see \Xivi\Core\ValueList\ValueTone},
 *    {@see \Xivi\Core\ValueList\ValueIcon}), because it is a row and not a string;
 *  * an entry may have a **parent**, so forty regions can be read as five
 *    countries;
 *  * **one list, several fields.** *Region* on a contact and *Region* on an
 *    order were two unrelated strings that drifted apart the moment somebody
 *    edited one. That is the whole ticket, and it is not something a field's own
 *    options can be made to do — an option that lives inside one definition is
 *    by construction that definition's.
 *
 * ## The three rules it inherits rather than invents
 *
 * **[XIV-144] settled all of them for a field's own options, and §5.4 says a
 * shared list is the same question with more records behind it** — so this
 * re-decides nothing:
 *
 *  * an entry's **stored value is derived from its label once and frozen**, so
 *    renaming the label moves no record;
 *  * **an entry records point into cannot be taken away** while they point into
 *    it, refused with the values named and counted;
 *  * **retirement is not built** — see §5.4, and
 *    {@see \Xivi\Core\Metadata\MetadataChangeRefused::entriesAreHeld()}.
 *
 * ## And the one rule that is this ticket's own
 *
 * **Attaching a list to a field records already have values in is refused when
 * the list has not got those values.** This is the answer to the objection §5.21
 * raises against options in general — that a checkbox reinterprets everything
 * already stored — and it is answered rather than dodged: the values are counted
 * against the list first, and a field whose records would stop matching anything
 * is left alone with the numbers named. It is the same shape as repointing a
 * populated reference ({@see PointsAtAModule}) and it is refused by the same
 * method.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface PointsAtAList extends Enumerates
{
}

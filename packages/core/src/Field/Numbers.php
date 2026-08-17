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
 * A field type whose fields can be filled from a counter (XIV-27).
 *
 * The second capability of this kind, after {@see Autocompletes} (XIV-36), and
 * the one that turns the pair into the shape §5.4 has been describing since the
 * metadata editor was built: a **type** says which of its options are the
 * customer's to set, and the editor draws the control for those and no others.
 * The editor now holds one declared list of option-to-capability rather than an
 * `instanceof` per option — see {@see \App\Controller\FieldController} — so a
 * third of these is a class and a line rather than another branch in a form.
 *
 * **Text, and only text.** A number is a string: `ORD-2026-0001` is not an
 * integer with decoration, it has a prefix, leading zeros that are part of it,
 * and a width chosen so that sorting the text sorts the documents (§5.10). An
 * `integer` field would store 1 and print 1, losing every part of that; a `date`
 * or a `choice` has nothing a counter could mean. So this is deliberately not a
 * property of "any field the engine could technically write into" — it is a
 * statement about which kind of value can *be* a document number.
 *
 * Being of a type that declares this is necessary and not sufficient. Whether a
 * particular field is numbered is still {@see \Xivi\Core\Numbering\NumberFormat}
 * reading that field's own `sequence` option, because two text fields on one
 * module are ordinarily one number and one name.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface Numbers extends FieldType
{
}

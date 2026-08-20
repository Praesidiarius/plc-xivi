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
 * A field type whose value names bytes kept outside the database (XIV-115).
 *
 * **The same kind of declaration as {@see HoldsSeveralValues}**, and not one of
 * the numbered capabilities on {@see \App\Controller\FieldController::PER_TYPE}.
 * Those say which question the editor asks a customer and each costs a control;
 * this one says something about *what is stored*, and there is nothing here for
 * anybody to tick. A file field's options are the type's, not the field's.
 *
 * ## Why the shape has to be sayable at all
 *
 * Four things in this engine would otherwise have to ask "is this a `file`",
 * which is the switch on field type the whole design exists to prevent:
 *
 *  * **The download route** (§8.4) needs the token, the name and the media type
 *    out of a value it is handed, and it is handed a `FieldDefinition` and a
 *    string. It asks this.
 *  * **The record page** draws a file as a link to that route rather than as
 *    text, on the same rule {@see LinksToRecord} follows: the template asks the
 *    field, and a field with no file answers null and is drawn as it always was.
 *  * **The drift check** walks a tenant's records to find the tokens they claim
 *    (§4.7). It has to know which fields can claim one without knowing which
 *    types exist.
 *  * **A shape that is not a module** is refused one, in the editor and in the
 *    installer. The reason is the route again: a download is addressed by module
 *    and record id, and a collection row has no address of its own today. A
 *    second type holding bytes inherits that refusal rather than discovering it.
 *
 * ## One file, deliberately, and XIV-113 is the precedent
 *
 * A field of this shape holds exactly one. Several is not a `multiple` option on
 * this type, for §5.21's reason and XIV-113's: ticking a box that turns one
 * stored string into a list reinterprets every record already holding one, and
 * there is no answer to which of four files survives it being unticked. If a
 * customer wants several, that is a *type* of its own that declares this and
 * {@see HoldsSeveralValues} together, and everything below is written so that
 * the day it arrives, nothing here has to move: every caller asks the field for
 * what it holds rather than assuming a value is one file.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface HoldsAFile extends FieldType
{
    /**
     * The file this value names, or null when there is not one.
     *
     * Null covers three cases that are all the same to a caller: an empty field,
     * a value left behind by a definition that has been removed (§5.4), and a
     * string that is not a stored file at all. What none of them mean is that
     * the bytes are missing, which is a question about a filesystem and is asked
     * one layer along.
     */
    public function fileOf(mixed $value, FieldDefinition $field): ?StoredFile;
}

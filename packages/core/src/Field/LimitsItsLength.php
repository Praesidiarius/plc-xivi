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
 * A field type holding text, whose fields may say how much of it (XIV-163).
 *
 * The **eighth** capability of this kind, and the first that describes an option
 * the editor already had rather than one it is gaining. `max_length` was one of
 * three settings the editor kept in a hand-written list of its own and drew for
 * every field there is, on the argument that a number is harmless where it means
 * nothing. It is not harmless: a maximum-length box beside a date field is a
 * control that does nothing, which is the defect XIV-144 is named after and the
 * one §8.3.1 exists to prevent. The hand-kept list was also a second place where
 * a type and a control could drift apart, standing beside the one place built to
 * stop exactly that.
 *
 * So the three settings joined the mechanism the other seven use, and the list
 * went. What this buys is more than the controls that were missing: [XIV-163]
 * gives every field type a form of its own, and "the options this type declares"
 * is the whole content of that form. A setting outside the declarations would
 * have had to be drawn on every one of those forms or on none of them.
 *
 * **What declaring it means.** Values are text, and a field of this type may
 * carry a maximum number of characters that the type's own validation, its form
 * control and its truncation all read. A field that says nothing gets the type's
 * default, which is why this is an ordinary capability rather than a
 * {@see NeedsAnAnswer}: saying nothing is a good answer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface LimitsItsLength extends FieldType
{
    /**
     * The option a field of this type carries its limit in.
     *
     * Named here rather than on each type, because the point of the declaration
     * is that one string means one thing across the editor, the engine and every
     * type that has this shape.
     */
    public const string OPTION = 'max_length';
}

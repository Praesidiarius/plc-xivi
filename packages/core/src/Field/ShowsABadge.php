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
 * A field type whose values may come with a colour and a picture (XIV-127).
 *
 * **The same shape as {@see LinksToRecord} and {@see HoldsFormattedText}, for
 * the same reason**, and that similarity is the whole argument: a page asking
 * `field.type == 'choice'` is a page that has to be edited the next time
 * something has a colour, so it asks the *field* instead and a type with nothing
 * to draw does not implement this. `display()` stays what it has always been —
 * the value as one line of text — and this is the extra a caller with room for
 * it may draw instead.
 *
 * **Null is the important answer.** It means "there is nothing to see here,
 * render this the way you always did", which is what a `choice` field keeping
 * its own options returns for every value it holds. Every existing page in every
 * tenant therefore looks exactly as it did, and the colour is visible only where
 * a customer has asked for one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface ShowsABadge extends FieldType
{
    /**
     * The chip for one stored value, or null to draw it the ordinary way.
     *
     * Null both for a field that has no colours at all and for a value inside
     * one that has not been given one, because the caller does the same thing in
     * either case and a caller that had to tell them apart would be a caller
     * with an `if` it does not need.
     */
    public function badgeOf(mixed $value, FieldDefinition $field): ?ValueBadge;
}

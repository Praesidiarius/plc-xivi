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
 * A field type whose one stored value draws more than one chip ([XIV-169]).
 *
 * {@see ShowsABadge} answers with a badge or with null, and both halves of that
 * are right for a value that is one thing: a `choice` field pointed at a shared
 * list draws the entry's colour, and one keeping its own options draws nothing,
 * because a badge around a bare word is furniture (§5.26).
 *
 * A field holding several values cannot answer either way. Its stored value is
 * an array, so a method typed to return one badge would have to pick one of them
 * or invent a chip meaning "three things", and a caller handed a single badge
 * would draw one where the record holds four.
 *
 * ## Why several chips rather than `display()`'s comma-separated line
 *
 * {@see HoldsSeveralValues}' separator is a comma and so is
 * {@see Type\MultiChoiceFieldType::display()}, and that is right wherever the
 * destination is text: a spreadsheet cell, a .docx marker, a record's own title.
 * On a page it is not, and the reason is the customer's own labels rather than a
 * preference about looks. A label is free text, so `Zurich, CH` is a perfectly
 * ordinary single option, and two of those joined by a comma read as four
 * values. A chip per value is the only rendering that says where one value ends
 * and the next begins, whatever anybody typed.
 *
 * That argument holds for a field with no colours at all, which is why this does
 * not simply collect the non-null answers {@see ShowsABadge} would give: a
 * multi-valued field draws chips because it holds several, not because somebody
 * coloured them. §5.26's "a badge around a word is furniture" is a statement
 * about a lone value, and a lone value is what a `choice` field has.
 *
 * **No ceiling here.** How many chips are too many is a question about the room
 * a caller has, and a record page and a list column have very different answers;
 * whichever number this returned would be one of them imposed on both. So it
 * hands over every value, and a template caps what it cannot fit and states its
 * own number where the room is.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface ShowsSeveralBadges extends FieldType
{
    /**
     * One chip per value the record holds, in the order they should read.
     *
     * Empty for a field holding nothing, which is the caller's cue to draw the
     * blank it draws for every other empty field rather than an empty chip.
     *
     * @return list<ValueBadge>
     */
    public function badgesOf(mixed $value, FieldDefinition $field): array;
}

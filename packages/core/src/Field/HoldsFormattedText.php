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
 * A field type whose value is Markdown, and can hand back the source (XIV-131).
 *
 * The third capability interface on a field type, after {@see LinksToRecord} and
 * {@see Autocompletes}, and it is here for the same reason both of those are: a
 * question only some types can answer, asked by the places that can use the
 * answer, so that nothing anywhere has to switch on a type key. A template
 * writing `field.type == 'markdown'` is a template that has to be edited the
 * next time somebody adds a type that also holds prose — [XIV-132]'s
 * knowledge-base entry is the one already on the list.
 *
 * **Why this is not `display()`.** That method is plain text on purpose and
 * three things depend on its being so: `DocumentMarkers` fills .docx templates
 * with it, the exporter and the list write it into cells, and `recordTitle()`
 * builds a record's *name* out of the display of its title fields. §5.21 decides
 * that all of those get the words with the marks taken off — `Warning: do not…`
 * rather than `**Warning:** do not…` — which means `display()` is a *lossy*
 * rendering and cannot be what a page re-parses to draw the formatted version.
 * Asking for the source separately is what keeps the lossy answer lossy and the
 * faithful one faithful.
 *
 * **Why a method rather than a marker interface.** A bare marker would have
 * meant "read `display()` as Markdown", which is exactly the sentence the
 * paragraph above says is false. One method that returns the source says what it
 * returns, and a type is free to store something other than the source and
 * derive it — which is not a hypothetical the engine has to pay for now, but is
 * the difference between an interface that describes a value and one that
 * describes a coincidence.
 *
 * **What implementing this asserts** is narrow and worth stating: that the
 * string coming back is *source text a person typed*, not markup. It is about to
 * be handed to {@see \Xivi\Core\Markdown\MarkdownRenderer}, whose parser is
 * configured to escape raw HTML, and that is the whole reason a record value can
 * reach a page as text rather than as markup. A type that returned HTML here
 * would be handing the sanitizer sole responsibility for a customer's data,
 * which is the trade §5.13.1 refused when it insisted a collection expand to
 * Markdown rather than to HTML.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface HoldsFormattedText
{
    /**
     * The Markdown source, exactly as somebody typed it, or the empty string
     * when the field holds nothing.
     *
     * Empty rather than null so that a caller does not have to distinguish
     * "nothing stored" from "stored and empty" — the types here already collapse
     * those two on the way into storage, and a page drawing a blank block is the
     * same page either way.
     */
    public function source(mixed $value, FieldDefinition $field): string;
}

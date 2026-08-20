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

namespace Xivi\Core\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\HoldsFormattedText;
use Xivi\Core\Field\HoldsSeveralValues;
use Xivi\Core\Field\LinksToRecord;
use Xivi\Core\Field\RecordLink;
use Xivi\Core\Field\ShowsABadge;
use Xivi\Core\Field\ValueBadge;
use Xivi\Core\Markdown\MarkdownRenderer;

/**
 * `display(field, value)` in a template.
 *
 * Without it every list view would need to ask what kind of thing it is holding
 * — is this a date, does it need formatting — which is knowledge the field type
 * already owns. A template asking that question is a template that has to be
 * changed each time a field type is added.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FieldDisplayExtension extends AbstractExtension
{
    public function __construct(
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly MarkdownRenderer $markdown,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display', $this->display(...)),
            new TwigFunction('record_link', $this->recordLink(...)),
            // Markup, and the only function here that is: see the method for why
            // this one may hand back something already safe and `display()`
            // never can.
            new TwigFunction('formatted', $this->formatted(...)),
            new TwigFunction('value_badge', $this->valueBadge(...)),
            new TwigFunction('display_stored', $this->displayStored(...)),
            new TwigFunction('is_sortable', $this->isSortable(...)),
            new TwigFunction('in_field_order', $this->inFieldOrder(...)),
            new TwigFunction('record_title', $this->recordTitle(...)),
        ];
    }

    /**
     * What to call one record, in one line.
     *
     * Built from the fields the shape says name it (§5.4), rendered through
     * their own types so a date in a title reads like a date. Falls back to the
     * shape's label and the record's id, because a record with nothing filled in
     * still has to be referred to somehow — "Contacts #14" is a poor name and a
     * worse blank.
     *
     * @param array<string, mixed> $data the record's values
     */
    public function recordTitle(ShapeDefinition $shape, array $data, ?int $id = null): string
    {
        $parts = [];

        foreach ($shape->getTitleFields() as $field) {
            $shown = trim($this->display($field, $data[$field->getKey()] ?? null));

            if ($shown !== '') {
                $parts[] = $shown;
            }
        }

        return $parts === []
            ? sprintf('%s #%s', $shape->getLabel(), $id ?? '?')
            : implode(' ', $parts);
    }

    /**
     * The same entries, in the order the shape declares its fields.
     *
     * Needed because `jsonb` does not keep the order keys were written in —
     * Postgres sorts them by length and then bytes — so a history entry read back
     * would list an address as "Bern, Work, Bahnhofstrasse 5" (§5.2). The
     * definitions still know the intended order, so it is restored here rather
     * than stored twice.
     *
     * Keys the shape no longer declares keep their place at the end instead of
     * disappearing: history outlives the fields it describes.
     *
     * @param array<string, mixed> $entries
     *
     * @return array<string, mixed>
     */
    public function inFieldOrder(?ShapeDefinition $shape, array $entries): array
    {
        if ($shape === null) {
            return $entries;
        }

        $ordered = [];

        foreach ($shape->getFields() as $field) {
            if (\array_key_exists($field->getKey(), $entries)) {
                $ordered[$field->getKey()] = $entries[$field->getKey()];
            }
        }

        return [...$ordered, ...array_diff_key($entries, $ordered)];
    }

    public function display(FieldDefinition $field, mixed $value): string
    {
        return $this->fieldTypes->get($field->getType())->display($value, $field);
    }

    /**
     * The record a value points at, when there is one the reader may open
     * (XIV-42).
     *
     * The template asks *the field* whether it links, rather than asking whether
     * it is a reference — which is the same rule this whole extension exists
     * for: a template that switches on field type is a template that has to be
     * changed every time a type is added. A type with nothing to link to does
     * not implement the interface and the answer is null.
     */
    public function recordLink(FieldDefinition $field, mixed $value): ?RecordLink
    {
        $type = $this->fieldTypes->get($field->getType());

        return $type instanceof LinksToRecord ? $type->linkOf($value, $field) : null;
    }

    /**
     * The value as formatted markup, when the field is one that holds any
     * (XIV-131).
     *
     * The same shape as `recordLink()` above and for the same reason: **the
     * template asks the field, not the type key.** A page writing
     * `field.type == 'markdown'` is a page that has to be edited the next time
     * something else holds prose, and [XIV-132] already has one on the way. A
     * type with nothing to format does not implement the interface and the
     * answer is null, which is the caller's cue to fall back to `display()`.
     *
     * **Null and the empty string are different answers here.** Null means "this
     * field is not formatted, draw it the ordinary way"; the empty string means
     * "it is formatted and holds nothing", which is a blank the caller renders
     * as a blank rather than as a paragraph containing nothing.
     *
     * **Why this may come back already safe and `display()` may not.** What is
     * in the {@see Markup} has been through {@see MarkdownRenderer::toHtml()},
     * which parsed it with raw HTML escaped and then put the result through the
     * sanitizer's policy — so the markup in it is markup CommonMark produced,
     * not markup a customer typed. `display()` returns a value straight out of
     * storage and is escaped by Twig like everything else, which is the
     * difference between the two and the reason they are two functions rather
     * than one with a flag.
     */
    public function formatted(FieldDefinition $field, mixed $value): ?Markup
    {
        $type = $this->fieldTypes->get($field->getType());

        return $type instanceof HoldsFormattedText
            ? new Markup($this->markdown->toHtml($type->source($value, $field)), 'UTF-8')
            : null;
    }

    /**
     * The colour and the picture a value carries, when it carries any
     * (XIV-127).
     *
     * The third function on this class with exactly this shape, after
     * `record_link()` and `formatted()`, and the third one written this way for
     * the reason the first two give: **the template asks the field, not the type
     * key.** A page that wrote `field.type == 'choice'` would be a page to edit
     * the next time something has a colour, and it would still be wrong today —
     * a `choice` field keeping its own options has no colours, so the answer
     * depends on the field rather than on its type.
     *
     * Null means "draw this the way you always did", which is what every field
     * in every tenant returns until somebody points one at a shared list.
     */
    public function valueBadge(FieldDefinition $field, mixed $value): ?ValueBadge
    {
        $type = $this->fieldTypes->get($field->getType());

        return $type instanceof ShowsABadge ? $type->badgeOf($value, $field) : null;
    }

    /**
     * Whether a list may be put in the order of this column (XIV-113).
     *
     * The fourth function on this class with the same shape as `record_link()`,
     * and written for the reason that one gives: **the template asks the field,
     * not the type key.** A header writing `field.type != 'multi_reference'`
     * would be a header to edit the next time something holds a list.
     *
     * The answer is no for a field holding several values, because a record with
     * four tags has four and none of them is the record's. That is §5.3's own
     * argument for refusing to sort by a collection, and
     * {@see \Xivi\Core\Query\QueryCompiler} is what makes it true. This is the half that keeps a customer from meeting it: a
     * column header offering a link that raises is worse than one that is plain
     * text, and the two have to agree or the refusal is a 500 somebody clicked.
     */
    public function isSortable(FieldDefinition $field): bool
    {
        return !$this->fieldTypes->get($field->getType()) instanceof HoldsSeveralValues;
    }

    /**
     * The same, for a value that is still in storage form.
     *
     * History keeps what it recorded exactly as it was stored (§5.2), so a date
     * in an old entry is a string rather than a date object. Sending it through
     * the type both ways means an entry from last year renders like the field it
     * describes, instead of like whatever JSON happened to hold.
     */
    public function displayStored(FieldDefinition $field, mixed $stored): string
    {
        $type = $this->fieldTypes->get($field->getType());

        return $type->display($type->fromStorage($stored, $field), $field);
    }
}

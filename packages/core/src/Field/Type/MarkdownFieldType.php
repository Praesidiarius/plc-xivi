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

namespace Xivi\Core\Field\Type;

use Symfony\Component\Validator\Constraints as Assert;
use Xivi\Core\Demo\SampleVocabulary;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\HoldsFormattedText;
use Xivi\Core\Field\LimitsItsLength;
use Xivi\Core\Form\MarkdownType;
use Xivi\Core\Markdown\MarkdownRenderer;
use Xivi\Core\Query\Operator;

/**
 * Text with formatting in it: a procedure, an article description that goes on a
 * document, a knowledge-base entry (XIV-131).
 *
 * The longest thing a record could hold until now was a `textarea`, which is
 * plain text — no headings, no lists, no emphasis, no links. That is right for a
 * note and wrong for anything somebody is meant to *follow*.
 *
 * ### Markdown, because the dangerous half was already solved
 *
 * The alternative was a rich-text editor storing HTML, and it loses on the one
 * property that matters. XIV-38 and XIV-62 built Markdown rendering for email,
 * and the valuable part of that work is not the rendering: it is that
 * substitution happens on the **source**, with the parser told to escape raw
 * HTML, so a value containing a script tag becomes text without anybody
 * remembering to make it so (§5.13). A field storing HTML arrives on the far
 * side of that decision and leaves a sanitizer as the only thing between a
 * customer's data and the markup of a page — exactly the trade XIV-62 refused
 * when it insisted a collection expand to Markdown rather than to HTML. It also
 * costs a dependency; `league/commonmark` is already installed, and a WYSIWYG
 * editor is a JavaScript bundle this application has promised not to fetch from
 * anybody's CDN (§8.3).
 *
 * ### Its own type rather than an option on `textarea`
 *
 * The question is real — an option means every existing textarea keeps working
 * and a customer ticks a box — and it was decided against, for three reasons
 * that are argued at length in §5.21. In short:
 *
 * 1. **The precedent is one file away and went the same way.**
 *    {@see TextareaFieldType} is its own type rather than an option on `text`,
 *    because *everything that follows from the length differs*. Everything that
 *    follows from formatting differs at least as much — what the widget is, what
 *    a record page draws, what a Word document is given, what a list cell says.
 * 2. **Whether a stored value is markup-bearing has to be readable from the
 *    type.** `$type instanceof HoldsFormattedText` is one question the container
 *    answers; `$field->getOption('markdown') === true` is a question every
 *    caller answers again, and two answers is how one of them ends up
 *    unescaped.
 * 3. **A checkbox would be retroactive and a type cannot be.** Ticking it
 *    reinterprets every value already in the field at once — a parts list typed
 *    with `*` bullets and `_snake_case_` codes changes meaning in every record —
 *    with no migration, no history entry, because nothing changed, and nothing
 *    to see afterwards.
 *
 * The cost is honest and is accepted: there is no path from an existing
 * `textarea` to this. That is a conversion of stored data and belongs in §7.2's
 * territory as an explicit operation, not as a checkbox that silently
 * reinterprets what a customer already wrote.
 *
 * ### What it stores, and what everything else gets
 *
 * The **source**, as typed, and nothing derived. So the export writes the source
 * — the exporter works in storage form and a file that cannot be imported back
 * is not a backup — and a filter matches the source, which is the string
 * Postgres has. Both are §5.21's decisions rather than accidents of the storage
 * layer, which is why they are written down.
 *
 * `display()`, on the other hand, is **the words with the marks taken off**,
 * because a Word document and a table cell can carry neither the markup nor an
 * excuse for the punctuation. The formatted rendering is a second question,
 * asked through {@see HoldsFormattedText} by the one place that has a block to
 * draw it in.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MarkdownFieldType implements HoldsFormattedText, LimitsItsLength
{
    /**
     * Four times what a `textarea` allows, because the things this exists for
     * are longer than the things that one exists for.
     *
     * A note fits in five thousand characters and a procedure does not. The
     * number is still a number rather than "unbounded": a field with no ceiling
     * is a field somebody can paste a megabyte into, and every row of a list
     * then carries it out of the database.
     */
    public const int DEFAULT_MAX_LENGTH = 20000;

    /**
     * Taller than a `textarea`'s five. Somebody writing a procedure wants to see
     * the shape of it, and the preview underneath is only useful next to enough
     * source to have produced it.
     */
    public const int DEFAULT_ROWS = 12;

    public function __construct(
        private readonly SampleVocabulary $vocabulary,
        private readonly MarkdownRenderer $markdown,
    ) {
    }

    public function key(): string
    {
        return 'markdown';
    }

    public function label(): string
    {
        return 'Formatted text';
    }

    public function constraints(FieldDefinition $field): array
    {
        return [
            new Assert\Type('string'),
            // Measured on the source, which is what is stored and what the
            // customer typed. Counting the rendered words instead would mean a
            // field whose limit moves when somebody adds emphasis to a sentence.
            new Assert\Length(max: $this->maxLength($field)),
        ];
    }

    /**
     * Demo data with actual formatting in it (XIV-24).
     *
     * A sample that came back as one flat sentence would make every demo record
     * look like a `textarea` and hide the entire feature from anybody clicking
     * through a freshly seeded tenant — including from whoever is checking that
     * the rendering works. So it builds the three constructs a reader is most
     * likely to meet: a heading, a paragraph with emphasis in it, and a short
     * list.
     */
    public function sample(FieldDefinition $field, int $sequence): ?string
    {
        if (!$field->isRequired() && mt_rand(1, 10) === 1) {
            return null;
        }

        $word = fn (): string => $this->vocabulary->forKey($field->getKey());

        $lines = [
            '## ' . $word(),
            '',
            sprintf('%s **%s** %s.', $word(), $word(), $word()),
            '',
        ];

        foreach (range(1, mt_rand(2, 4)) as $ignored) {
            $lines[] = '- ' . $word();
        }

        return mb_substr(implode("\n", $lines), 0, $this->maxLength($field));
    }

    public function toStorage(mixed $value, FieldDefinition $field): ?string
    {
        if ($value === null) {
            return null;
        }

        // Trailing whitespace on the whole value only, exactly as `textarea`
        // does it — the blank lines *inside* are the block structure and
        // touching them would rewrite what somebody wrote. Empty and "not filled
        // in" stay one thing.
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function fromStorage(mixed $value, FieldDefinition $field): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function formType(): string
    {
        return MarkdownType::class;
    }

    public function formOptions(FieldDefinition $field): array
    {
        return [
            'attr' => [
                'maxlength' => $this->maxLength($field),
                'rows' => $field->getOption('rows') ?? self::DEFAULT_ROWS,
            ],
        ];
    }

    /**
     * The words, with the marks taken off.
     *
     * Not the source, and not markup. §5.21 decides this for all three of
     * `display()`'s consumers at once, because they have the same constraint: a
     * .docx is not HTML, a spreadsheet cell is not HTML, and a list cell has no
     * room to be. Given that the formatting cannot survive any of those trips,
     * the only question left is whether the punctuation travels with it, and
     * `**Warning:** do not…` printed on a customer's invoice is punctuation
     * nobody meant to send.
     *
     * The faithful rendering lives in {@see source()} and is asked for by name.
     */
    public function display(mixed $value, FieldDefinition $field): string
    {
        return \is_string($value) ? $this->markdown->toText($value) : '';
    }

    /** The source, untouched, for the one caller that renders it (XIV-131). */
    public function source(mixed $value, FieldDefinition $field): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * The same three a `textarea` offers, and they match **the source**.
     *
     * That is a decision rather than a consequence of the storage: `contains`
     * runs against the string in the payload, so searching for `Warning` finds a
     * record whose text says `**Warning:**`, and searching for `**` finds every
     * record with emphasis in it. The alternative — matching the rendered words
     * — would mean either rendering every row in the database on every query or
     * storing a second, derived copy of every value to search against, and
     * neither is worth buying the ability to *not* find punctuation somebody
     * typed.
     */
    public function operators(): array
    {
        return [
            Operator::Contains,
            Operator::IsEmpty,
            Operator::IsNotEmpty,
        ];
    }

    /** Text in the payload already, like `text` and `textarea`. */
    public function comparableSql(string $accessor): string
    {
        return $accessor;
    }

    /** @return int<1, max> at least one, since a field nothing fits in is not a shorter field */
    private function maxLength(FieldDefinition $field): int
    {
        return max(1, (int) $field->getOption('max_length', self::DEFAULT_MAX_LENGTH));
    }

    /**
     * The whole row, for the reason `textarea` takes it and then some: this one
     * is drawn with a preview underneath, and a preview in half a row is a
     * column of two-word lines that looks nothing like what will be rendered.
     */
    public function defaultWidth(): int
    {
        return 12;
    }
}

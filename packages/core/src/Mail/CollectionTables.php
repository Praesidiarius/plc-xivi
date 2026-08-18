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

namespace Xivi\Core\Mail;

use Xivi\Core\Document\DocumentMarker;
use Xivi\Core\Document\DocumentMarkers;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\InheritedValue;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordPrimer;
use Xivi\Core\Record\RecordRepository;

/**
 * A collection, written into an email as one marker and rendered as a table
 * (XIV-62).
 *
 * The answer to the question XIV-38 declined to answer on purpose, and it is
 * deliberately **not** {@see \Xivi\Core\Document\RepeatingBlocks} ported to
 * Markdown. That class exists because Word hands a person a unit: its own
 * docblock says "a `<w:tr>` is the unit because it is the unit Word gives a
 * person", and a template author builds the row they want and gets it that many
 * times. Markdown hands nobody a unit, so there was no port to do — only a
 * choice to make, and every candidate cost something.
 *
 * ### Why one marker rather than a repeating construct
 *
 * A Markdown table row would have been the closest thing to the docx model, and
 * it is text held together by punctuation: a line description containing `|`
 * breaks the template rather than the line. A list item is natural Markdown and
 * a bad fit, because line items have columns and a list has one. Explicit
 * `[lines]…[/lines]` delimiters are unambiguous and are a template language
 * arriving by the side door, in a system whose markers are flat substitutions
 * and deliberately nothing else.
 *
 * All three exist to let a tenant hand-build the table, and **that is the part
 * that contradicts a decision already taken.** §5.13's argument for Markdown was
 * that an email has no layout worth designing — it is why there is no .docx
 * here, no rich-text editor and no per-tenant wrapper. Handing somebody a
 * repeating construct so they can lay out their own line table takes that back.
 *
 * So `[lines]` is one marker that renders the whole collection, and **the shape
 * it renders into ships in code**, exactly as the base template does (§6.1). The
 * divergence from the document side is the point rather than an oversight: in
 * Word the layout *is* the deliverable, and in an email it is not — and XIV-40
 * already attaches the document, where the lines are laid out properly. What an
 * email body wants beside that attachment is a summary, not a second rendering
 * of it.
 *
 * ### It renders Markdown, and that is the load-bearing part
 *
 * §5.13 made marker substitution happen on the **Markdown source, before
 * CommonMark parses it**, with `html_input: escape`, so a record value
 * containing markup becomes text without anybody remembering to make it so. A
 * `[lines]` that expanded to **HTML** would arrive after that decision had been
 * made and hand raw markup to the sanitizer as its only defence — and it would
 * have no sensible plain-text form, so the text alternative §5.13 gets for free
 * would quietly become a table's worth of nothing.
 *
 * A pipe table keeps both. Values still enter as source and are still escaped by
 * the parser, and the text part is still the thing somebody would read. The one
 * cost is that a cell containing the table's own punctuation has to be escaped,
 * which is {@see self::cell()} and is one small solvable problem rather than a
 * class of them.
 *
 * ### The grammar is the document's own, not a second one
 *
 * `DocumentMarkers::collectionKey()` already writes `collection[:kind].field`.
 * This reads the same production with the field part allowed to be *absent* or
 * to be a *list*:
 *
 * - `[lines]` — every row, in the columns {@see self::columnsOf} chooses;
 * - `[lines:article]` — only the rows of that kind;
 * - `[lines.description,line_total]` — those columns, in that order;
 * - `[lines:article.description,line_total]` — both.
 *
 * Overloading the colon to mean "columns" was the other candidate and was
 * rejected on exactly this: the colon already means "of this kind" one screen
 * away, and `[lines:article]` would then have had two readings depending on
 * whether the tenant happened to have a field called `article`. Extending an
 * existing production costs a reader nothing; giving a separator a second
 * meaning costs them the first one.
 *
 * The happy consequence is that **every collection token from the document
 * reference list means something here**: `[lines.description]` pasted out of a
 * .docx into an email body is a one-column table rather than the blank it used
 * to be.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class CollectionTables
{
    /**
     * `lines`, `lines:article`, `lines.a,b`, `lines:article.a,b`.
     *
     * Anchored, because it is applied to one token at a time — the same shape
     * `RepeatingBlocks`' own marker pattern has, for the same reason.
     */
    private const string MARKER = '/^([a-z0-9_]+)(?::([a-z0-9_]+))?(?:\.([a-z0-9_]+(?:\s*,\s*[a-z0-9_]+)*))?$/i';

    public function __construct(
        private RecordRepository $records,
        private DocumentMarkers $markers,
        // The rows are rendered exactly as the record page renders them, so they
        // are read ahead here too (XIV-54) — the same call `RepeatingBlocks`
        // makes, for the same reason. An order line naming an article is one
        // query per line without it, and a preview is a page somebody is waiting
        // on.
        private RecordPrimer $primer,
    ) {
    }

    /**
     * What one marker is worth, as Markdown, or null when it names no collection
     * of this module.
     *
     * Null and the empty string are different answers and both callers need the
     * difference. **Null** means "this is not a collection marker at all", which
     * leaves the substitution free to try the flat vocabulary and, failing that,
     * to leave the brackets alone — XIV-25's rule that a token nobody recognises
     * is printed as it was typed, because it is probably a typo somebody needs
     * to see. **The empty string** means "this is a collection marker and it
     * draws nothing", which is §5.11's call for an empty collection carried over
     * unchanged: nothing at all rather than a header with no rows under it.
     *
     * @param Record|null $record the parent whose rows are drawn, or null where
     *                            there is nowhere to draw a table — see
     *                            {@see EmailRenderer} on the subject line
     */
    public function render(ModuleDefinition $module, ?Record $record, string $key): ?string
    {
        $marker = self::parse($module, $key);

        if ($marker === null) {
            return null;
        }

        [$collection, $kind, $named] = $marker;

        if ($record === null || $record->id === null) {
            return '';
        }

        $columns = self::columnsOf($collection, $kind, $named);

        if ($columns === []) {
            return '';
        }

        $rows = $this->records->findChildren($collection, $record->id);
        $this->primer->prime($collection, $rows);

        $body = '';

        foreach ($rows as $row) {
            // A named kind draws only its own rows; a marker naming none draws
            // every one of them, in the order the collection holds them. That is
            // §5.11's rule about which rows a block is for, arrived at from the
            // other side — there, the template decides by laying out a row per
            // kind, and here there are no rows to lay out.
            if ($kind !== null && $collection->variantOf($row->data) !== $kind) {
                continue;
            }

            $body .= $this->line($collection, $columns, $row);
        }

        if ($body === '') {
            return '';
        }

        return $this->heading($columns) . $body;
    }

    /**
     * The tokens the placeholder panel offers for one collection (XIV-62).
     *
     * The panel is half the reason the write page is worth opening, and until
     * now it offered nothing at all for a collection — honestly, because a
     * collection token came out blank. Now that one produces a table it has to
     * be listed, and it has to be listed with what it *does*: `[lines]` next to
     * `[first_name]` with nothing to tell them apart is a token somebody pastes
     * into the middle of a sentence, which is the mistake XIV-89's picture badge
     * exists to prevent one row further down the same list.
     *
     * The per-kind forms come after the whole-collection one, because the
     * whole-collection one is what almost everybody wants and the kinds are the
     * escape hatch. There is deliberately no entry per *column*: the named-column
     * form is a shape rather than a token, so the page prints one worked example
     * of it instead of a list nobody could use as a list.
     *
     * @return list<DocumentMarker>
     */
    public function markersFor(CollectionDefinition $collection): array
    {
        $markers = [new DocumentMarker($collection->getKey(), $collection->getLabel())];

        foreach ($collection->getVariants() as $variant => $label) {
            $markers[] = new DocumentMarker(sprintf('%s:%s', $collection->getKey(), $variant), $label);
        }

        return $markers;
    }

    /**
     * The columns `[lines]` draws when nobody names any, as a worked example for
     * the panel — `lines.description,line_total`.
     *
     * Built from the same method the renderer uses, so the example on the screen
     * cannot drift from what typing it produces. That is §5.7's rule about the
     * reference list and the substitution being one piece of code, applied to
     * the one part of this vocabulary a list cannot enumerate.
     */
    public function exampleFor(CollectionDefinition $collection): string
    {
        $columns = self::columnsOf($collection, null, null);

        return sprintf(
            '%s.%s',
            $collection->getKey(),
            implode(',', array_map(static fn (FieldDefinition $f): string => $f->getKey(), $columns)),
        );
    }

    /**
     * Which collection a marker names, which kind of row, and which columns.
     *
     * @return array{0: CollectionDefinition, 1: string|null, 2: list<string>|null}|null
     */
    private static function parse(ModuleDefinition $module, string $key): ?array
    {
        if (preg_match(self::MARKER, $key, $match) !== 1) {
            return null;
        }

        $collection = $module->getCollection($match[1]);

        if ($collection === null) {
            return null;
        }

        $kind = ($match[2] ?? '') === '' ? null : $match[2];
        $named = ($match[3] ?? '') === ''
            ? null
            : array_values(array_map(trim(...), explode(',', $match[3])));

        return [$collection, $kind, $named];
    }

    /**
     * Which fields become columns, and the whole decision about kinds.
     *
     * **Named columns win outright**, in the order they were named, and a name
     * nothing answers is dropped rather than printed as an empty column: the
     * panel lists the real ones, so a name that matches nothing is a typo, and a
     * column of blanks is the least legible way to report one.
     *
     * **A marker naming a kind gets that kind's own fields**, which is what
     * `getFieldsFor()` already means everywhere else.
     *
     * **A marker naming no kind gets the union**, every field of the collection
     * in the customer's own order, and a row whose kind does not carry one of
     * them leaves that cell empty. The three candidates were the union, one
     * table per kind, and the default kind alone, and the other two are worse
     * for reasons the document side already found:
     *
     * - **one table per kind** sorts the invoice by kind, and a comment line
     *   sits *between* two article lines (XIV-21). §5.11 rejected exactly this
     *   when it made consecutive blocks a group;
     * - **the default kind alone** sends an email that lists four of six lines
     *   and says nothing about the other two, which is the only option here that
     *   can be *wrong* rather than merely plain.
     *
     * The union costs an empty cell where a comment line meets the money
     * columns, and that is what a printed invoice looks like anyway. There is no
     * layout here to protect, which is precisely the difference from Word that
     * made §5.11 push kinds back to the template.
     *
     * Two fields are left out of the union, and neither is a guess about what
     * matters:
     *
     * - **the field that says which kind a row is.** It is the discriminator,
     *   not a column — §5.1 has it travelling hidden rather than as a select for
     *   the same reason — and a column reading "Comment, Article, Article" is
     *   noise beside rows that already look different.
     * - **a field another field inherits from** (XIV-18). An order line's
     *   `description` is copied out of `article`, so printing both prints the
     *   same words twice under two headings.
     *
     * **Nothing is capped.** A cap would be the engine guessing which of
     * somebody's fields matter, and being wrong about it drops the total off the
     * end of the table without saying so. Naming the columns is one line and the
     * panel shows the form, which is the honest place for that decision to be
     * made.
     *
     * @param list<string>|null $named
     *
     * @return list<FieldDefinition>
     */
    private static function columnsOf(CollectionDefinition $collection, ?string $kind, ?array $named): array
    {
        if ($named !== null) {
            $columns = [];

            foreach ($named as $key) {
                $field = $collection->getField($key);

                if ($field !== null) {
                    $columns[] = $field;
                }
            }

            return $columns;
        }

        $inherited = [];

        foreach ($collection->getFields() as $field) {
            $from = InheritedValue::of($field);

            if ($from !== null) {
                $inherited[$from->reference] = true;
            }
        }

        $fields = $kind === null
            ? array_values($collection->getFields()->toArray())
            : $collection->getFieldsFor($kind);

        $columns = [];

        foreach ($fields as $field) {
            if ($field->getKey() === $collection->getVariantField() || isset($inherited[$field->getKey()])) {
                continue;
            }

            $columns[] = $field;
        }

        return $columns;
    }

    /**
     * The header row and the delimiter under it.
     *
     * The **field's own label**, never translated, which is the rule
     * {@see DocumentMarkers::forShape} states and the reason for it holds here
     * too: the customer may have renamed the field, and overruling that would
     * make the rename screen a lie (§5.4).
     *
     * No alignment is declared — every column is `---` rather than `---:` for
     * the numbers. Declaring it would mean this class holding a list of which
     * field types count as numeric, which is a second place to say what a field
     * type already knows, and the two would eventually disagree. A money column
     * that reads left-aligned in a summary is a smaller wrongness than that.
     *
     * @param list<FieldDefinition> $columns
     */
    private function heading(array $columns): string
    {
        $labels = [];
        $rule = [];

        foreach ($columns as $field) {
            $labels[] = self::cell($field->getLabel());
            $rule[] = '---';
        }

        return self::row($labels) . self::row($rule);
    }

    /**
     * One row of the collection, as one row of the table.
     *
     * `valuesOf()` is the document side's own method and renders **every** field
     * of the collection through its field type, including the ones this row's
     * kind does not carry — which come back as the empty string and are exactly
     * the empty cells the union above is about. Reusing it rather than reading
     * the row here is what keeps a price reading "CHF 19.90" in an email for the
     * same reason it does in a document (§5.7).
     *
     * @param list<FieldDefinition> $columns
     */
    private function line(CollectionDefinition $collection, array $columns, Record $row): string
    {
        $values = $this->markers->valuesOf($collection, $row);
        $cells = [];

        foreach ($columns as $field) {
            $cells[] = self::cell($values[$field->getKey()] ?? '');
        }

        return self::row($cells);
    }

    /** @param list<string> $cells */
    private static function row(array $cells): string
    {
        return '| ' . implode(' | ', $cells) . " |\n";
    }

    /**
     * One value, made safe to sit inside a pipe table.
     *
     * This is the whole price of choosing Markdown over HTML, and it is worth
     * being explicit about how small it is.
     *
     * **The backslash first, then the pipe.** Escaping the delimiter with a
     * character that is itself special, and not escaping *that* character, is
     * the classic way to leave a hole in an escaping routine: `a\` followed by
     * `|` would otherwise become `a\\|`, which CommonMark's table parser reads
     * as a backslash and then a cell boundary.
     *
     * **A newline becomes a space.** A pipe table's row *is* a line, so a
     * textarea value carrying one would end the row half way through and turn
     * the rest of the record into a paragraph. There is no Markdown for a line
     * break inside a cell — the usual answer is a literal `<br>`, which is the
     * one thing this class must not emit (see the class docblock).
     *
     * **Nothing else is escaped**, and that is §5.13's decision rather than a
     * new one: a value containing `*` or `_` reads as Markdown there and reads
     * as Markdown here, because it is a formatting oddity in one email against
     * the cost of a second, divergent escaping rule for cells. What is escaped
     * here is only what would break the *structure* the reader is looking at.
     */
    private static function cell(string $value): string
    {
        return str_replace(
            ['\\', '|', "\r\n", "\n", "\r"],
            ['\\\\', '\\|', ' ', ' ', ' '],
            $value,
        );
    }
}

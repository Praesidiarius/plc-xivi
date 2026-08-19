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

use Twig\Environment;
use Xivi\Core\Document\DocumentMarkers;
use Xivi\Core\Entity\EmailTemplate;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Markdown\MarkdownRenderer;
use Xivi\Core\Record\Record;

/**
 * One template plus one record makes one email (XIV-38).
 *
 * The counterpart to {@see \Xivi\Core\Document\DocumentGenerator}, and it does
 * the same three things in the same order: work out what every marker is worth,
 * put those values where the template asked for them, and wrap the result in
 * whatever the finished artefact has to be. Only the third differs — a document
 * is wrapped in the stationery somebody uploaded, and an email is wrapped in a
 * skeleton that ships in code (§5.13).
 *
 * ### Markers are `DocumentMarkers`, not a second list
 *
 * The same class, the same keys, the same values, rendered through the same
 * field types — so a field a customer added this morning is a marker in an email
 * this afternoon, and there is no second vocabulary to keep in step with the
 * first. That is the whole reason this class is three short methods rather than
 * a feature: everything hard about markers was solved for documents and is
 * reused rather than re-derived. The class keeps its name too, for the same
 * reason `DocumentContext` does — renaming it to something neutral would be a
 * diff across the engine bought with nothing.
 *
 * A **collection** marker is the one thing that comes out different, and it is
 * deliberate. `RepeatingBlocks` scans `<w:tr>` elements out of Word's XML: the
 * table row is the unit because it is the unit Word gives a person, and Markdown
 * has no equivalent — so XIV-38 left the question open rather than porting an
 * answer that did not fit. XIV-62 answered it, and the answer is
 * {@see CollectionTables}: **one marker that renders the whole collection as a
 * pipe table**, rather than a construct a tenant lays a row out inside. Which is
 * a genuine divergence from the document side and is meant to be — in Word the
 * layout is the deliverable, and in an email it is not.
 *
 * It matters here, in this class, that what a collection expands to is
 * **Markdown**: an expansion to HTML would arrive on the far side of the
 * escaping decision below, hand raw markup to the sanitizer as its only defence,
 * and have no plain-text form worth reading. Expanding to source keeps both
 * properties for free.
 *
 * ### Substitution happens before the Markdown is parsed, and that matters
 *
 * The alternative — parse first, then replace in the HTML — was rejected because
 * it is the unsafe one. A record's values are the customer's *data*, not their
 * template, and dropping them into finished HTML would mean escaping each one
 * by hand at exactly the moment the code has stopped thinking about escaping.
 * Substituting first means every value goes through CommonMark's own escaping
 * on its way out, so a contact whose company name is `<script>` becomes text
 * rather than markup without anybody remembering to make it so.
 *
 * The price is real and small: a value containing `*` or `_` can be read as
 * Markdown. That is a formatting oddity in one email; the other way round is a
 * script tag in every one.
 *
 * ### Raw HTML is disabled, *and* the output is sanitized — elsewhere now
 *
 * The ticket asked for one of the two and this class did both, in that order,
 * because they defend against different things. It configured the converter in
 * its own constructor for as long as it was the only thing that had one.
 *
 * **XIV-131 moved that configuration into {@see MarkdownRenderer}**, because a
 * field that holds formatted text is the second caller and two converters with
 * two configurations is how one of them ends up unescaped. Nothing about the
 * policy changed and nothing about this class's guarantees did: markers are
 * still substituted into the *source*, the source is still parsed with raw HTML
 * escaped, and the result is still sanitized before it reaches the wrapper. The
 * argument for each of those is now in that class, where the record page can
 * read it too.
 *
 * What is worth keeping here is the consequence rather than the mechanism: a
 * change to what an email is permitted to contain can no longer apply to email
 * and not to a record page, because there is one object and one policy and both
 * of them are handed out by the container.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class EmailRenderer
{
    /** The one base template, and the argument for there being one is in §5.13. */
    private const string BASE = '@XiviCore/email/base.html.twig';

    public function __construct(
        private DocumentMarkers $markers,
        private CollectionTables $tables,
        private MarkdownRenderer $markdown,
        private Environment $twig,
    ) {
    }

    /**
     * The finished email: subject, HTML and the plain-text alternative.
     *
     * @param string|null $subject what to use instead of the template's own, for
     *                             the send where somebody typed a different one
     *                             (XIV-39). Markers are substituted into it
     *                             exactly as they are into the template's, so a
     *                             subject typed by hand is not a subject that
     *                             stopped understanding `[record_id]`.
     */
    public function render(
        EmailTemplate $template,
        ModuleDefinition $module,
        Record $record,
        ?string $subject = null,
    ): RenderedEmail {
        $values = $this->markers->dataFor($module, $record);

        // The subject is given no record to draw tables from, and that is the
        // argument rather than an omission: a subject line is one line of text,
        // and a table in one is not a thing anybody means. A collection marker
        // written there is still *recognised* — it comes out blank, which is the
        // rule every unfilled marker gets and the rule `dataFor()` already
        // applies to the `[lines.description]` form.
        $line = $this->substitute($subject ?? $template->getSubject(), $values, $module, null);
        $text = $this->substitute($template->getBody(), $values, $module, $record);

        return new RenderedEmail($line, $this->wrap($text, $line), $text);
    }

    /**
     * The content, as the HTML document that actually leaves the building.
     *
     * Markdown to HTML, sanitized, and then handed to the base template — which
     * is what supplies the skeleton, the sender block and the footer around it.
     * A tenant writes the content part and never this (§5.13, §6.1).
     */
    private function wrap(string $markdown, string $subject): string
    {
        $content = $this->markdown->toHtml($markdown);

        return $this->twig->render(self::BASE, [
            'subject' => $subject,
            'content' => $content,
            // The markers that are about the moment rather than the record
            // (§5.7), as a plain map, so the footer can name the company sending
            // this without core learning what a tenant is. Passed whole rather
            // than as a `tenant` argument for the reason `DocumentContext`
            // answers with markers instead of values: a general marker added
            // later needs no change here.
            'general' => self::valuesOf($this->markers->general()),
        ]);
    }

    /**
     * `[first_name]` becomes what this record's first name is, and `[lines]`
     * becomes a table.
     *
     * **One left-to-right pass over the text, and nothing is ever looked at
     * twice.** That was `strtr()`'s whole justification and it is this method's:
     * a scan that never re-reads what it has written is what stops a contact
     * whose company name happens to contain `[today]` from having it
     * substituted. `preg_replace_callback` keeps that property exactly —
     * scanning resumes *after* each replacement — and buys the thing `strtr()`
     * could not do, which is to decide per token what kind of marker it is
     * rather than looking it up in one flat map (XIV-62).
     *
     * Three answers, in this order:
     *
     * 1. **a collection**, which renders as a table and is asked first. It has
     *    to be first: `dataFor()` blanks every `[lines.description]` for the
     *    document side's benefit, so consulting the map first would blank the
     *    very tokens this ticket exists to fill in.
     * 2. **a marker the shape offers**, which is its value — including the empty
     *    string, because a marker the engine knows and cannot fill is blanked
     *    rather than left printing its own brackets (§5.7).
     * 3. **anything else**, left exactly as it was typed. That is XIV-25's rule
     *    and it is why a Markdown link's `[text]` survives this untouched: a
     *    token nobody recognises is far more likely to be a typo somebody needs
     *    to see than a marker somebody meant.
     *
     * @param array<string, string> $values every marker key this shape offers
     * @param Record|null           $record null where a table cannot be drawn
     */
    private function substitute(string $text, array $values, ModuleDefinition $module, ?Record $record): string
    {
        return (string) preg_replace_callback(
            // Deliberately not `.+`: a marker never spans a line, and letting one
            // do so would let a stray bracket at the top of a message swallow
            // everything down to the next one.
            '/\[([^\[\]\r\n]++)\]/',
            function (array $match) use ($values, $module, $record, $text): string {
                [$token, $offset] = $match[0];
                $key = $match[1][0];

                $table = $this->tables->render($module, $record, $key);

                if ($table !== null) {
                    return $table === '' ? '' : self::spaced($text, $offset, \strlen($token), $table);
                }

                return $values[$key] ?? $token;
            },
            $text,
            flags: \PREG_OFFSET_CAPTURE,
        );
    }

    /**
     * A table, with whatever blank lines it needs around it and no more.
     *
     * A pipe table is a *block*, so it needs a blank line on each side or
     * CommonMark reads it as more of the paragraph it interrupts — and somebody
     * writing "Here is what you ordered:" and then `[lines]` on the next line has
     * written exactly that paragraph. Padding unconditionally would have been one
     * line of code and would have left a stray blank line in the plain-text half
     * every time the marker already stood alone, which is the half a person
     * actually reads.
     *
     * So the source is measured instead. The offsets are into the original text
     * rather than the result, which is the right question: what is being asked is
     * whether the *author* left a blank line there, not what the expansion of
     * some earlier marker happened to end with.
     */
    private static function spaced(string $text, int $offset, int $length, string $table): string
    {
        $before = rtrim(substr($text, 0, $offset), " \t");
        $after = ltrim(substr($text, $offset + $length), " \t");

        return self::gap($before, true) . rtrim($table, "\n") . self::gap($after, false);
    }

    /**
     * How many newlines are missing between the table and the text on one side
     * of it: none when that side is empty or already ends in a blank line, one
     * when it ends in a single newline, two otherwise.
     */
    private static function gap(string $text, bool $preceding): string
    {
        if ($text === '') {
            return '';
        }

        // The two characters the table is about to touch, read outwards from the
        // marker: the last two before it, or the first two after it.
        $touching = $preceding ? substr($text, -2) : substr($text, 0, 2);
        $nearest = $preceding ? substr($touching, -1) : substr($touching, 0, 1);

        if ($touching === "\n\n") {
            return '';
        }

        return $nearest === "\n" ? "\n" : "\n\n";
    }

    /**
     * @param list<\Xivi\Core\Document\DocumentMarker> $markers
     *
     * @return array<string, string>
     */
    private static function valuesOf(array $markers): array
    {
        $values = [];

        foreach ($markers as $marker) {
            $values[$marker->key] = $marker->example ?? '';
        }

        return $values;
    }
}

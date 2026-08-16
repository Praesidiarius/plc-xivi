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

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\ConverterInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Twig\Environment;
use Xivi\Core\Document\DocumentMarkers;
use Xivi\Core\Entity\EmailTemplate;
use Xivi\Core\Entity\ModuleDefinition;
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
 * has no equivalent. Choosing one — a list item? a table row? a fenced block? —
 * is a design question rather than a port, so it is not answered here (XIV-38).
 * What a collection marker does in the meantime is what any unfilled marker
 * does: it comes out blank, because `dataFor()` already offers every collection
 * key as an empty string. Blank beats brackets, which is the same call the
 * document side made.
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
 * ### Raw HTML is disabled, *and* the output is sanitized
 *
 * The ticket asked for one of the two and this does both, in that order, because
 * they defend against different things.
 *
 * **Disabled** (`html_input: escape`) is the primary decision. CommonMark passes
 * raw HTML through by default, which would make the paragraph above false: a
 * marker's value is substituted into the Markdown source, so anything the parser
 * lets through is a route from a customer's *record* into the markup of an
 * email. Escaping closes that route at the point where the distinction between
 * "text somebody typed" and "markup" is still known.
 *
 * **Sanitized** is the second layer, and it is not ceremony. CommonMark emits
 * markup of its own from perfectly ordinary Markdown — links, above all — and
 * `[click](javascript:…)` is a link somebody can type without any raw HTML being
 * involved. `allow_unsafe_links: false` refuses the obvious ones and the
 * sanitizer is what enforces the allowed elements, attributes and URL schemes as
 * a policy rather than as a parser setting. It is also what keeps this class
 * honest if raw HTML is ever turned back on for a reason nobody has thought of
 * yet. The policy itself is Symfony's, configured in
 * config/packages/html_sanitizer.yaml, because a hand-rolled allow-list is the
 * kind of thing this project takes from the framework rather than writes.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class EmailRenderer
{
    /** The one base template, and the argument for there being one is in §5.13. */
    private const string BASE = '@XiviCore/email/base.html.twig';

    private ConverterInterface $markdown;

    public function __construct(
        private DocumentMarkers $markers,
        private HtmlSanitizerInterface $sanitizer,
        private Environment $twig,
    ) {
        $this->markdown = new CommonMarkConverter([
            // See the class docblock: this is the primary half of the answer to
            // "sanitize or disable raw HTML", and the half that makes marker
            // substitution into the source safe rather than merely tidy.
            'html_input' => 'escape',
            // `[click me](javascript:alert(1))` needs no raw HTML at all, so the
            // setting above would not have caught it.
            'allow_unsafe_links' => false,
        ]);
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

        $line = self::substitute($subject ?? $template->getSubject(), $values);
        $text = self::substitute($template->getBody(), $values);

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
        $content = $this->sanitizer->sanitize($this->markdown->convert($markdown)->getContent());

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
     * `[first_name]` becomes what this record's first name is.
     *
     * `strtr()` rather than a loop of `str_replace()`, and that is not a
     * micro-optimisation: `strtr()` scans the subject once and never looks at
     * text it has already written, so a value that happens to contain `[today]`
     * is left alone. A loop would substitute into its own output.
     *
     * @param array<string, string> $values every marker key this shape offers
     */
    private static function substitute(string $text, array $values): string
    {
        $tokens = [];

        foreach ($values as $key => $value) {
            $tokens['[' . $key . ']'] = $value;
        }

        return strtr($text, $tokens);
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

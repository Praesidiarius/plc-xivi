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

namespace Xivi\Core\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The one place that decides what Markdown is allowed to become (XIV-131).
 *
 * ### Why this class exists at all
 *
 * Everything in it was written for email and lived in the constructor of
 * {@see \Xivi\Core\Mail\EmailRenderer} (XIV-38, XIV-62), where it had exactly
 * one caller and no reason to move. A field that holds formatted text is the
 * second caller, and a second caller is the moment a private constructor body
 * becomes a policy: two `MarkdownConverter` instances built from two
 * `Environment`s is the arrangement in which somebody tightens what a link may
 * point at, tightens it in one of them, and leaves the other open for a year
 * without anything going red. So the configuration is hoisted here whole and
 * both callers are handed the same object, which makes "the email pipeline and
 * the record page cannot disagree" a fact about the container rather than a
 * promise in a comment.
 *
 * Nothing about the policy changed on the way across. The argument for each part
 * of it is §5.13's, and it is repeated here because this is now where a reader
 * looking for it will arrive.
 *
 * ### Raw HTML is escaped, *and* the output is sanitized
 *
 * Two layers, defending against two different things, in this order.
 *
 * **`html_input: escape` is the primary decision, and it is not about the person
 * writing the Markdown.** It is about the *values*. An email template's markers
 * are filled in on the Markdown **source**, before any of it is parsed; a
 * record's formatted field is a value a colleague typed and is parsed directly.
 * Both are text somebody entered into this application, and in both cases the
 * parser is the last point at which "text somebody typed" and "markup" are still
 * distinguishable to the code. Escaping there means a value containing
 * `<script>` becomes a paragraph that reads `<script>`, and it means it
 * **without anybody remembering to make it so** — which is the property worth
 * defending, because remembering is the thing that fails.
 *
 * The price is that a value containing `*` or `_` can read as Markdown. For a
 * field whose entire purpose is that it reads as Markdown that is not a price at
 * all; for a marker's value in an email it is a formatting oddity in one
 * message, weighed against a script tag in every one.
 *
 * **The sanitizer is the second layer and is not ceremony.** CommonMark emits
 * markup of its own from perfectly ordinary Markdown — links above all — and
 * `[click](javascript:…)` needs no raw HTML whatsoever. `allow_unsafe_links`
 * refuses the obvious ones at parse time, and the sanitizer is what makes the
 * permitted elements, attributes and URL schemes a *policy*, expressed in
 * Symfony's own component and Symfony's own configuration rather than in an
 * allow-list written by hand here.
 *
 * ### One policy, and it is deliberately the strictest caller's
 *
 * The policy this is given is the one XIV-38 wrote for email, renamed rather
 * than duplicated: config/packages/html_sanitizer.yaml. Two of its rules are
 * strictly about email — relative links are dropped because a message has no
 * base URL to resolve them against, and `data:` media are dropped because a data
 * URI is how an image is smuggled past a mail client's remote-content warning.
 * Neither hurts a record page. A relative link typed into a record would resolve
 * against whatever URL that record happens to be shown at, which is not
 * something anybody means, and an image in a record field is [XIV-115]'s
 * question rather than a hole to leave open in the meantime.
 *
 * So the shared policy is the intersection of what the callers need rather than
 * the union, and that direction is the point. A policy that relaxes for the
 * newer caller is two policies with one name, which is precisely the failure
 * this class was extracted to prevent.
 *
 * ### The grammar is small on purpose
 *
 * `CommonMarkCoreExtension` and `TableExtension`, named individually rather than
 * taken as `GithubFlavoredMarkdownExtension` — which would also bring
 * autolinking, strikethrough, task lists and a raw-HTML filter nothing has asked
 * for. The smaller the grammar somebody writes against, the fewer ways their
 * text surprises them, and every addition to it is a new shape of markup the
 * sanitizer's policy would have to have an opinion about.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class MarkdownRenderer
{
    private Environment $environment;

    private MarkdownConverter $converter;

    public function __construct(private HtmlSanitizerInterface $sanitizer)
    {
        $this->environment = new Environment([
            // See the class docblock: the primary half of the answer to
            // "sanitize or disable raw HTML", and the half that makes a stored
            // value safe rather than merely tidy.
            'html_input' => 'escape',
            // `[click me](javascript:alert(1))` needs no raw HTML at all, so the
            // setting above would not have caught it.
            'allow_unsafe_links' => false,
        ]);

        $this->environment->addExtension(new CommonMarkCoreExtension());
        $this->environment->addExtension(new TableExtension());

        $this->converter = new MarkdownConverter($this->environment);
    }

    /**
     * Markdown as the HTML a page may print: escaped at parse time, then
     * sanitized.
     *
     * This is the only method in the engine that hands back markup built out of
     * something a customer typed, so it is the one place where both layers above
     * have to be in force — which is why they are applied together here rather
     * than left to the caller to compose in the right order. A caller that could
     * ask for the unsanitized half would eventually be a caller that did.
     */
    public function toHtml(string $markdown): string
    {
        return $this->sanitizer->sanitize($this->converter->convert($markdown)->getContent());
    }

    /**
     * The same text with the marks taken off: the words, and none of the
     * punctuation that was holding them together.
     *
     * **This is what a spreadsheet cell, a table cell and a Word document get**,
     * and the argument is in §5.21. A .docx is not HTML, so the formatting
     * cannot survive the trip; the choice is therefore between printing
     * `**Warning:** do not…` on a customer's invoice, which prints punctuation
     * nobody meant to send, and printing `Warning: do not…`, which loses
     * emphasis the medium could not have carried anyway. The second is the
     * smaller loss, and the same answer serves a list column, where a cell
     * reading `**bold**` is worse than one reading `bold` for exactly the same
     * reason.
     *
     * **It asks the parser rather than the string.** Stripping `*` and `#` with
     * a regular expression would be a second and worse implementation of the
     * grammar that is already in the room, and it would disagree with
     * `toHtml()` the first time somebody typed a literal asterisk.
     *
     * **It is not "the HTML with the tags taken out", and that distinction is
     * load-bearing.** Stripping tags out of finished markup would mean
     * un-escaping entities afterwards to get readable text back, and a pipeline
     * that escapes and then un-escapes is one refactor away from handing markup
     * to a caller that trusted it. Reading literals off the parsed document
     * never builds the markup in the first place. Raw HTML somebody typed comes
     * back as the text it is — which is exactly what `toHtml()` shows too,
     * because the parser was told to escape it — so the two halves of this class
     * agree about what a value says.
     */
    public function toText(string $markdown): string
    {
        $parser = new MarkdownParser($this->environment);
        $text = '';

        foreach ($parser->parse($markdown)->iterator() as $node) {
            // A block boundary is a word boundary. Two paragraphs, two list
            // items and two table cells would otherwise run into each other —
            // "first paragraphsecond paragraph" — and a space is the separator
            // that is right for all of them. The document node itself is skipped
            // so the result does not begin with one.
            if (($node instanceof AbstractBlock && !$node instanceof Document) || $node instanceof Newline) {
                $text .= ' ';
            }

            // Deliberately *not* an `elseif`: a fenced code block and a raw HTML
            // block are each a block *and* a string container, so a chain would
            // have counted the boundary and then thrown the contents away.
            if ($node instanceof StringContainerInterface) {
                $text .= $node->getLiteral();
            }
        }

        // The line breaks the author typed are meaningless by now — the block
        // structure they described has already been read and turned into the
        // spaces above — so what is left is collapsed to one space between
        // words, because this is going into a cell.
        return trim((string) preg_replace('/\s++/u', ' ', $text));
    }
}

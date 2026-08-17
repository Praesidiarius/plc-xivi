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

namespace Xivi\Core\Document;

use Xivi\Core\Entity\DocumentTemplate;
use Xivi\Core\Entity\ModuleDefinition;

/**
 * Which placeholders in a template nothing is going to fill (XIV-25).
 *
 * **The bug this exists for, because it is worth having written down.** An order
 * template printed `[contacŧ]` into a finished document instead of the
 * customer's name. The last character was U+0167 — a `t` with a stroke, which on
 * a Swiss keyboard is AltGr and the key next to it — and at body-text size it is
 * indistinguishable from the letter it is not. Nothing is called `contacŧ`, so
 * the generator correctly left the text alone and the letter went out with a
 * bracket in it.
 *
 * **The behaviour was right and the silence was not.** Printing an unknown
 * marker as it stands is the correct choice: blanking it would swallow the
 * mistake, and §5.7 already fills every marker it *knows* with the empty string
 * precisely so that nothing prints its own brackets by accident. What was
 * missing is that nobody was told. A bracket in a finished PDF has two readings
 * — "the engine failed to replace it" and "you typed something else" — which
 * look identical on the page, and the first is where everybody starts.
 *
 * **So this reports and never refuses.** Square brackets in a letter are legal
 * prose, a customer may be halfway through writing a template, and a template
 * with a token nobody recognises is still a template somebody may have meant.
 * Refusing the upload would trade a silent wrongness for a loud one. Nothing
 * here throws, nothing here changes the document, and the wording that carries
 * the answer says what will *happen* to the text rather than what is wrong with
 * it.
 *
 * **It is a comparison and not a parser.** Both halves already existed: the
 * tokens come from {@see TemplateTokens}, which is the split-tolerant scan the
 * generator's own repeating blocks use, and the vocabulary from
 * {@see DocumentMarkers::keysFor()}, which is the same list the reference panel
 * on the upload page prints. That is the whole of it, and it is the reason this
 * class can be short — a second scanner or a second vocabulary would each be a
 * thing to keep in step with the generator, and the first time one drifted the
 * report would start lying in one direction or the other.
 *
 * **Unused markers are deliberately not reported**, which the ticket left open.
 * A template that never mentions its record may well be a mistake, but "you did
 * not use `[status]`" belongs on every upload of every template and is therefore
 * noise nobody reads twice — and a reader who has learned to skip this line
 * skips the unknown tokens with it. The two are not the same kind of fact: an
 * unknown token has a wrong answer behind it and somebody wants to know, an
 * unused one is a preference about a letter. If it is ever wanted it wants a
 * quieter place than this.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TemplateReview
{
    public function __construct(
        private DocumentMarkers $markers,
    ) {
    }

    /**
     * The tokens in this template that no marker answers, brackets and all.
     *
     * Works on an uploaded template exactly as it works on one being uploaded,
     * which is the point rather than a convenience: a template written against a
     * field that has since been renamed goes stale without anybody touching the
     * file, and the moment of upload is the one moment that will never come
     * round again for it.
     *
     * Exact keys, not a fuzzy match. What decides whether a marker is filled is
     * an array lookup in {@see DocumentMarkers::dataFor()}, so `[ first_name ]`
     * and `[First_Name]` are as unfilled as `[contacŧ]` is and are reported the
     * same way. Guessing at a near miss — "did you mean `[contact]`?" — would be
     * this class deciding what somebody meant to type, and it is wrong about
     * `[see appendix]` the moment it tries.
     *
     * @return list<string>
     */
    public function unknownIn(DocumentTemplate $template, ModuleDefinition $module): array
    {
        $known = array_fill_keys($this->markers->keysFor($module), true);
        $unknown = [];

        foreach (TemplateTokens::inDocument($template->getContent()) as $token) {
            if (!isset($known[substr($token, 1, -1)])) {
                $unknown[] = $token;
            }
        }

        return $unknown;
    }
}

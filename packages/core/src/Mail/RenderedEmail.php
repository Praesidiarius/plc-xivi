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

/**
 * One template, filled in for one record, ready to be sent or shown (XIV-38).
 *
 * Three strings and no behaviour, because the two things that want it want
 * different halves: XIV-39's preview draws the HTML on a page, and its send puts
 * all three into a message. Handing back a `Symfony\Component\Mime\Email` here
 * instead was the obvious alternative and was rejected on the boundary — core
 * would then be deciding who the message is from and who it goes to, and it
 * knows neither. Those are the application's facts (§5, and XIV-37's whole
 * subject), so core answers with the contents and stops.
 *
 * **Both parts, always.** A well-formed email carries a plain-text alternative
 * beside its HTML, and here the thing somebody typed *is* that alternative, near
 * enough — the Markdown source with the same markers filled in. Nothing has to
 * generate it by stripping tags out of the HTML, which is the step that quietly
 * produces a text part nobody would want to read.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RenderedEmail
{
    public function __construct(
        /** The subject line, markers substituted. Never Markdown: a subject is one line of text. */
        public string $subject,
        /** The whole HTML document — the content inside the base template (§5.13). */
        public string $html,
        /** The Markdown source with the same markers substituted, as the text alternative. */
        public string $text,
    ) {
    }
}

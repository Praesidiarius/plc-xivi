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
use Xivi\Core\Markdown\MarkdownRenderer;

/**
 * `markdown(source)` in a template (XIV-131).
 *
 * One function, with one caller: the preview under a formatted field's textarea,
 * which has a string and no field definition to ask about it. Everything that
 * *does* have a definition goes through `formatted()` on
 * {@see FieldDisplayExtension} instead, so that the question "is this field
 * formatted" is asked of the field type rather than guessed at by whoever is
 * writing the template.
 *
 * **It hands back a {@see Markup}, which is the whole reason it is a function
 * rather than a filter used with `|raw`.** `|raw` at a call site is a decision a
 * template author makes, and it looks identical whether what precedes it went
 * through the sanitizer or came straight out of a database column. A `Markup`
 * moves the decision to the one place that knows the answer: the escaping and
 * the sanitizing both happened inside {@see MarkdownRenderer::toHtml()}, and
 * nothing else in this application may claim the same. A grep for `|raw` over
 * the templates should keep finding only sentences out of our own catalogues.
 *
 * `Markup` rather than the `is_safe` option, which would do the same job at the
 * point of printing and only there: a template that assigns the result to a
 * variable first — which is what a page with a null check in it has to do —
 * loses the marking on the way and quietly escapes the markup back into visible
 * angle brackets. The object carries its own safety wherever it goes.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MarkdownExtension extends AbstractExtension
{
    public function __construct(private readonly MarkdownRenderer $markdown)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('markdown', $this->markdown(...)),
        ];
    }

    /**
     * Markdown source as the HTML a page may print.
     *
     * Null and the empty string both give back nothing rather than an empty
     * paragraph, because the callers are drawing a block that should not be
     * there at all when there is nothing in it.
     */
    public function markdown(?string $source): Markup
    {
        $html = $source === null || trim($source) === '' ? '' : $this->markdown->toHtml($source);

        // UTF-8 named rather than taken from the environment: this project has
        // exactly one charset, it is set in the Twig configuration the framework
        // ships, and threading it through so that a hypothetical second one
        // would work would be plumbing for a case that cannot arise.
        return new Markup($html, 'UTF-8');
    }
}

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

namespace App\Tests\Unit\Markdown;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Xivi\Core\Markdown\MarkdownRenderer;

/**
 * What a document, an export and a list cell are given (XIV-131).
 *
 * §5.21 decides that all three get *the words with the marks taken off* rather
 * than the source or the markup, and that decision is arithmetic-shaped: it is a
 * rule about strings, it has edges, and every one of its edges is somewhere a
 * regular expression would have been wrong. So it gets a unit test of its own
 * rather than being asserted in passing by the page that happens to use it.
 *
 * **The sanitizer here refuses to do anything, and that is an assertion in
 * disguise.** `toText()` never builds markup — it reads literals off the parsed
 * document — so it must never reach the sanitizer at all. A future change that
 * made it render to HTML and strip the tags out would have to un-escape entities
 * afterwards to get readable text back, which is the pipeline §5.21 refused; it
 * would also fail here, loudly, on the first test that contains a `<`.
 *
 * A unit test because none of this needs a kernel or a database. The *policy*
 * half — what the sanitizer keeps — is proven through the real container by
 * `FormattedFieldTest`, which is the only place it can honestly be proven.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MarkdownTextTest extends TestCase
{
    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer(new class implements HtmlSanitizerInterface {
            public function sanitize(string $input): string
            {
                throw new \LogicException('toText() reached the sanitizer, which means it built markup on the way.');
            }

            public function sanitizeFor(string $element, string $input): string
            {
                throw new \LogicException('toText() reached the sanitizer, which means it built markup on the way.');
            }
        });
    }

    /** Emphasis is the words, not the asterisks that made them emphatic. */
    public function testTheMarksComeOff(): void
    {
        self::assertSame('Wear gloves now', $this->renderer->toText('Wear **gloves** _now_'));
    }

    /**
     * A heading is a heading's words, and the next block starts with a space
     * between them rather than running into it.
     *
     * This is the edge that made the boundary rule necessary: without it a
     * two-block value comes out as `Safety firstWear gloves`, which reads as a
     * typo in a spreadsheet cell and is impossible to search for.
     */
    public function testBlocksAreSeparatedByASpace(): void
    {
        self::assertSame('Safety first Wear gloves', $this->renderer->toText("## Safety first\n\nWear **gloves**"));
    }

    /** A list is its items, spaced, for exactly the same reason. */
    public function testListItemsDoNotRunTogether(): void
    {
        self::assertSame('Check the seal Log the reading', $this->renderer->toText("- Check the seal\n- Log the reading"));
    }

    /**
     * A link is the words somebody would read, not the URL behind them.
     *
     * A cell reading `the manual` beside one reading
     * `[the manual](https://example.test/manual)` is not a close call, and the
     * address is still in the source, which is what the export and the filter
     * see.
     */
    public function testALinkIsItsText(): void
    {
        self::assertSame('See the manual', $this->renderer->toText('See [the manual](https://example.test/manual)'));
    }

    /**
     * A table is its cells, in reading order, with the punctuation that held them
     * apart replaced by spaces.
     *
     * Worth asserting because a pipe table is the one construct where the
     * delimiters *are* the structure: getting this wrong would produce either
     * `AliceBob` or a cell full of hyphens from the header rule.
     */
    public function testATableIsItsCells(): void
    {
        $table = <<<'MARKDOWN'
            | Name | Role |
            | --- | --- |
            | Alice | Fitter |
            MARKDOWN;

        self::assertSame('Name Role Alice Fitter', $this->renderer->toText($table));
    }

    /**
     * Raw HTML somebody typed comes back as the text it is.
     *
     * The parser is configured to escape raw HTML, so this is exactly what the
     * rendered half shows too — the two halves of the renderer agree about what
     * a value says, which is what stops a list cell and a record page from
     * telling a reader two different things about the same record. That the
     * sanitizer is never consulted on the way is the point of the object it was
     * given.
     */
    public function testRawHtmlIsTheTextItIs(): void
    {
        self::assertSame(
            'Wear gloves before <script>alert(1)</script> touching it',
            $this->renderer->toText('Wear **gloves** before <script>alert(1)</script> touching it'),
        );
    }

    /** Nothing in, nothing out — not an empty paragraph's worth of whitespace. */
    public function testEmptyStaysEmpty(): void
    {
        self::assertSame('', $this->renderer->toText(''));
        self::assertSame('', $this->renderer->toText("\n\n   \n"));
    }
}

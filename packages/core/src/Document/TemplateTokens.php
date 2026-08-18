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

/**
 * What a .docx actually says between square brackets (XIV-25).
 *
 * **This is a seam, not a new capability.** Reading tokens out of a Word
 * document was already being done twice over — `anourvalar/office` does it to
 * substitute the flat markers, and {@see RepeatingBlocks} does it to find the
 * rows that repeat — and XIV-25 needed it a third time, to say which of them
 * nothing will fill. A third private copy of the trick is how three scanners
 * end up disagreeing about what a marker is, and the disagreement would show up
 * as a report that says a template is fine when the generator is about to leave
 * a bracket in it, or the other way round. So the scan moved here and
 * `RepeatingBlocks` reads it from here; the pattern it applies afterwards, and
 * everything it decides, is untouched.
 *
 * **The whole difficulty is that Word does not keep a word in one piece.** A
 * placeholder somebody typed in one go — `[first_name]` — routinely ends up as
 * `[first_na` in one run and `me]` in the next, because Word splits runs at
 * every spell-check boundary, every proofing-language change and every cursor
 * position it happened to remember. A string search over the XML finds nothing
 * at all in exactly the templates a human wrote by hand, which is every template
 * that matters. Stripping the markup first is what makes the text read the way
 * the person who typed it meant it to, and it is the same technique the library
 * uses on the substitution side (§5.7).
 *
 * **A token is not a marker**, and this class is careful not to decide which is
 * which. It hands back everything in brackets, including `[see appendix]` in the
 * middle of a sentence and including `[contacŧ]`. Whether a token is a marker is
 * a question about the customer's field definitions, which belongs to
 * {@see DocumentMarkers}, and whether a token being unknown is worth saying is
 * {@see TemplateReview}'s. Keeping those apart is what lets the reporting say
 * *what will happen to the text* rather than pass judgement on it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TemplateTokens
{
    /**
     * Anything between square brackets, on one line, of a plausible length.
     *
     * Deliberately not the marker grammar: a report that only found the tokens
     * that already look like markers would find nothing wrong with `[contacŧ]`,
     * which is the bug this exists for. What it does exclude is the two ways a
     * bracket scan produces nonsense once the markup is gone and every paragraph
     * in the document runs into the next one — a stray `[` that finds a `]`
     * three pages later, and one that finds it after a line break. Both are
     * bounded rather than solved, because they cost a reader a puzzling line at
     * worst and this reports rather than refuses.
     */
    private const string TOKEN = '/\[([^\[\]\r\n]{1,120})\]/u';

    /**
     * The parts of a .docx that hold words somebody typed.
     *
     * The same list the generator settles content controls in, and for the same
     * reason: a letterhead is mostly header, and a marker in a footer is a
     * marker. Anything else in the zip — styles, fonts, the relationship
     * graph — holds no prose and would only contribute false tokens.
     */
    private const string PARTS = '#^word/(document|header\d*|footer\d*)\.xml$#';

    /**
     * Every `[token]` a .docx contains, brackets and all, without repeats.
     *
     * In the order they are first met, which is the order somebody reading the
     * document would meet them — a list sorted any other way makes the reader
     * hunt for the one being complained about.
     *
     * A file rather than the bytes, because `ZipArchive` reads paths and a .docx
     * is a zip. The temporary file is removed however this returns, which is the
     * same dance {@see DocumentGenerator} does for the same library-shaped
     * reason.
     *
     * @param string $contents the .docx as it is kept in the database
     *
     * @return list<string>
     */
    public static function inDocument(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-scan-');

        if ($path === false) {
            return [];
        }

        try {
            file_put_contents($path, $contents);

            $zip = new \ZipArchive();

            // Not an exception: the upload check has already refused anything
            // that is not a readable .docx, so a file that will not open here is
            // one that has been damaged since — and a report that cannot be
            // produced is not a reason to keep somebody off the page listing
            // their templates.
            if ($zip->open($path) !== true) {
                return [];
            }

            $tokens = [];

            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);

                if (preg_match(self::PARTS, $name) !== 1) {
                    continue;
                }

                foreach (self::in((string) $zip->getFromIndex($i)) as $token) {
                    $tokens[$token] = true;
                }
            }

            $zip->close();

            return array_keys($tokens);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The pattern that finds one particular token however Word cut it up
     * (XIV-89).
     *
     * The counterpart to the scanning above and the second half of the same
     * problem: that one asks "what tokens are in here", this one asks "where is
     * *this* token", and both have to survive `[tenant.` sitting in one run with
     * `logo]` in the next. Between every character it allows a stretch of markup
     * containing no `[`, which is what lets a match run across the
     * `</w:t></w:r><w:r><w:t>` Word wedged into the middle of a word; the match
     * is then only the token if `strip_tags` of it reads as the token, which is
     * what the callers check and is why this returns a pattern rather than
     * performing the match itself.
     *
     * **It lives here for the reason the scan does.** `RepeatingBlocks` had it
     * privately, `anourvalar/office` has its own copy inside the library, and
     * XIV-89 needed a third — at which point the class docblock's argument about
     * three scanners disagreeing applies word for word to three patterns. Two
     * callers in this repository now share one, and the day the tolerance has to
     * change there is one place to change it.
     *
     * The `U` modifier makes the markup runs lazy, so a match stops at the first
     * `]` that completes the token rather than at the last one in the file, and
     * `u` is there because a marker may be typed in any script the customer
     * writes in.
     *
     * @param string $token the marker, brackets and all
     */
    public static function spanning(string $token): string
    {
        $pattern = '';

        foreach (mb_str_split($token) as $character) {
            $pattern .= preg_quote($character, '#') . '(<[^\[]*)?';
        }

        return '#' . $pattern . '#Uu';
    }

    /**
     * Every `[token]` in one piece of Word XML, in reading order, without
     * repeats.
     *
     * `strip_tags` is the whole trick and is worth naming as a decision rather
     * than a convenience: it turns the markup Word scattered through the
     * sentence back into the sentence, so a marker cut across runs reads as one
     * word again. Its cost is that it also removes the boundaries *between*
     * paragraphs and cells, which is why {@see self::TOKEN} refuses to cross a
     * line break and refuses to run on forever.
     *
     * @return list<string>
     */
    public static function in(string $xml): array
    {
        if (preg_match_all(self::TOKEN, strip_tags($xml), $matches) === 0) {
            return [];
        }

        $tokens = [];

        foreach ($matches[0] as $token) {
            $tokens[$token] = true;
        }

        return array_keys($tokens);
    }
}

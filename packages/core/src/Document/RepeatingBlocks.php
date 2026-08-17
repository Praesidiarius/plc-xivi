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

use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordPrimer;
use Xivi\Core\Record\RecordRepository;

/**
 * A table row that draws itself once per row of a collection (XIV-17).
 *
 * An invoice whose template cannot list its lines is not an invoice, and this is
 * the part `anourvalar/office` does not do: its repeating rows are a spreadsheet
 * feature, and for a .docx it substitutes flat markers and nothing else. So the
 * document is preprocessed before the library ever sees it — **the rows are
 * multiplied here, and the library still only ever substitutes markers.**
 *
 * **A block is a table row containing a collection marker.** Nothing else to
 * learn and nothing to open and close: writing `[lines.description]` in a cell is
 * what makes that row repeat, because a marker naming a collection can only mean
 * "once per row of it". A `<w:tr>` is the unit because it is the unit Word gives
 * a person — they build the row they want and it comes out that many times.
 *
 * **How much the template cares about kinds is the template's business.** A row
 * whose markers name a kind — `[lines:article.description]` — is drawn only for
 * lines of that kind, so a template can lay out three rows and give the comment
 * line no money columns and the subtotal line a bold figure. A row whose markers
 * name no kind is drawn for every line. That is the whole decision the ticket
 * asked for, and it is deliberately not one decision: the simple template stays
 * one row, and the careful one is possible without the engine formatting
 * anything on the customer's behalf. An engine that pre-formatted the cells
 * would be choosing how somebody's invoice looks, which is the one thing a
 * template is for.
 *
 * **Consecutive blocks for one collection are a group**, replaced as a whole by
 * the rows in the order the collection holds them (XIV-21). They have to be: a
 * comment sits *between* two article lines, so drawing all the article rows and
 * then all the comment rows would sort the invoice by kind.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RepeatingBlocks
{
    /** `[lines.description]`, or `[lines:article.description]`. */
    private const string MARKER = '/\[([a-z0-9_]+)(?::([a-z0-9_]+))?\.([a-z0-9_]+)\]/i';

    /** The parts a table can live in. A letterhead is mostly header, and may hold one. */
    private const string PARTS = '#^word/(document|header\d*|footer\d*)\.xml$#';

    public function __construct(
        private RecordRepository $records,
        private DocumentMarkers $markers,
        // The rows are drawn here exactly as the record page draws them, so they
        // are read ahead here too (XIV-54). A long invoice is the case: it is the
        // document that has 500 lines, and generating a PDF is where hundreds of
        // extra round trips are least welcome, since the request is already
        // waiting on a converter.
        private RecordPrimer $primer,
    ) {
    }

    /** Multiply every block in the document, in place. */
    public function expand(string $path, ModuleDefinition $module, Record $record): void
    {
        if ($module->getCollections()->isEmpty() || $record->id === null) {
            return;
        }

        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            return;
        }

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match(self::PARTS, $name) !== 1) {
                continue;
            }

            $xml = (string) $zip->getFromIndex($i);
            $expanded = $this->expandIn($xml, $module, (int) $record->id);

            if ($expanded !== $xml) {
                $zip->addFromString($name, $expanded);
            }
        }

        $zip->close();
    }

    /** @param int $recordId the parent whose rows are being drawn */
    private function expandIn(string $xml, ModuleDefinition $module, int $recordId): string
    {
        $blocks = self::blocksIn($xml, $module);

        if ($blocks === []) {
            return $xml;
        }

        /** @var array<string, list<Record>> $rows read once per collection, however many groups draw it */
        $rows = [];

        // Backwards, so that replacing one group leaves every earlier group's
        // offsets where they were.
        foreach (array_reverse(self::group($blocks)) as $group) {
            $collection = $group[0]['collection'];
            $key = $collection->getKey();
            // Read once per collection however many groups draw it, and primed
            // in the same breath: `??=` means the priming happens on the read
            // rather than on every group, and a second table listing the same
            // lines finds both the rows and their references already in hand.
            if (!isset($rows[$key])) {
                $rows[$key] = $this->records->findChildren($collection, $recordId);
                $this->primer->prime($collection, $rows[$key]);
            }

            $drawn = '';

            foreach ($rows[$key] as $row) {
                $block = self::blockFor($group, $collection->variantOf($row->data));

                // A row no block was laid out for is not drawn. The alternative
                // — falling back to some other kind's row — prints a comment
                // through an article line's columns, and a template that lists
                // only what it has a row for is a template somebody meant.
                if ($block !== null) {
                    $drawn .= $this->draw($block, $collection, $row);
                }
            }

            $start = $group[0]['start'];
            $end = $group[\count($group) - 1]['end'];
            // An empty collection leaves nothing behind rather than one blank
            // row: the header of the table is still there, which is the sensible
            // page for a document with no lines.
            $xml = substr($xml, 0, $start) . $drawn . substr($xml, $end);
        }

        return $xml;
    }

    /**
     * One copy of a block, with this row's values in place of its markers.
     *
     * @param array{start: int, end: int, xml: string, collection: CollectionDefinition, variant: string|null, tokens: array<string, string>} $block
     */
    private function draw(array $block, CollectionDefinition $collection, Record $row): string
    {
        $values = $this->markers->valuesOf($collection, $row);
        $xml = $block['xml'];

        foreach ($block['tokens'] as $token => $field) {
            $xml = self::substitute($xml, $token, $values[$field] ?? '');
        }

        return $xml;
    }

    /**
     * Replace one marker wherever it is, even when Word has cut it up.
     *
     * A placeholder somebody typed in one go can end up split across several
     * runs — `[lines.` in one and `description]` in the next — which is why this
     * cannot be a string replace. The pattern allows tags between every
     * character and then checks that the text between them really was the
     * marker, which is how the library does the same job (§5.7).
     */
    private static function substitute(string $xml, string $token, string $value): string
    {
        $pattern = '';

        foreach (mb_str_split($token) as $character) {
            $pattern .= preg_quote($character, '#') . '(<[^\[]*)?';
        }

        return (string) preg_replace_callback(
            '#' . $pattern . '#Uu',
            static fn (array $match): string => strip_tags($match[0]) === $token
                ? htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8')
                : $match[0],
            $xml,
        );
    }

    /**
     * Every table row that names a collection, innermost first.
     *
     * Innermost because a nested table is an ordinary thing in a letterhead, and
     * the row that repeats is the tightest one around the marker — the outer row
     * holding the whole table is not a line item.
     *
     * @return list<array{start: int, end: int, xml: string, collection: CollectionDefinition, variant: string|null, tokens: array<string, string>}>
     */
    private static function blocksIn(string $xml, ModuleDefinition $module): array
    {
        $blocks = [];
        $inner = [];

        foreach (self::tableRows($xml) as $row) {
            $found = self::markersOf(substr($xml, $row['start'], $row['end'] - $row['start']), $module);

            if ($found === null) {
                continue;
            }

            // A row that contains a qualifying row is the table around the
            // block, not the block.
            foreach ($inner as $seen) {
                if ($seen['start'] > $row['start'] && $seen['end'] < $row['end']) {
                    continue 2;
                }
            }

            $inner[] = $row;
            $blocks[] = [
                'start' => $row['start'],
                'end' => $row['end'],
                'xml' => substr($xml, $row['start'], $row['end'] - $row['start']),
                ...$found,
            ];
        }

        usort($blocks, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $blocks;
    }

    /**
     * What one row's markers say: which collection, which kind, and which field
     * each token wants.
     *
     * The first collection marker decides; a row mixing two collections is not a
     * thing anybody means, and picking the first is a decision rather than an
     * error message about a Word document.
     *
     * @return array{collection: CollectionDefinition, variant: string|null, tokens: array<string, string>}|null
     */
    private static function markersOf(string $xml, ModuleDefinition $module): ?array
    {
        // Against the text rather than the markup, so a marker Word has split
        // across runs still reads as one word here.
        if (preg_match_all(self::MARKER, strip_tags($xml), $matches, \PREG_SET_ORDER) === 0) {
            return null;
        }

        $collection = null;
        $variant = null;
        $tokens = [];

        foreach ($matches as $match) {
            [$token, $name, $kind, $field] = [$match[0], $match[1], $match[2], $match[3]];

            $found = $module->getCollection($name);

            if ($found === null || ($collection !== null && $found->getKey() !== $collection->getKey())) {
                continue;
            }

            $collection ??= $found;
            $variant ??= $kind === '' ? null : $kind;
            $tokens[$token] = $field;
        }

        return $collection === null ? null : ['collection' => $collection, 'variant' => $variant, 'tokens' => $tokens];
    }

    /**
     * Blocks for one collection that sit next to each other, as one group.
     *
     * Next to each other means nothing but whitespace between them, which is
     * what `</w:tr><w:tr>` looks like in a table. A second table further down the
     * page listing the same collection is its own group and draws its own rows —
     * a delivery note that lists the lines twice is somebody's decision.
     *
     * @param list<array{start: int, end: int, xml: string, collection: CollectionDefinition, variant: string|null, tokens: array<string, string>}> $blocks
     *
     * @return list<list<array{start: int, end: int, xml: string, collection: CollectionDefinition, variant: string|null, tokens: array<string, string>}>>
     */
    private static function group(array $blocks): array
    {
        $groups = [];
        $current = [];

        foreach ($blocks as $block) {
            $previous = $current === [] ? null : $current[\count($current) - 1];

            if (
                $previous !== null
                && ($previous['collection']->getKey() !== $block['collection']->getKey()
                    || $previous['end'] !== $block['start'])
            ) {
                $groups[] = $current;
                $current = [];
            }

            $current[] = $block;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Which block draws a row of this kind: the one that names it, or the one
     * that names none.
     *
     * In the order the template laid them out, so a general row placed first is
     * what a template means by "and everything else like this".
     *
     * @param list<array{start: int, end: int, xml: string, collection: CollectionDefinition, variant: string|null, tokens: array<string, string>}> $group
     *
     * @return array{start: int, end: int, xml: string, collection: CollectionDefinition, variant: string|null, tokens: array<string, string>}|null
     */
    private static function blockFor(array $group, ?string $variant): ?array
    {
        foreach ($group as $block) {
            if ($block['variant'] === null || $block['variant'] === $variant) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Every `<w:tr>` in the part, nesting included, innermost first.
     *
     * A hand-written scan rather than a regex, because `<w:tr>.*?</w:tr>` stops
     * at the first close it meets — which for a table inside a table is the
     * wrong one, and produces a span of markup that is not an element.
     *
     * @return list<array{start: int, end: int}>
     */
    private static function tableRows(string $xml): array
    {
        preg_match_all('#<w:tr[\s/>]|</w:tr>#', $xml, $matches, \PREG_OFFSET_CAPTURE);

        $open = [];
        $rows = [];

        foreach ($matches[0] as [$tag, $offset]) {
            if (str_starts_with($tag, '</')) {
                $start = array_pop($open);

                if ($start !== null) {
                    $rows[] = ['start' => $start, 'end' => $offset + \strlen('</w:tr>')];
                }

                continue;
            }

            // A self-closing row holds no cells and therefore no markers.
            if (!str_ends_with($tag, '/>')) {
                $open[] = $offset;
            }
        }

        return $rows;
    }
}

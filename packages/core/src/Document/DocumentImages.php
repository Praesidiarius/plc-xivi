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
 * A marker that becomes a picture rather than a sentence (XIV-89).
 *
 * **This inverts the pipeline, which is why it is a class and not a key in a
 * list.** Every other marker resolves to text: `DocumentMarkers::dataFor()`
 * returns `array<string, string>`, `DocumentGenerator` hands that to
 * `anourvalar/office`, and the library replaces strings inside the XML parts of
 * the zip. There is no image path in that library at all — it has a driver for
 * the spreadsheet side and nothing equivalent for a Word drawing — so placing
 * one means writing DrawingML by hand and doing the *opposite* operation to the
 * one everything else does: the marker's text is not substituted, the marker is
 * replaced by an element.
 *
 * ### What a picture in a .docx actually costs
 *
 * A .docx is an OPC package, and an image is not one thing in it but four:
 *
 * - the bytes go in as a part, `word/media/…`;
 * - the part is reachable only through a **relationship** in the rels of
 *   whichever part draws it, under an `rId` that must not collide with one the
 *   customer's own template already uses;
 * - `[Content_Types].xml` has to say what a `.png` or a `.jpeg` in this package
 *   is, or Word refuses to open the file at all;
 * - and the drawing itself carries its size in **EMU**, so somebody has to
 *   decide how large a logo is on a page.
 *
 * Each of those is per-*part*, not per-document, which is the half that catches
 * people out: a letterhead puts its mark in `header1.xml`, and a header has its
 * own relationships. So the work below is done once for every part that mentions
 * the marker, and the media bytes are the only thing shared between them.
 *
 * ### Where the rId comes from
 *
 * Not from a counter, and not from `rId1000` and hoping. The rels part is read,
 * every `Id` in it is collected, and the next free `rIdN` above the highest
 * numeric one is taken — then checked against the collected set anyway, because
 * a template written by something other than Word may number its relationships
 * in any order it likes or not numerically at all. Getting this wrong is not a
 * visible bug: two relationships sharing an id means the header image and the
 * customer's own font or hyperlink resolve to each other, and what comes out is
 * a document that opens and is subtly wrong.
 *
 * ### Where the drawing goes, and why the marker may be in pieces
 *
 * `<w:drawing>` is run content — a sibling of `<w:t>` inside a `<w:r>` — so the
 * substitution closes the text, emits the drawing and opens a fresh text:
 * `…</w:t><w:drawing/><w:t xml:space="preserve">…`. That is valid because a run
 * may hold text, then a drawing, then text again.
 *
 * It also happens to be exactly right for the case that makes this hard. Word
 * cuts a placeholder somebody typed in one go across several runs, so the span
 * being replaced routinely *contains* `</w:t></w:r><w:r><w:t>` in the middle of
 * it. Consuming that span and emitting the three fragments above removes one
 * `</w:r>` and one `<w:r>` together, so the markup stays balanced and the two
 * runs become one. The tail text inherits the first run's formatting rather than
 * its own, which is the one thing this loses and is a fair trade: the
 * alternative is reconstructing run properties from a span that may have crossed
 * three of them.
 *
 * A span that crosses a **paragraph** is refused instead. `[tenant.` at the end
 * of one paragraph and `logo]` at the start of the next is not a marker somebody
 * typed, it is two brackets that happen to face each other, and joining the
 * paragraphs to draw a picture between them would be a worse answer than leaving
 * the words alone.
 *
 * ### How big
 *
 * See {@see self::extentOf()}. Briefly: natural size at 96 dpi, scaled down to
 * fit a 40 × 20 mm box, never scaled up.
 *
 * ### What it does not do
 *
 * It does not decide what an image marker *is*. The keys and the bytes come from
 * {@see DocumentContext::images()}, so core still never learns what a tenant or
 * a logo is — the same seam `PdfConverter` and `InstanceCurrency` sit on. And it
 * does not fetch anything: documents are generated without a browser, so the
 * bytes arrive from the database through that interface and never over HTTP.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DocumentImages
{
    /**
     * The parts a marker can be typed into.
     *
     * The same list {@see TemplateTokens} scans and {@see RepeatingBlocks}
     * expands, and here it is load-bearing rather than tidy: a letterhead is
     * mostly header, so the header is where a customer actually puts their mark.
     */
    private const string PARTS = '#^word/(document|header\d*|footer\d*)\.xml$#';

    /** What OPC calls a relationship pointing at an image. */
    private const string IMAGE_RELATIONSHIP = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    /** The rels document a part that had none starts from. */
    private const string NO_RELATIONSHIPS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

    /**
     * A pixel, in English Metric Units, at 96 dpi.
     *
     * 914400 EMU is an inch by definition, and 96 dpi is the resolution every
     * screen-facing tool has assumed since Windows 3.1 — including Word itself
     * when it pastes a bitmap. A PNG's own `pHYs` chunk is deliberately *not*
     * consulted: most exports carry none, the ones that do carry whatever the
     * design tool felt like, and a logo that came out twice the size of the same
     * logo re-exported would be impossible for a customer to explain.
     */
    private const int EMU_PER_PIXEL = 9525;

    /** An EMU is 1/914400 inch; a millimetre is 36000 of them. */
    private const int EMU_PER_MM = 36000;

    /** 40 mm — see {@see self::extentOf()} for why that number. */
    private const int WIDEST_MM = 40;

    /** 20 mm — likewise. */
    private const int TALLEST_MM = 20;

    public function __construct(
        private DocumentContext $context,
    ) {
    }

    /**
     * Draws every image marker the document mentions, in place.
     *
     * Returns quietly when there is nothing to draw, which is the ordinary case
     * twice over: most installations have uploaded no logo, and most templates
     * do not mention one. Whatever is left in the file afterwards is a marker
     * with no image behind it, and the flat substitution that runs next blanks it
     * like any other unfilled marker — which is the whole of the "no logo draws
     * nothing" rule, and the reason it is not written anywhere as a branch.
     *
     * @param string $path the .docx being built, as a file, because the parts
     *                     live in a zip and `ZipArchive` reads paths
     */
    public function place(string $path): void
    {
        $images = self::readable($this->context->images());

        if ($images === []) {
            return;
        }

        $zip = new \ZipArchive();

        // Not an exception, for the reason {@see TemplateTokens} gives: by the
        // time this runs the template has already been accepted as a readable
        // .docx, so a file that will not open here is one the generator is about
        // to fail on anyway, with a better message than this could give.
        if ($zip->open($path) !== true) {
            return;
        }

        $parts = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match(self::PARTS, $name) === 1) {
                $parts[$name] = (string) $zip->getFromIndex($i);
            }
        }

        // Read before anything is written, because a part being replaced while
        // the archive is open is a thing to reason about and this way there is
        // nothing to reason about.
        $types = (string) $zip->getFromName('[Content_Types].xml');

        // Every drawing in the finished document needs its own `docPr` id, the
        // customer's own pictures included — a template that already has a
        // letterhead graphic has already used some of these.
        $drawingId = self::firstFreeDrawingId($parts);

        /** @var array<string, string> $media marker key => the media part it was written as, once however many parts draw it */
        $media = [];
        /** @var array<string, string> $rewritten part name => its new contents */
        $rewritten = [];

        foreach ($parts as $name => $xml) {
            $relationships = null;
            $relationshipsPart = self::relationshipsFor($name);

            foreach ($images as $key => $image) {
                $token = sprintf('[%s]', $key);

                if (!self::mentions($xml, $token)) {
                    continue;
                }

                // Read once per part and only for a part that turns out to need
                // it: most documents have exactly one header that draws this and
                // several that do not.
                $relationships ??= self::relationshipsIn($zip, $relationshipsPart);
                $id = self::freeRelationshipId($relationships);
                $file = $media[$key] ??= $image['file'];

                $relationships = self::withRelationship($relationships, $id, $file);
                $xml = self::draw($xml, $token, $id, $image, $drawingId);
            }

            if ($relationships !== null) {
                $rewritten[$name] = $xml;
                $rewritten[$relationshipsPart] = $relationships;
            }
        }

        if ($rewritten !== []) {
            foreach ($rewritten as $name => $contents) {
                $zip->addFromString($name, $contents);
            }

            foreach ($media as $key => $file) {
                $zip->addFromString('word/media/' . $file, $images[$key]['bytes']);
            }

            $zip->addFromString('[Content_Types].xml', self::withContentTypes($types, $images, array_keys($media)));
        }

        $zip->close();
    }

    /**
     * How large a logo is on a page, which the ticket asked to be decided and
     * written down.
     *
     * **Natural size at 96 dpi, scaled down to fit 40 × 20 mm, never scaled up.**
     * Each third of that is a separate decision:
     *
     * *Natural size* is the honest starting point, and the only one that does not
     * require guessing what the customer meant. It is also what makes a small
     * mark come out small rather than blown up: a 120 × 40 wordmark draws at
     * 32 × 11 mm, which is a real letterhead logo.
     *
     * *The box* is what stops the common case being absurd. Logos are exported at
     * two or three times their intended size as a matter of course, so a 1200-
     * pixel-wide PNG is not a customer asking for a banner 317 mm across — it is
     * the same 40 mm wordmark at 3×. A4 is 210 mm wide and leaves about 160 mm
     * between ordinary margins, so 40 mm is a quarter of the text width: large
     * enough to read as the company's mark, small enough that dropping it into a
     * paragraph does not rearrange the page. The 20 mm ceiling is what keeps a
     * *square* logo from becoming a 40 mm block; a letterhead band is about that
     * tall.
     *
     * *Never scaled up* because enlarging a bitmap to fill a box is how a crisp
     * mark acquires soft edges, and a customer whose logo came out blurry has no
     * way of knowing we did it — the same argument §8.6 made for not re-encoding
     * the upload.
     *
     * **The aspect ratio is preserved**, so the box is a bound rather than a
     * shape. A wide wordmark hits the width and a tall crest hits the height, and
     * neither is distorted to fit.
     *
     * **This does not want a second upload.** §8.6 left open whether a wide mark
     * for the bar and a square one for the letterhead would eventually be two
     * fields; nothing here brings that forward, because fitting rather than
     * stretching already gives both aspect ratios a sensible answer from one
     * file. If 40 × 20 turns out to be wrong for somebody, the next thing to add
     * is a *size* on the profile — one number, next to the picture they already
     * uploaded — and not a second picture, which would be a second thing to keep
     * in step for the sake of a measurement.
     *
     * @return array{int, int} the extent as `[cx, cy]`, in EMU
     */
    public static function extentOf(int $width, int $height): array
    {
        $cx = $width * self::EMU_PER_PIXEL;
        $cy = $height * self::EMU_PER_PIXEL;

        $scale = min(
            1.0,
            (self::WIDEST_MM * self::EMU_PER_MM) / $cx,
            (self::TALLEST_MM * self::EMU_PER_MM) / $cy,
        );

        // At least one EMU in each direction: an extent of zero is a drawing
        // Word draws as nothing and LibreOffice has been known to reject, and
        // rounding a one-pixel image down is the only way to reach it.
        return [max(1, (int) round($cx * $scale)), max(1, (int) round($cy * $scale))];
    }

    /**
     * The images that can actually be embedded, with everything the drawing needs
     * already worked out.
     *
     * **The format is decided by decoding the header, never by being told.** The
     * interface hands over bytes and nothing else precisely so this question is
     * asked here, of the thing being embedded, rather than answered by a label
     * somebody else chose — which is the same call `LogoFormat` makes about an
     * upload and `DocumentController` makes about a .docx. `getimagesizefromstring`
     * is core PHP rather than an extension and reads only the header, so a file
     * it does not recognise costs nothing.
     *
     * **PNG and JPEG and nothing else**, which is not this class being cautious:
     * it is the list §8.6 arrived at, and the reason it is that list is a licence
     * rather than a preference — the only credible SVG sanitizer in PHP is
     * GPL-2.0-or-later and this project is MIT. Both of these are formats Word
     * embeds natively, so the media part needs no conversion and nothing is
     * re-encoded on the way through.
     *
     * The media part is named after a hash of the bytes. That is not for caching
     * — nothing caches inside a .docx — but so that the name cannot collide with
     * something the customer's own template already keeps in `word/media`, which
     * is where every picture they have ever pasted lives.
     *
     * @param array<string, string> $images
     *
     * @return array<string, array{bytes: string, file: string, extension: string, type: string, cx: int, cy: int}>
     */
    private static function readable(array $images): array
    {
        $readable = [];

        foreach ($images as $key => $bytes) {
            $size = @getimagesizefromstring($bytes);

            if ($size === false) {
                continue;
            }

            [$width, $height] = $size;

            /** @var array{string, string}|null $format */
            $format = match ($size[2]) {
                \IMAGETYPE_PNG => ['png', 'image/png'],
                \IMAGETYPE_JPEG => ['jpeg', 'image/jpeg'],
                default => null,
            };

            if ($format === null || $width < 1 || $height < 1) {
                continue;
            }

            [$cx, $cy] = self::extentOf($width, $height);

            $readable[$key] = [
                'bytes' => $bytes,
                'file' => sprintf('xivi-%s.%s', substr(hash('sha256', $bytes), 0, 16), $format[0]),
                'extension' => $format[0],
                'type' => $format[1],
                'cx' => $cx,
                'cy' => $cy,
            ];
        }

        return $readable;
    }

    /**
     * Whether this part really says this marker, however Word cut it up.
     *
     * Asked before anything is allocated, because allocating a relationship for a
     * marker that is not there would leave every header of every document
     * carrying a reference to a picture nothing draws.
     */
    private static function mentions(string $xml, string $token): bool
    {
        if (preg_match_all(TemplateTokens::spanning($token), $xml, $matches, \PREG_SET_ORDER) < 1) {
            return false;
        }

        foreach ($matches as $match) {
            if (self::isTheMarker((string) $match[0], $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a matched span is the marker rather than a coincidence.
     *
     * Two questions, and both are refusals. Stripping the markup has to give back
     * the marker exactly — the pattern allows tags between every character, so
     * without this a run of unrelated markup between two brackets would match.
     * And the span must not cross a paragraph: a `[` at the end of one paragraph
     * finding a `]` at the start of the next is two brackets facing each other,
     * not something somebody typed, and drawing a picture there would silently
     * weld the two paragraphs into one.
     */
    private static function isTheMarker(string $span, string $token): bool
    {
        return strip_tags($span) === $token && !str_contains($span, '</w:p>');
    }

    /**
     * Replaces every occurrence of one marker in one part with a drawing.
     *
     * The id is passed by reference and consumed as it goes, because a marker in
     * a repeated table row is now several markers — {@see RepeatingBlocks} has
     * already multiplied the row by the time this runs — and each of the copies
     * needs a `docPr` of its own.
     *
     * @param array{bytes: string, file: string, extension: string, type: string, cx: int, cy: int} $image
     * @param int                                                                                   $id    the next free drawing id, advanced past every drawing written
     */
    private static function draw(string $xml, string $token, string $relationshipId, array $image, int &$id): string
    {
        return (string) preg_replace_callback(
            TemplateTokens::spanning($token),
            static function (array $match) use ($token, $relationshipId, $image, &$id): string {
                if (!self::isTheMarker((string) $match[0], $token)) {
                    return (string) $match[0];
                }

                // Out of the text, into the run, back into the text — see the
                // class docblock for why this is what keeps the markup balanced
                // when Word has split the marker across two runs.
                return '</w:t>' . self::drawing($id++, $relationshipId, $image) . '<w:t xml:space="preserve">';
            },
            $xml,
        );
    }

    /**
     * One inline picture, as DrawingML.
     *
     * **Every namespace is declared on the element that uses it**, which is
     * unusual to read and is deliberate. A .docx Word wrote declares `wp`, `a`,
     * `pic` and `r` on `<w:document>` and this could rely on that — but a
     * template does not have to have come from Word, the header parts of one
     * frequently declare a shorter list than the document does, and a missing
     * declaration is a file that no longer parses rather than one that draws
     * oddly. Declaring them locally is valid XML, costs a few hundred bytes per
     * picture, and cannot be wrong.
     *
     * The drawing is `wp:inline` rather than `wp:anchor`: the marker sits in a
     * line of text, an inline picture is what a character position means, and
     * anchoring would mean inventing an offset from a page corner that the
     * template never asked for. A customer who wants their mark floating puts it
     * in the header, which is a placement Word gives them and this does not have
     * to model.
     *
     * @param array{bytes: string, file: string, extension: string, type: string, cx: int, cy: int} $image
     */
    private static function drawing(int $id, string $relationshipId, array $image): string
    {
        $wordprocessingDrawing = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
        $drawingml = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $picture = 'http://schemas.openxmlformats.org/drawingml/2006/picture';
        $relationships = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        return sprintf(
            '<w:drawing>'
                . '<wp:inline xmlns:wp="%1$s" distT="0" distB="0" distL="0" distR="0">'
                    . '<wp:extent cx="%5$d" cy="%6$d"/>'
                    . '<wp:effectExtent l="0" t="0" r="0" b="0"/>'
                    . '<wp:docPr id="%7$d" name="Picture %7$d"/>'
                    . '<wp:cNvGraphicFramePr>'
                        . '<a:graphicFrameLocks xmlns:a="%2$s" noChangeAspect="1"/>'
                    . '</wp:cNvGraphicFramePr>'
                    . '<a:graphic xmlns:a="%2$s">'
                        . '<a:graphicData uri="%3$s">'
                            . '<pic:pic xmlns:pic="%3$s">'
                                . '<pic:nvPicPr>'
                                    . '<pic:cNvPr id="0" name="%8$s"/>'
                                    . '<pic:cNvPicPr/>'
                                . '</pic:nvPicPr>'
                                . '<pic:blipFill>'
                                    . '<a:blip xmlns:r="%4$s" r:embed="%9$s"/>'
                                    . '<a:stretch><a:fillRect/></a:stretch>'
                                . '</pic:blipFill>'
                                . '<pic:spPr>'
                                    . '<a:xfrm><a:off x="0" y="0"/><a:ext cx="%5$d" cy="%6$d"/></a:xfrm>'
                                    . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
                                . '</pic:spPr>'
                            . '</pic:pic>'
                        . '</a:graphicData>'
                    . '</a:graphic>'
                . '</wp:inline>'
            . '</w:drawing>',
            $wordprocessingDrawing,
            $drawingml,
            $picture,
            $relationships,
            $image['cx'],
            $image['cy'],
            $id,
            htmlspecialchars($image['file'], \ENT_XML1 | \ENT_QUOTES, 'UTF-8'),
            $relationshipId,
        );
    }

    /** `word/document.xml` keeps its relationships in `word/_rels/document.xml.rels`. */
    private static function relationshipsFor(string $part): string
    {
        return \dirname($part) . '/_rels/' . basename($part) . '.rels';
    }

    /**
     * A part's relationships, or an empty set when it has none.
     *
     * A header with no hyperlinks and no pictures genuinely has no rels part, so
     * "missing" is an ordinary answer rather than a damaged package — and the one
     * this class is most likely to meet, since a letterhead's header is exactly
     * where the mark is about to go.
     *
     * A `<Relationships/>` written self-closed is reopened rather than treated as
     * unreadable. It means the same thing and some tools write it that way.
     */
    private static function relationshipsIn(\ZipArchive $zip, string $name): string
    {
        $relationships = $zip->getFromName($name);

        if ($relationships === false || trim($relationships) === '') {
            return self::NO_RELATIONSHIPS;
        }

        if (!str_contains($relationships, '</Relationships>')) {
            $relationships = (string) preg_replace(
                '#<Relationships\b([^>]*?)\s*/>#',
                '<Relationships$1></Relationships>',
                $relationships,
            );
        }

        return str_contains($relationships, '</Relationships>') ? $relationships : self::NO_RELATIONSHIPS;
    }

    /**
     * An `rId` this part is definitely not already using.
     *
     * **The collision is the point of this method**, and it is worth saying what
     * a collision would look like, because it does not look like a crash: the
     * package would still open, and the reader would resolve one of the two
     * relationships for both uses — so the customer's own header image, hyperlink
     * or embedded font would come out as the logo, or the logo would come out as
     * their font. A document that is wrong and opens is worse than one that does
     * not open.
     *
     * So: every `Id` in the part is collected, the highest `rIdN` decides where to
     * start, **and the candidate is checked against the set anyway**. The second
     * check is not belt and braces. Relationship ids are xsd:ID and nothing
     * requires them to be `rId` plus a number — a template that has been through
     * a converter, a template engine or somebody's script may carry
     * `rIdImage1`, and counting past the numeric maximum would happily land on
     * something that already exists.
     */
    private static function freeRelationshipId(string $relationships): string
    {
        preg_match_all('#\bId="([^"]*)"#', $relationships, $matches);

        $taken = array_fill_keys($matches[1], true);
        $next = 1;

        foreach ($matches[1] as $id) {
            if (preg_match('#^rId(\d+)$#', $id, $digits) === 1) {
                $next = max($next, (int) $digits[1] + 1);
            }
        }

        while (isset($taken['rId' . $next])) {
            ++$next;
        }

        return 'rId' . $next;
    }

    /**
     * The same relationships with one more in them.
     *
     * The target is relative to the part's own directory, which for everything
     * under `word/` means `media/…` rather than `word/media/…`. Writing the
     * absolute-looking form is the classic way to produce a package that opens
     * everywhere except in Word.
     */
    private static function withRelationship(string $relationships, string $id, string $file): string
    {
        $relationship = sprintf(
            '<Relationship Id="%s" Type="%s" Target="media/%s"/>',
            $id,
            self::IMAGE_RELATIONSHIP,
            htmlspecialchars($file, \ENT_XML1 | \ENT_QUOTES, 'UTF-8'),
        );

        $closing = strrpos($relationships, '</Relationships>');

        // Cannot happen — self::relationshipsIn() guarantees the closing tag —
        // and is handled rather than asserted because the alternative is throwing
        // over a template that was going to generate fine apart from its picture.
        return $closing === false
            ? $relationships
            : substr_replace($relationships, $relationship, $closing, 0);
    }

    /**
     * `[Content_Types].xml` with a declaration for each extension being added.
     *
     * **Word refuses to open a package containing a part it has no content type
     * for**, and refuses loudly and unhelpfully — "the file is corrupt" — so this
     * is the step whose absence looks like every other possible mistake. A
     * `Default` per extension rather than an `Override` per part, because that is
     * what the extension mechanism is for and because it stays correct if the
     * same logo is ever drawn into a second media part.
     *
     * A template that already contains pictures already declares its extension,
     * which is the common case for a real letterhead, so most of the time this
     * changes nothing at all.
     *
     * @param array<string, array{bytes: string, file: string, extension: string, type: string, cx: int, cy: int}> $images
     * @param list<string>                                                                                         $used   the keys of the images actually written
     */
    private static function withContentTypes(string $types, array $images, array $used): string
    {
        $closing = strrpos($types, '</Types>');

        if ($closing === false) {
            return $types;
        }

        foreach ($used as $key) {
            $extension = $images[$key]['extension'];
            $declared = '#<Default\b[^>]*\bExtension="' . preg_quote($extension, '#') . '"#i';

            // Re-asked of the growing document rather than of the original, so
            // two images sharing an extension declare it once.
            if (preg_match($declared, $types) === 1) {
                continue;
            }

            $closing = strrpos($types, '</Types>');

            if ($closing === false) {
                continue;
            }

            $types = substr_replace($types, sprintf(
                '<Default Extension="%s" ContentType="%s"/>',
                $extension,
                $images[$key]['type'],
            ), $closing, 0);
        }

        return $types;
    }

    /**
     * The first `docPr` id no drawing in this document is using.
     *
     * Word wants these unique across the document and complains about a file
     * where they are not, so starting from one would be wrong for the templates
     * that matter most — a letterhead with a graphic in it has already spent the
     * low numbers. `cNvPr` is counted with them because the two share a
     * numbering in every file Word writes and it costs nothing to stay out of
     * both.
     *
     * @param array<string, string> $parts
     */
    private static function firstFreeDrawingId(array $parts): int
    {
        $highest = 0;

        foreach ($parts as $xml) {
            if (preg_match_all('#<(?:[A-Za-z0-9]+:)?(?:docPr|cNvPr)\b[^>]*\bid="(\d+)"#', $xml, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $id) {
                $highest = max($highest, (int) $id);
            }
        }

        return $highest + 1;
    }
}

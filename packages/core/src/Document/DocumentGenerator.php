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

use AnourValar\Office\DocumentService;
use Xivi\Core\Entity\DocumentTemplate;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Record;

/**
 * One record plus one template makes one document (XIV-4).
 *
 * The filling is `anourvalar/office`, which is MIT where PHPWord — the library
 * everybody reaches for — is LGPL-3.0 (§5.7). It replaces `[marker]` inside the
 * .docx's XML, including the case Word makes hard: a placeholder somebody typed
 * in one go can end up split across several runs in the file, and a naive string
 * replace misses exactly the ones a human typed by hand.
 *
 * **Through the filesystem, briefly.** The template lives in the database as
 * bytes and the library reads and writes files, so both ends touch a temporary
 * file that is deleted on the way out — including when filling throws, which is
 * why the finally is not optional.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DocumentGenerator
{
    public function __construct(
        private DocumentMarkers $markers,
        private PdfConverter $converter,
    ) {
    }

    /**
     * The finished .docx, as bytes.
     *
     * @throws DocumentFailed when the template cannot be read or filled
     */
    public function docx(DocumentTemplate $template, ModuleDefinition $module, Record $record): string
    {
        $data = $this->markers->dataFor($module, $record);

        $source = self::temporary('xivi-template-', $template->getContent());
        $target = self::temporary('xivi-document-', '');

        try {
            (new DocumentService())
                ->generate($source, $data)
                ->saveAs($target);

            self::settlePlaceholderControls($target);

            return (string) file_get_contents($target);
        } catch (\Throwable $e) {
            throw DocumentFailed::couldNotFill($template->getName(), $e);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    /**
     * Makes Word's own placeholder text real, so the PDF says what the .docx says.
     *
     * Letterhead templates are usually built from Word *content controls* — the
     * "Sender's name", "Company Name", "address street" boxes you click into.
     * Until somebody types in one, it carries `<w:showingPlcHdr/>`: still showing
     * its placeholder. Word displays that text and prints it; **LibreOffice
     * renders nothing for it**, so the same document came out complete as a .docx
     * and with its whole sender block missing as a PDF.
     *
     * Dropping the flag is what settles it. The text is already in the file — it
     * is the control's own content — and without the flag every reader treats it
     * as ordinary text, which is what Word was showing all along. Nothing is
     * added, removed or substituted; one attribute stops meaning "ignore this".
     *
     * Headers and footers get the same treatment, because a letterhead is mostly
     * header.
     */
    private static function settlePlaceholderControls(string $path): void
    {
        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            return;
        }

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name) !== 1) {
                continue;
            }

            $xml = (string) $zip->getFromIndex($i);
            $settled = (string) preg_replace('#<w:showingPlcHdr\s*/>|<w:showingPlcHdr\b[^>]*>.*?</w:showingPlcHdr>#s', '', $xml);

            if ($settled !== $xml) {
                $zip->addFromString($name, $settled);
            }
        }

        $zip->close();
    }

    /**
     * The same document as a PDF.
     *
     * Filled first and converted afterwards, rather than converted and then
     * filled: the markers live in a Word document and stop being addressable the
     * moment it becomes a PDF.
     *
     * @throws DocumentFailed
     */
    public function pdf(DocumentTemplate $template, ModuleDefinition $module, Record $record): string
    {
        return $this->converter->toPdf(
            $this->docx($template, $module, $record),
            self::filename($template, $record, DocumentFormat::Docx),
        );
    }

    /**
     * What the download is called: the template's name, the record, the date.
     *
     * Named after the record rather than after the template alone, because a
     * folder of downloads called "Invoice.pdf (3)" is somebody else's afternoon.
     */
    public static function filename(DocumentTemplate $template, Record $record, DocumentFormat $format): string
    {
        $stem = sprintf('%s-%s-%s', self::slug($template->getName()), $record->id ?? 0, date('Y-m-d'));

        return $stem . '.' . $format->value;
    }

    /**
     * The library takes paths, so the bytes have to become one for a moment.
     *
     * The name carries the extension, because DocumentService reads the format
     * from it and falls back to docx when there is none — a fallback worth not
     * relying on. tempnam() cannot be asked for a suffix, so the file it reserves
     * is dropped as soon as the real name is derived from it.
     */
    private static function temporary(string $prefix, string $contents): string
    {
        $reserved = (string) tempnam(sys_get_temp_dir(), $prefix);
        $path = $reserved . '.docx';

        @unlink($reserved);
        file_put_contents($path, $contents);

        return $path;
    }

    private static function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');

        return $slug === '' ? 'document' : $slug;
    }
}

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

namespace App\Tenant\Document;

use Sensiolabs\GotenbergBundle\GotenbergInterface;
use Sensiolabs\GotenbergBundle\Processor\TempfileProcessor;
use Xivi\Core\Document\DocumentFailed;
use Xivi\Core\Document\PdfConverter;

/**
 * The engine's PDF question (XIV-4), answered by LibreOffice in a container.
 *
 * The application half of the seam, like ProfileCurrency: core fills a template
 * and never learns that the converter is a service on the compose network.
 *
 * **Why a service and not a library.** Every pure-PHP PDF library renders HTML,
 * so the pipeline with one would be docx → HTML → PDF, and the header, the
 * footer, the page numbering and the fonts of the Word template somebody
 * carefully made are all approximations by the end of it. Gotenberg wraps the
 * same LibreOffice a person would use to export the document themselves. It is
 * also MIT, where dompdf is LGPL-2.1 and mPDF is GPL-2.0 (§5.7).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class GotenbergPdfConverter implements PdfConverter
{
    public function __construct(private GotenbergInterface $gotenberg)
    {
    }

    public function toPdf(string $docx, string $filename): string
    {
        // Gotenberg is handed files rather than bytes, and reads the format from
        // the extension — so the name matters and the document goes to disk for
        // as long as the call takes.
        $path = sys_get_temp_dir() . '/' . uniqid('xivi-convert-', true) . '.docx';
        file_put_contents($path, $docx);

        try {
            // Through a temporary file rather than the in-memory processor,
            // which the bundle labels "do not use in production" for good
            // reason: nothing here knows how big a customer's template is, and
            // accumulating an unknown response in a string is how a page turns
            // into a memory limit. The result is a stream, read once and closed.
            $pdf = $this->gotenberg->pdf()->office()
                ->files($path)
                ->generate()
                ->processor(new TempfileProcessor())
                ->process();

            \assert(\is_resource($pdf));

            try {
                return (string) stream_get_contents($pdf);
            } finally {
                fclose($pdf);
            }
        } catch (\Throwable $e) {
            // A converter that is down is not a broken record. The .docx is
            // offered beside the PDF for exactly this, and the page says so
            // rather than showing a stack trace.
            throw DocumentFailed::converterUnavailable($e);
        } finally {
            @unlink($path);
        }
    }
}

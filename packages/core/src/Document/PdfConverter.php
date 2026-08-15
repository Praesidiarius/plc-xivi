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
 * Turning a finished .docx into a PDF (XIV-4).
 *
 * Declared here and implemented by the application, the same seam
 * `InstanceCurrency` uses: the engine fills a template and has no business
 * knowing that the converter is a service on the compose network, or an HTTP
 * client, or anything else. It also means the whole document pipeline can be
 * tested without one running.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface PdfConverter
{
    /**
     * @param string $docx     the finished document, as bytes
     * @param string $filename what to call it on the way through; converters use
     *                         the extension to decide how to read it
     *
     * @return string the PDF, as bytes
     *
     * @throws DocumentFailed when the converter cannot be reached or refuses
     */
    public function toPdf(string $docx, string $filename): string;
}

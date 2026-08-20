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

use Sensiolabs\GotenbergBundle\Enumeration\Unit;
use Sensiolabs\GotenbergBundle\GotenbergInterface;
use Sensiolabs\GotenbergBundle\Processor\TempfileProcessor;
use Xivi\Core\Document\DocumentFailed;

/**
 * Staples a QR-bill payment part onto a finished invoice PDF (XIV-152).
 *
 * The mechanics behind InvoicePaymentPart, kept apart from the decision-making
 * the way GotenbergPdfConverter is kept apart from DocumentGenerator: this
 * class knows Gotenberg and nothing about invoices, profiles or reference
 * types.
 *
 * **Why Gotenberg twice rather than a PDF library once.** The obvious
 * implementation opens the invoice PDF and draws the slip onto its last page,
 * and every library that can do that was already rejected here on licence
 * (§5.7: FPDI's parser, TCPDF, mPDF; the project's PDF competence deliberately
 * lives in a container, not in vendor/). So the slip becomes its own A4 page
 * through the same Chromium that is already in that container, and Gotenberg's
 * merge endpoint puts the two documents together. The visible consequence is
 * that the payment part is an *additional last page* rather than the foot of
 * the letter's own last page, which is the layout the guidelines themselves
 * bless for bills whose last page is full, and which no template change can
 * ever break, where stamping onto a page the customer's template controls
 * would fight their footer for the same 105 mm.
 *
 * **The slip page is drawn to the standard's geometry.** A6 landscape payment
 * part (210 × 105 mm) at the very foot of an A4 page, zero page margins so the
 * millimetres are the library's own, backgrounds printed because the scissors
 * line and the corner marks are borders. The HTML comes from sprain's
 * HtmlOutput and is not edited here: the layout inside the slip is the
 * library's contract with the guidelines, and this class only decides where
 * the slip sits on paper.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class GotenbergPaymentSlip
{
    public function __construct(private GotenbergInterface $gotenberg)
    {
    }

    /**
     * The invoice with the payment part as its new last page.
     *
     * @param string $pdf             the converted invoice, as bytes
     * @param string $paymentPartHtml sprain's rendered payment part fragment
     *
     * @return string the merged PDF, as bytes
     *
     * @throws DocumentFailed when the converter cannot be reached or refuses;
     *                        a caller that got this far has a valid payload, so
     *                        failing beats quietly shipping the bill unpayable
     */
    public function append(string $pdf, string $paymentPartHtml): string
    {
        $slip = $this->slipPage($paymentPartHtml);

        // The merge endpoint takes files and orders them by *name*, not by the
        // order they are attached, so the names carry the order, one shared
        // prefix and an a/b suffix. Through the filesystem briefly, exactly as
        // the office conversion goes (see GotenbergPdfConverter).
        $prefix = sys_get_temp_dir() . '/' . uniqid('xivi-qr-', true);
        $invoicePath = $prefix . '-a.pdf';
        $slipPath = $prefix . '-b.pdf';

        file_put_contents($invoicePath, $pdf);
        file_put_contents($slipPath, $slip);

        try {
            $merged = $this->gotenberg->pdf()->merge()
                ->files($invoicePath, $slipPath)
                ->generate()
                ->processor(new TempfileProcessor())
                ->process();

            \assert(\is_resource($merged));

            try {
                return (string) stream_get_contents($merged);
            } finally {
                fclose($merged);
            }
        } catch (\Throwable $e) {
            throw DocumentFailed::converterUnavailable($e);
        } finally {
            @unlink($invoicePath);
            @unlink($slipPath);
        }
    }

    /**
     * One A4 page with the payment part at its foot, as PDF bytes.
     *
     * Chromium rather than LibreOffice for this half, and not by accident: the
     * office route exists because customer templates are .docx, but the slip
     * is *our* HTML, and HTML with millimetre CSS is exactly the input
     * Chromium's print pipeline is specified for.
     *
     * @throws DocumentFailed
     */
    private function slipPage(string $paymentPartHtml): string
    {
        // The fragment is wrapped in a page of its own: pinned to the bottom
        // edge, full 210 mm width. Everything above the slip is deliberately
        // blank: the letter said what it had to say on its own pages.
        $page = <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
            <meta charset="utf-8">
            <style>
                html, body { margin: 0; padding: 0; }
                .xivi-payment-slip { position: absolute; bottom: 0; left: 0; width: 210mm; }
            </style>
            </head>
            <body><div class="xivi-payment-slip">{$paymentPartHtml}</div></body>
            </html>
            HTML;

        try {
            $slip = $this->gotenberg->pdf()->html()
                ->contentRaw($page)
                ->paperSize(210.0, 297.0, Unit::Millimeters)
                ->margins(0.0, 0.0, 0.0, 0.0, Unit::Millimeters)
                // The separation line and the blank "payable by" corner marks
                // are drawn as borders and would vanish under Chromium's
                // default print setting.
                ->printBackground()
                ->generate()
                ->processor(new TempfileProcessor())
                ->process();

            \assert(\is_resource($slip));

            try {
                return (string) stream_get_contents($slip);
            } finally {
                fclose($slip);
            }
        } catch (\Throwable $e) {
            throw DocumentFailed::converterUnavailable($e);
        }
    }
}

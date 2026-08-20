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

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Record;

/**
 * What the application may add to a finished PDF that a template cannot carry
 * (XIV-152).
 *
 * Declared here and answered by the application, the same seam `PdfConverter`
 * and `DocumentContext` sit on: core converts a filled template and has no
 * business knowing that a Swiss invoice wants a QR-bill payment part stapled to
 * it, any more than it knows the converter is a container on the compose
 * network.
 *
 * **Why after the conversion and not a marker in the template.** Every marker
 * resolves inside the customer's own .docx, which is exactly right for content:
 * the customer decides where their logo goes. A payment part is the opposite
 * kind of thing: its geometry is normative (an A6 slip at the foot of a page,
 * a 46 mm QR code, the receipt on the left, defined to the millimetre by the
 * Swiss Implementation Guidelines), and a customer-editable template is a place
 * where that promise goes to die: moved, resized, or simply absent from the
 * template somebody uploaded last year. So the slip is composed onto the *PDF*,
 * after the template has said everything the template gets to say.
 *
 * **The .docx deliberately goes out undecorated.** It is offered for editing
 * (§5.7), and whoever edits it makes their PDF through this pipeline again;
 * a payment slip pasted into an editable file would be one more editable thing
 * pretending to be exact.
 *
 * The record travels with the module so an implementation can read the figures
 * it is decorating for, the same pair every other document collaborator gets.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface PdfDecorator
{
    /**
     * The same PDF, or the PDF with whatever this installation adds to it.
     *
     * Returning the bytes untouched is the ordinary case, not a failure: most
     * modules have nothing to add, and an implementation that cannot decorate
     * *this* record says so through its own channels and still returns a
     * document somebody can send.
     *
     * @param string $pdf the converted document, as bytes
     *
     * @return string the document to hand out, as bytes
     *
     * @throws DocumentFailed when decorating was attempted and could not finish;
     *                        a half-decorated document must not leave looking whole
     */
    public function decorate(ModuleDefinition $module, Record $record, string $pdf): string;
}

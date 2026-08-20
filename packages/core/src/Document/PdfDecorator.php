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
 * **Two questions rather than one** (XIV-164). This seam was the only thing in
 * the system that knew whether there was anything to add to a given record's
 * PDF, so when the payment part had to become a choice somebody makes, it was
 * also the only thing that could say what to put the choice on. It answers
 * "what would you add here, and what is it called" as well as "add it", and the
 * generic chooser draws a tick per answer without ever learning what an invoice
 * is. See {@see Decoration}.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface PdfDecorator
{
    /**
     * What this installation *could* add to a PDF of this record, and nothing
     * more (XIV-164).
     *
     * Asked while a form is being drawn, so it must be cheap, must write
     * nothing, and must say nothing: an empty list is the ordinary answer and
     * carries no complaint with it. That silence is the point. A module with no
     * decoration, a tenant whose settings could not produce one, and a record
     * that happens not to qualify all return the same empty list, and the
     * chooser draws no control at all rather than a disabled one explaining
     * itself.
     *
     * An offer here is a promise about *possibility*, not about outcome:
     * {@see self::decorate()} asks its own questions again at the moment it
     * matters, because the answer may have changed between the two and because
     * a hand-posted form never went through this method at all.
     *
     * @return list<Decoration> one per tick to draw, in the order to draw them
     */
    public function offers(ModuleDefinition $module, Record $record): array;

    /**
     * The same PDF, or the PDF with whatever was asked for on it.
     *
     * Returning the bytes untouched is the ordinary case, not a failure: most
     * modules have nothing to add, and an implementation that cannot decorate
     * *this* record says so through its own channels and still returns a
     * document somebody can send.
     *
     * @param string       $pdf    the converted document, as bytes
     * @param list<string> $wanted the keys of the offers that were ticked. An
     *                             unticked checkbox submits nothing, so the empty list is
     *                             both "nobody asked" and "somebody said no", and the two
     *                             deliberately mean the same thing here: the default that
     *                             puts a payment part on a Swiss invoice belongs to the form
     *                             where the choice is visible, not to a fallback down here
     *                             where it would apply to requests nobody chose anything on.
     *                             A key that was never offered is ignored rather than
     *                             refused, which is what keeps a tick from producing
     *                             something the offer would have said was impossible.
     *
     * @return string the document to hand out, as bytes
     *
     * @throws DocumentFailed when decorating was attempted and could not finish;
     *                        a half-decorated document must not leave looking whole
     */
    public function decorate(ModuleDefinition $module, Record $record, string $pdf, array $wanted): string;
}

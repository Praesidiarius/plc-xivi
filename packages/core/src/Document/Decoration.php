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
 * Something the application would add to a PDF, offered rather than applied
 * (XIV-164).
 *
 * XIV-152 gave the application a way to compose a payment part onto a finished
 * invoice, and every invoice PDF then carried one because nothing could say
 * otherwise. There are ordinary reasons not to want a payment slip on a
 * particular document: a copy for the file, a proforma, a corrected invoice
 * sent beside the real one, an invoice somebody has already paid. So the
 * decoration became a tick on the chooser, and this is what a tick is made of.
 *
 * **The chooser may not know what an invoice is.** It is one page, generic over
 * every module (§5.7), and writing "if this is the invoice module, draw a
 * payment-part checkbox" into it is exactly the module-specific code §1 exists
 * to keep out of the engine. So the offer comes from the module's own side of
 * the seam, the way §5.14's mail recipient is declared rather than guessed:
 * {@see PdfDecorator::offers()} answers "what would you add here, and what is
 * it called", the chooser draws one tick per answer, and a module with nothing
 * to add draws nothing at all.
 *
 * Three strings and no behaviour, deliberately. The decision about whether the
 * decoration is *possible* has already been made by the time one of these
 * exists — an offer that has to be re-examined by whoever received it is not
 * an offer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Decoration
{
    public function __construct(
        /**
         * What a request names when it asks for this one, and what the record's
         * timeline writes down afterwards.
         *
         * Stable and ours rather than the customer's: a decoration is declared
         * in code, so unlike a field label (§5.2) it cannot be renamed out from
         * under a history entry, which is why an entry stores the key and lets
         * the catalogue supply the words when somebody reads it.
         */
        public string $key,
        /**
         * The tick's own wording, as a translation key.
         *
         * Core does not translate it and never sees the sentence: the words for
         * a Swiss payment slip belong to whoever knows what one is, which is the
         * same side of the seam that knows how to draw it.
         */
        public string $label,
        /** One line under the tick, as a translation key, or nothing. */
        public ?string $help = null,
    ) {
    }
}

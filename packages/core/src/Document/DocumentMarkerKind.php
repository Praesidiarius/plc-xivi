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
 * What a marker turns into when the document is generated (XIV-89).
 *
 * **Every marker was text until this enum existed**, and that was not an
 * oversight: `DocumentMarkers::dataFor()` hands back `array<string, string>`,
 * the library substituting it replaces strings inside the XML, and the whole
 * pipeline is built out of that one operation (§5.7). `[tenant.logo]` is the
 * first marker that is not a value at all — the marker's run is *replaced by an
 * element*, which is the opposite operation — so the reference list, the
 * substitution and the review each need to be able to tell the two apart.
 *
 * **It exists mostly so the list can say so.** A reference panel offering
 * `[tenant.logo]` beside `[tenant.name]` with nothing to distinguish them is a
 * panel that will get one of them pasted into the middle of a sentence, and the
 * result is a picture wedged into a line of prose — which reads as the engine
 * misbehaving rather than as a template saying what it says. One word on the row
 * is the whole fix, and it costs a marker one property.
 *
 * It is deliberately a *kind* rather than a boolean. The next one of these is
 * plausibly a barcode or a QR code — both drawings, neither a logo — and a
 * boolean called `image` would have to be renamed the day one arrives.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum DocumentMarkerKind
{
    /** Substituted into the words of the document, which is what almost everything is. */
    case Text;

    /** Drawn into the document as a picture; see {@see DocumentImages}. */
    case Image;
}

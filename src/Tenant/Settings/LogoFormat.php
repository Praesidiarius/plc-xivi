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

namespace App\Tenant\Settings;

/**
 * What a tenant's logo is allowed to be (XIV-49).
 *
 * **Two raster formats, and SVG is refused.** That is the interesting half of
 * this class, so the argument is written here rather than left to be
 * reconstructed from the enum having two cases.
 *
 * SVG is what everybody wants for a logo and it is the one format that is not an
 * image at all: it is an XML *document*, with a script element, event handlers on
 * every node, `<foreignObject>`, and external references. Rendered in an `<img>`
 * a browser will not run its script — but nothing keeps it in an `<img>`, and the
 * route serving it (§8.6) is deliberately readable without signing in, from the
 * customer's own origin, which is the origin their session cookie belongs to.
 * "Somebody uploads a document that runs on our origin" is a bad enough sentence
 * that it needs a real defence rather than a `Content-Type` and good intentions.
 *
 * The real defence is a sanitizer, and **the sanitizer is the reason this is a
 * refusal rather than a feature**: the one credible SVG sanitizer in PHP is
 * `enshrined/svg-sanitize`, which is GPL-2.0-or-later. This project is MIT and
 * has already turned down PHPWord over LGPL-3.0 (§5.7); a copyleft dependency is
 * not a thing to take on for a nicer logo. `symfony/html-sanitizer` is MIT and is
 * not an answer — it parses HTML, and an SVG through an HTML sanitizer comes out
 * as either nothing or something that no longer draws. Writing our own is the
 * worst of the three: an allow-list over a format with namespaces, entities and
 * `xlink:href` is a security component, and this codebase reaches for the
 * framework's own before hand-rolling one precisely so it does not end up
 * maintaining those.
 *
 * So: PNG and JPEG. Dull, safe, and what every logo already exists as. A
 * customer with only an SVG exports one, which is one step in their design tool
 * and not a step anybody here has to be right about. If SVG is ever wanted badly
 * enough, the ticket that adds it is a licence decision first and a sanitizing
 * decision second, in that order.
 *
 * WebP and AVIF are left out for a smaller reason and could be added without any
 * of the above: they are safe, they are just not what anybody hands over, and a
 * shorter accepted list is a shorter sentence on the upload form.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum LogoFormat: string
{
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';

    /**
     * How much of a logo is too much: half a mebibyte.
     *
     * Small on purpose, and not only because a logo is small. These bytes live on
     * `tenant_profile`, which is read on nearly every page of the application, so
     * the ceiling is also the amount of extra row every request carries once a
     * customer has uploaded one — see TenantProfile::$logo. A PNG wordmark at a
     * sensible resolution is tens of kilobytes; a customer hitting this is
     * uploading a photograph or a print-resolution export, and the honest thing
     * to say is that it is the wrong file rather than to swallow it.
     */
    public const int MAX_BYTES = 512 * 1024;

    /**
     * The widest or tallest a mark may be, in pixels.
     *
     * Nothing here decodes an image, so this is not about a decompression bomb on
     * our side — it is about not handing one to the browser. A 20000×20000 PNG
     * compresses to very little and expands to gigabytes of bitmap in whoever
     * opens the sign-in page, which is a denial of service aimed at the
     * customer's own staff. The dimensions come out of the header without any
     * pixel being decoded here, so the check is free.
     */
    public const int MAX_PIXELS = 4000;

    /**
     * What these bytes actually are, or null if they are not an accepted image.
     *
     * **Decided by decoding the header, never by the file name or the
     * `Content-Type` the browser sent.** Both of those are things the person
     * uploading chooses, and an upload route that believes them is a route that
     * stores whatever it is handed under whatever label it is asked to. This is
     * the same call `DocumentController` makes about a .docx, where the check is
     * that the zip contains `word/document.xml` rather than that the name ends in
     * the right four letters.
     *
     * `getimagesizefromstring()` is core PHP rather than an extension, and it
     * reads only enough of the header to answer — so an image it does not
     * recognise costs nothing and an image it does is never decoded.
     */
    public static function of(string $bytes): ?self
    {
        $size = @getimagesizefromstring($bytes);

        if ($size === false) {
            return null;
        }

        [$width, $height] = $size;

        if ($width < 1 || $height < 1 || $width > self::MAX_PIXELS || $height > self::MAX_PIXELS) {
            return null;
        }

        return match ($size[2]) {
            \IMAGETYPE_PNG => self::Png,
            \IMAGETYPE_JPEG => self::Jpeg,
            default => null,
        };
    }

    /** What the upload form tells somebody it will take, e.g. `image/png,image/jpeg`. */
    public static function accepted(): string
    {
        return implode(',', array_column(self::cases(), 'value'));
    }
}

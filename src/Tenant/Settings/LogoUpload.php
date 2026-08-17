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
 * A logo somebody uploaded, after it has been proved to be one (XIV-49).
 *
 * **A type rather than a check, and the difference is what fixes an ordering
 * problem.** The profile page has two halves that can refuse a submission — the
 * mail settings (§8.7) and now this — and the rule XIV-37 set was that nothing is
 * written until every refusal has had its say, because a page reporting a failure
 * over a form that half-saved is a page telling somebody the opposite of what
 * happened. Two managers that each validate and then flush cannot keep that rule
 * between them: whichever runs first has already written by the time the second
 * one refuses.
 *
 * So the refusing is pulled out of the writing. Constructing one of these is
 * where a bad file is turned away, the controller does it before it calls
 * anything that touches the database, and `TenantProfileManager::applyLogo()`
 * takes one of these rather than a string — at which point it has nothing left to
 * check, because holding the type *is* the proof. Nothing downstream has to
 * remember to validate, which is the failure mode a `validate($bytes)` helper
 * would still have.
 *
 * What counts as acceptable is LogoFormat's, including the argument for why SVG
 * is not on the list.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class LogoUpload
{
    private function __construct(
        public string $bytes,
        public LogoFormat $format,
    ) {
    }

    /**
     * Proves an upload is a logo, or refuses it.
     *
     * The size is checked before the format because that is the order the answers
     * are useful in: somebody who has just uploaded a forty-megabyte photograph
     * wants to be told about the forty megabytes, not that we could not read it.
     *
     * @param string $filename only ever used to name the file in a refusal —
     *                         never to decide anything about what it is, which is
     *                         LogoFormat's job and it does it by decoding
     *
     * @throws LogoRefused
     */
    public static function from(string $bytes, string $filename): self
    {
        if (\strlen($bytes) > LogoFormat::MAX_BYTES) {
            throw LogoRefused::tooLarge();
        }

        $format = LogoFormat::of($bytes);

        if ($format === null) {
            // An empty upload arrives here too, along with the SVG, the PDF
            // renamed to `.png`, the truncated transfer and the image whose
            // dimensions would flatten the browser drawing it. One sentence for
            // all of them, deliberately: the accepted list is two formats long
            // and naming it is more use than diagnosing which way the file failed.
            throw LogoRefused::notAnImage($filename);
        }

        return new self($bytes, $format);
    }
}

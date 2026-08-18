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

namespace App\Twig;

/**
 * A person drawn as their initials in a coloured circle (XIV-77).
 *
 * **Generated, never fetched**, and that is a decision rather than a shortcut.
 * Gravatar would have been nearly free and would have sent every signed-in
 * user's email hash to a third party on every page load, under a product whose
 * documentation promises the customer's browser makes no CDN calls — the same argument
 * `assets/app.js` makes about scripts, applied to a picture. There is nothing to
 * opt out of here because there is nothing to send.
 *
 * **Where an uploaded picture goes later.** This class would grow a nullable
 * source — a URL, or whatever the storage answer turns out to be — and the
 * template would draw an `<img>` where it now draws {@see $initials}, keeping
 * these two as the fallback for everybody who has not uploaded one. That is the
 * whole seam, and it is deliberately not taken now: an avatar is per user and
 * kept forever, which is the attachments question §9 records as half answered,
 * and it is not a question a top bar gets to settle in passing.
 *
 * **Both halves are derived, and from different things.** The initials come from
 * the name, because that is what a person recognises themselves by; the colour
 * comes from the email, because that is the one string that is unique per user
 * and stable — two colleagues called Anna would otherwise get the same circle,
 * which is precisely when a picture has to distinguish them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Avatar
{
    /**
     * @param string $initials one or two characters, already uppercased
     * @param int    $hue      0–359, for the `--avatar-hue` custom property
     */
    private function __construct(
        public string $initials,
        public int $hue,
    ) {
    }

    public static function for(string $name, string $email): self
    {
        // The name first, the email's local part when the name says nothing —
        // an account whose name was never filled in still has to draw as
        // something, and "@" is not initials.
        $initials = self::initialsOf($name)
            ?? self::initialsOf(self::localPart($email))
            ?? '?';

        return new self($initials, self::hueOf($email !== '' ? mb_strtolower($email) : $name));
    }

    /**
     * The first letter of the first word and of the last, or one letter when
     * there is only one word.
     *
     * Split on punctuation as well as spaces, so "anna.mueller" and
     * "Anna Müller" both give AM: the email fallback arrives as a local part and
     * would otherwise produce a single A for everybody in the company.
     *
     * Null rather than an empty string when there is nothing usable, because the
     * caller has a second source to try and "" is not an answer it can tell apart
     * from one.
     */
    private static function initialsOf(string $source): ?string
    {
        $words = preg_split('/[\s._+\-]+/u', trim($source), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        $letters = array_values(array_filter(
            array_map(static fn (string $word): string => mb_substr($word, 0, 1), $words),
            // Anything that is not a letter or a digit is skipped rather than
            // drawn: a name in quotes, or one that starts with a bracket, would
            // otherwise put punctuation in the circle.
            static fn (string $first): bool => preg_match('/^[\p{L}\p{N}]$/u', $first) === 1,
        ));

        if ($letters === []) {
            return null;
        }

        $picked = \count($letters) === 1
            ? [$letters[0]]
            : [$letters[0], $letters[array_key_last($letters)]];

        // Uppercased one at a time and cut back to a single character each:
        // uppercasing "ß" yields "SS", which would quietly make a two-letter
        // avatar three letters wide.
        return implode('', array_map(
            static fn (string $letter): string => mb_substr(mb_strtoupper($letter), 0, 1),
            $picked,
        ));
    }

    private static function localPart(string $email): string
    {
        $at = mb_strpos($email, '@');

        return $at === false ? $email : mb_substr($email, 0, $at);
    }

    /**
     * A hue from the identity, spread over the whole circle.
     *
     * `crc32` rather than anything cryptographic: this decides a colour, and the
     * only properties that matter are that it is the same everywhere and that it
     * needs no extension. Saturation and lightness are fixed in CSS instead of
     * being derived too, which is what keeps the white initials legible on every
     * hue — a colour picked freely would eventually pick a yellow.
     */
    private static function hueOf(string $identity): int
    {
        return abs(crc32($identity)) % 360;
    }
}

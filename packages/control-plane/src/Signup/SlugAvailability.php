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

namespace Xivi\ControlPlane\Signup;

/**
 * The answer to "could we be called this?" (XIV-64).
 *
 * **The derived slug travels with the answer**, and that is the whole reason the
 * availability check takes a company name as well as a slug. [XIV-65]'s form
 * shows a name before the visitor submits anything, and the name it shows has to
 * be the name that will be recorded — so the endpoint derives it and hands back
 * what it derived, rather than the page deriving one and hoping the server
 * agrees. Two implementations of a transliteration rule disagree on the first
 * umlaut somebody types.
 *
 * The reason is a {@see SignupError} rather than a boolean and a message,
 * because it is the same published vocabulary the submission endpoint answers
 * with: a caller that can render `slug_taken` from a refused submission renders
 * it here without a second table of strings.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SlugAvailability
{
    private function __construct(
        /** What was asked about, after derivation. Empty only when nothing could be derived. */
        public string $slug,
        /** Why not, or null when it is free. */
        public ?SignupError $reason,
    ) {
    }

    public static function free(string $slug): self
    {
        return new self($slug, null);
    }

    public static function refused(string $slug, SignupError $reason): self
    {
        return new self($slug, $reason);
    }

    public function isAvailable(): bool
    {
        return $this->reason === null;
    }
}

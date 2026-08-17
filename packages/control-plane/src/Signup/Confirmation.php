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

use Xivi\ControlPlane\Entity\SignupRequest;

/**
 * The result of following a confirmation link: what happened, and to which
 * signup (XIV-64).
 *
 * The signup is nullable for exactly one outcome —
 * {@see ConfirmationOutcome::Unknown} — and pairing the two in a value is what
 * keeps a caller from having to remember that. The page needs the row for the
 * cases where there is one, because it says the company's name back to the
 * person as the proof that this is the signup they meant.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Confirmation
{
    private function __construct(
        public ConfirmationOutcome $outcome,
        public ?SignupRequest $signup,
    ) {
    }

    public static function of(ConfirmationOutcome $outcome, SignupRequest $signup): self
    {
        return new self($outcome, $signup);
    }

    /** Nothing matched the token, and there is therefore nothing to say about. */
    public static function unknown(): self
    {
        return new self(ConfirmationOutcome::Unknown, null);
    }
}

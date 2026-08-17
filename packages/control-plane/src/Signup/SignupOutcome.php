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
 * What the intake said about one submission, as the page reads it (XIV-65).
 *
 * The mirror image of what {@see \Xivi\ControlPlane\Controller\SignupApiController}
 * writes: `201` with a body becomes {@see accepted()}, any refusal becomes
 * {@see refused()} carrying the published {@see SignupError} the response named.
 *
 * **A value rather than an exception**, which is the opposite of the choice made
 * one layer down where {@see SignupRefused} is thrown. The difference is who is
 * listening. Inside the intake a refusal is exceptional: `record()` has one job
 * and every refusal aborts it. To a page, a refusal is one of the two ordinary
 * outcomes — "that name has gone, choose another" is the single most likely thing
 * that happens on a signup form — and rendering it from a `catch` would make the
 * form's normal working day an error path.
 *
 * **The refusal carries a code and not a sentence.** The intake's `message` is
 * one fixed English sentence per code, written for a developer reading a log, and
 * §8.12 is explicit that [XIV-65] owns the words a visitor reads, in their
 * language. So the code is what travels and the translation catalogue is what
 * turns it into something a person can act on; the sentence is not kept at all,
 * because a field that exists is a field somebody eventually prints.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupOutcome
{
    private function __construct(
        /** Why not, or null when the signup was recorded. */
        public ?SignupError $error,
        /** The name the customer will get. Empty on a refusal, since there is none. */
        public string $slug = '',
        /** Where the confirmation went, so the page can say so without trusting its own form back. */
        public string $email = '',
        /** How long they have to answer it, for the same reason. */
        public ?\DateTimeImmutable $confirmationExpiresAt = null,
        /**
         * Seconds to wait, and only ever set alongside {@see SignupError::RateLimited}.
         *
         * Read off the `Retry-After` header rather than the body, because that is
         * where the endpoint puts it and where a proxy in front of it would put
         * its own.
         */
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public static function accepted(string $slug, string $email, ?\DateTimeImmutable $expiresAt): self
    {
        return new self(null, $slug, $email, $expiresAt);
    }

    public static function refused(SignupError $error, ?int $retryAfterSeconds = null): self
    {
        return new self($error, retryAfterSeconds: $retryAfterSeconds);
    }

    public function isAccepted(): bool
    {
        return $this->error === null;
    }
}

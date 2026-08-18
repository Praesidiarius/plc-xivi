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

use Xivi\ControlPlane\Provisioning\TenantProvisioner;

/**
 * A signup the intake will not record, carrying the word the caller is told
 * (XIV-64).
 *
 * **The exception carries a {@see SignupError} rather than a message**, which is
 * the whole reason it exists as a type. The controller turns one into a
 * response, and if the code lived only in the message it would be reconstructed
 * by matching on a sentence — which is how a published error vocabulary quietly
 * drifts from what the service actually decided.
 *
 * **The message here never reaches the caller.** It is for whoever is reading a
 * stack trace and is allowed to say more than the response does — which of three
 * reasons made a name unavailable, which address already holds a signup, what a
 * mail server said. The response carries {@see SignupError::message()} instead, a
 * fixed sentence per case; returning `getMessage()` would have undone
 * {@see SignupError::SlugTaken}'s whole argument from inside the response it was
 * written for.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SignupRefused extends \RuntimeException
{
    private function __construct(
        public readonly SignupError $error,
        string $message,
        ?\Throwable $previous = null,
        /** Seconds until the caller may try again; only ever set for {@see SignupError::RateLimited}. */
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Too many, too fast.
     *
     * Carries the wait because the response carries `Retry-After`: a caller that
     * is told to stop and not told for how long either stops for ever or retries
     * immediately, and both are worse than being told.
     */
    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(
            SignupError::RateLimited,
            sprintf('Too many signup requests; retry in %d seconds.', $retryAfterSeconds),
            retryAfterSeconds: $retryAfterSeconds,
        );
    }

    /** The body was not JSON at all, or was not shaped like the contract says. */
    public static function invalidBody(string $why): self
    {
        return new self(SignupError::InvalidRequest, sprintf('Malformed signup request: %s.', $why));
    }

    /**
     * No shared secret, the wrong one, or none configured at all.
     *
     * The reason is in the message, which goes to a log; the response says only
     * `unauthorized`. A caller learning *which* of the three it was learns
     * something about the installation rather than about its own request.
     */
    public static function unauthorized(string $why): self
    {
        return new self(SignupError::Unauthorized, sprintf('Signup request refused: %s.', $why));
    }

    public static function invalidEmail(string $email): self
    {
        return new self(SignupError::InvalidEmail, sprintf('"%s" is not an email address.', $email));
    }

    public static function invalidSlug(string $slug): self
    {
        return new self(SignupError::InvalidSlug, sprintf(
            '"%s" is not a hostname-safe name (%s).',
            $slug,
            SelfServiceSlug::PATTERN,
        ));
    }

    /** A company name with nothing transliterable in it, and no slug supplied. */
    public static function undeducibleSlug(string $companyName): self
    {
        return new self(SignupError::InvalidSlug, sprintf(
            'No name could be derived from "%s"; the caller has to supply one.',
            $companyName,
        ));
    }

    /**
     * A legal hostname label with no legal provisioning slug behind it
     * (XIV-98).
     *
     * `invalid_slug` rather than a new error code, deliberately. §8.12 fixed the
     * error vocabulary as part of a published contract — within v1 codes may be
     * *added*, so a new one would not break a caller, but it would tell that
     * caller something about the *inside* of this installation for no benefit:
     * the useful action is identical to every other `invalid_slug`, which is to
     * ask for a different name. The internal message names the real reason and
     * goes to a log, exactly as {@see unauthorized()} does for its three.
     */
    public static function unprovisionableSlug(string $slug): self
    {
        return new self(SignupError::InvalidSlug, sprintf(
            '"%s" is a legal hostname label but has no legal provisioning slug (%s).',
            $slug,
            TenantProvisioner::SLUG_PATTERN,
        ));
    }

    /**
     * The name a tenant would get once translated is already a tenant's
     * (XIV-98).
     *
     * The trap §8.12 handed [XIV-98] and the sharpest thing in it. `tenant.slug`
     * holds *provisioning* slugs, so an operator's `acme_bau` can never equal a
     * self-service `acme-bau` — the check that would have caught it looks up the
     * wrong string and finds nothing. The intake therefore asks the registry
     * about the translated form too, and this is what that question refuses
     * with.
     *
     * `slug_taken` like the other three, and §8.12's argument for one word
     * covering several situations applies unchanged: whatever the endpoint
     * distinguishes, a caller can enumerate, and the useful action is the same
     * in every case.
     */
    public static function translatedSlugBelongsToATenant(string $slug, string $tenantSlug): self
    {
        return new self(SignupError::SlugTaken, sprintf(
            '"%s" would be provisioned as "%s", and a tenant is already called that.',
            $slug,
            $tenantSlug,
        ));
    }

    /** The hostname a self-service tenant would be routed at is somebody's already (XIV-98). */
    public static function hostnameBelongsToATenant(string $slug, string $hostname): self
    {
        return new self(SignupError::SlugTaken, sprintf(
            '"%s" would be served at %s, and a tenant is already routed there.',
            $slug,
            $hostname,
        ));
    }

    public static function slugIsReserved(string $slug): self
    {
        return new self(SignupError::SlugTaken, sprintf('"%s" is reserved by this installation.', $slug));
    }

    public static function slugBelongsToATenant(string $slug): self
    {
        return new self(SignupError::SlugTaken, sprintf('A tenant is already called "%s".', $slug));
    }

    public static function slugIsHeldByAnotherSignup(string $slug): self
    {
        return new self(SignupError::SlugTaken, sprintf('A confirmed signup is holding "%s".', $slug));
    }

    public static function addressAlreadyRegistered(string $email): self
    {
        return new self(SignupError::AddressAlreadyRegistered, sprintf(
            '"%s" already holds a confirmed signup that has not been provisioned.',
            $email,
        ));
    }

    /** @param list<string> $known */
    public static function unknownPlan(string $plan, array $known): self
    {
        return new self(SignupError::UnknownPlan, sprintf(
            '"%s" is not a plan this installation offers (%s).',
            $plan,
            implode(', ', $known),
        ));
    }

    public static function mailFailed(\Throwable $failure): self
    {
        return new self(
            SignupError::MailFailed,
            'The signup was recorded and its confirmation mail could not be sent: ' . $failure->getMessage(),
            $failure,
        );
    }

    /**
     * Two submissions for one address, or two confirmations of one name, racing
     * each other.
     *
     * The checks in {@see SignupIntake} are what produce a *useful* message; the
     * unique indexes are what make the rule true. Between the two there is a
     * window of microseconds in which both callers were told the name was free,
     * and this is what the loser gets — the same word they would have got a
     * moment earlier, arriving from the database rather than from a query.
     */
    public static function lostTheRace(SignupError $error, \Throwable $violation): self
    {
        return new self($error, 'Another request got there first: ' . $violation->getMessage(), $violation);
    }
}

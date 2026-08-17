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

use Symfony\Component\HttpFoundation\Response;

/**
 * Everything the signup endpoint can say no with (XIV-64).
 *
 * **This is a published vocabulary, not an internal enum.** [XIV-65]'s landing
 * page is a *different codebase* — the whole point of that ticket is that signup
 * is an external interface rather than a form's private detail — so these
 * strings are what a caller written by somebody else branches on. They are
 * therefore subject to the same rule as the request and response shapes: a case
 * may be **added** within `v1`, because a caller that does not recognise it can
 * fall back to showing the message; a case may not be **removed or renamed**
 * within `v1`, because that silently changes what an existing caller does.
 *
 * The HTTP status is part of the contract too and is decided here rather than in
 * the controller, so that there is one table rather than a status chosen at each
 * `return`.
 *
 * ### Why "taken" and "reserved" are one word
 *
 * {@see SlugTaken} is answered for three genuinely different situations: a real
 * customer already has the name, a confirmed signup is holding it, and the
 * platform keeps it for itself. Telling them apart would be more informative and
 * is deliberately not done, because the endpoint is an **oracle**: whatever it
 * distinguishes, a caller can enumerate. One word means a prober learns "not
 * available" and cannot learn *why* — so they cannot use it to discover which
 * companies are customers of this installation. The form does not lose anything
 * by it: the only action available in all three cases is to pick another name.
 *
 * That defence is partial and the honest limit is worth stating here rather than
 * only in the brief: "not available" is still one bit more than nothing, so the
 * set of unavailable names is discoverable by somebody who is allowed to call
 * this at all. What stops that being an enumeration of the customer list is the
 * shared secret and the rate limiter, not this enum.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum SignupError: string
{
    /** The body was not JSON, or a field it needs is missing or the wrong type. */
    case InvalidRequest = 'invalid_request';

    /** No shared secret, or the wrong one. See {@see SignupApiKey}. */
    case Unauthorized = 'unauthorized';

    /** Not an email address. Nothing is looked up before this passes. */
    case InvalidEmail = 'invalid_email';

    /**
     * Not a legal name.
     *
     * Syntax only, and that is why it is separate from {@see SlugTaken} even
     * though both mean "pick another": this one leaks nothing, because the answer
     * is a property of the string the caller sent rather than of what is in the
     * database. It also covers the case where a company name transliterates to
     * nothing at all and the caller left the slug to be derived.
     */
    case InvalidSlug = 'invalid_slug';

    /** Somebody has it, somebody is holding it, or the platform keeps it. */
    case SlugTaken = 'slug_taken';

    /**
     * This address already holds a confirmed signup that has not been
     * provisioned yet.
     *
     * **Not answered for an unconfirmed one.** A second submission for an
     * address that has not confirmed is a *resend* — see
     * {@see SignupIntake::record()} — so the ordinary "did that mail arrive?"
     * case never sees this. What it means is genuinely different: an order is
     * already in the queue for this address, and a second one would be a second
     * installation nobody asked for.
     */
    case AddressAlreadyRegistered = 'address_already_registered';

    /** A plan this installation does not sell. See `app.signup_plans`. */
    case UnknownPlan = 'unknown_plan';

    /** Too many, too fast. The response carries `Retry-After`. */
    case RateLimited = 'rate_limited';

    /**
     * The signup was recorded and the confirmation mail could not be handed to a
     * mail server.
     *
     * A server-side failure with a 5xx to match, because it is: the caller did
     * nothing wrong and submitting the same thing again is the fix. Never
     * swallowed, for the reason `MailSendFailed` gives one screen along — a
     * confirmation that silently never went is somebody waiting for a mail that
     * is not coming.
     */
    case MailFailed = 'mail_failed';

    /**
     * The one sentence that goes back to the caller beside the code.
     *
     * **Derived from the case rather than taken from the exception**, and that is
     * a security property rather than tidiness. {@see SignupRefused} builds a
     * detailed message for whoever is reading a stack trace — "a tenant is
     * already called acme", "a confirmed signup is holding acme", "acme is
     * reserved by this installation" — and returning it would have handed a
     * caller exactly the three-way distinction {@see SlugTaken} exists to
     * withhold. The same applies to {@see MailFailed}, whose detailed message is
     * a mail server's, naming a host and possibly a role.
     *
     * These sentences are English and are for a developer's log. [XIV-65] owns
     * the words a visitor reads, in their language, keyed on the code.
     */
    public function message(): string
    {
        return match ($this) {
            self::InvalidRequest => 'The request body is not shaped the way this endpoint documents.',
            self::Unauthorized => 'The shared secret is missing or wrong.',
            self::InvalidEmail => 'That is not an email address.',
            self::InvalidSlug => 'That name is not a valid hostname label.',
            self::SlugTaken => 'That name is not available.',
            self::AddressAlreadyRegistered => 'That address already has a signup waiting to be set up.',
            self::UnknownPlan => 'This installation does not offer that plan.',
            self::RateLimited => 'Too many signup requests; see Retry-After.',
            self::MailFailed => 'The confirmation could not be sent. Try again.',
        };
    }

    /**
     * What the endpoint answers with.
     *
     * 409 rather than 400 for the two conflicts, because the request was
     * well-formed and the state of the world is what refused it; 422 would be
     * defensible for both and 409 is the one that says "somebody else got here
     * first", which is what actually happened.
     */
    public function statusCode(): int
    {
        return match ($this) {
            self::InvalidRequest, self::InvalidEmail, self::InvalidSlug, self::UnknownPlan => Response::HTTP_BAD_REQUEST,
            self::Unauthorized => Response::HTTP_UNAUTHORIZED,
            self::SlugTaken, self::AddressAlreadyRegistered => Response::HTTP_CONFLICT,
            self::RateLimited => Response::HTTP_TOO_MANY_REQUESTS,
            self::MailFailed => Response::HTTP_SERVICE_UNAVAILABLE,
        };
    }
}

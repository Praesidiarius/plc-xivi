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

namespace Xivi\ControlPlane\Provisioning;

use Xivi\ControlPlane\Signup\SelfServiceSlug;

/**
 * The one translation from a self-service name to a provisioning name (XIV-98).
 *
 * ### The two rules are disjoint, which is the whole reason this exists
 *
 * §8.12 wrote down two slug patterns and refused to unify them, with an argument
 * that still holds:
 *
 *     TenantProvisioner::SLUG_PATTERN  /^[a-z][a-z0-9_]{1,55}$/
 *     SelfServiceSlug::PATTERN         /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/
 *
 * The first is a PostgreSQL identifier — a database name and a role name — where
 * an underscore is the ordinary separator and a hyphen would force every
 * identifier in the system to be quoted. The second is a DNS label, where a
 * hyphen is the only separator there is and an underscore is illegal. Neither is
 * wrong and neither can be made to be the other, so a signup slug has to be
 * *translated* on its way into provisioning rather than passed through.
 *
 * ### The translation, and why it cannot make two customers collide
 *
 * Hyphen becomes underscore. That is the whole rule, and it was chosen over the
 * alternatives — dropping the separator, or appending a hash — because it is the
 * only one a human can perform in their head. An operator reading
 * `acme-bau.xivi.app` in a support ticket has to be able to type `psql
 * tenant_acme_bau` without looking anything up, and `tenant_acmebau` or
 * `tenant_acme_bau_7f3a` both fail that.
 *
 * **It is injective on legal self-service slugs**, and that is a proof rather
 * than a hope. A self-service slug is drawn from `[a-z0-9-]` and contains no
 * underscore, because {@see SelfServiceSlug::PATTERN} does not permit one. The
 * map is the identity on `[a-z0-9]` and sends the one remaining character to a
 * character that never occurred in the input, so it is a bijection between the
 * self-service alphabet and its image. Two distinct signup slugs therefore never
 * translate to one provisioning slug, and the intake's existing rule — one
 * confirmed signup per reserved name — carries over intact without a second
 * uniqueness check on the translated form.
 *
 * **What it does *not* carry over is the check against the registry**, and that
 * is the sharp edge §8.12 handed this ticket. `tenant.slug` holds provisioning
 * slugs: an operator's `acme_bau`, created by hand a year ago. No self-service
 * slug can ever equal that string — underscores are illegal on that side — so
 * the intake's `findOneBySlug($signupSlug)` looks it up, does not find it, and
 * says `acme-bau` is free. Then this class translates it to `acme_bau` and
 * provisioning refuses, long after somebody confirmed an address and was told
 * the name was theirs. So {@see \Xivi\ControlPlane\Signup\SignupIntake} checks
 * the *translated* name against the registry as well, using this class, at the
 * moment the name is asked for. That is the collision being prevented rather
 * than made unlikely.
 *
 * ### Not every legal self-service slug has a translation at all
 *
 * The patterns disagree about length and about first characters as well as about
 * separators, and in the strict direction:
 *
 *   * A DNS label may be **one** character (`a.xivi.app` is a valid hostname);
 *     `SLUG_PATTERN` demands at least two.
 *   * A DNS label may be **63** characters; `SLUG_PATTERN` allows at most 56.
 *   * A DNS label may **start with a digit** (`3m`); `SLUG_PATTERN` demands a
 *     letter first, because an unquoted PostgreSQL identifier must not start
 *     with one.
 *
 * So the map is partial, {@see forSignupSlug()} answers `null` where it is
 * undefined, and the intake refuses those names at the door with `invalid_slug`.
 * Refusing at the door is the entire point: the alternative is a name that
 * passes every check the customer can see and then fails, permanently and
 * unfixably, in a cron run nobody is watching.
 *
 * ### Static, and deliberately not a service
 *
 * Nothing here depends on configuration or on state, so there is nothing to
 * inject. That also keeps it out of a constructor graph, which matters more than
 * it looks: `SignupEndpointTest` walks the services behind the public
 * controllers and asserts that {@see TenantProvisioner} never appears among
 * them. This class reads one constant off that class and instantiates nothing,
 * so the intake can use it without acquiring a provisioner — the code boundary
 * §8.12 built stays exactly where it was.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ProvisioningSlug
{
    /** The character a DNS label separates with. */
    private const string SELF_SERVICE_SEPARATOR = '-';

    /** The character an unquoted PostgreSQL identifier separates with. */
    private const string PROVISIONING_SEPARATOR = '_';

    /**
     * The longest a provisioning slug may be.
     *
     * `TenantProvisioner::SLUG_PATTERN` says `{1,55}` after one leading letter,
     * which is fifty-six, and this is that number written where a caller can use
     * it. Duplicating a bound out of a regular expression is the sort of thing
     * that drifts, so `ProvisioningSlugTest` asserts both sides of it: fifty-six
     * characters translate and fifty-seven do not. The one caller that needs the
     * number rather than the answer is {@see SelfServiceSlug::derive()}, which
     * has to *cut* a long company name down instead of refusing it.
     */
    public const int MAX_LENGTH = 56;

    private function __construct()
    {
    }

    /**
     * What a tenant provisioned from this signup will be called, or `null` when
     * the name has no legal translation.
     *
     * Takes a self-service slug and validates the *result* rather than the
     * input, which is the order that matters: a caller that has already checked
     * the input against {@see SelfServiceSlug::PATTERN} still learns here that
     * `a` and `3m` and a sixty-character name have nowhere to go.
     */
    public static function forSignupSlug(string $signupSlug): ?string
    {
        $slug = str_replace(self::SELF_SERVICE_SEPARATOR, self::PROVISIONING_SEPARATOR, $signupSlug);

        return preg_match(TenantProvisioner::SLUG_PATTERN, $slug) === 1 ? $slug : null;
    }

    /**
     * Whether this name can become a tenant at all.
     *
     * The question the intake asks; {@see forSignupSlug()} is the same question
     * with the answer attached, and this exists so that a refusal reads as a
     * refusal at the call site rather than as a null check.
     */
    public static function isProvisionable(string $signupSlug): bool
    {
        return self::forSignupSlug($signupSlug) !== null;
    }
}

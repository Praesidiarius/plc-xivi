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
 * The request body of `POST /api/signup/v1/requests`, as a value (XIV-64).
 *
 * **This class is the published request shape.** It is here rather than in the
 * controller because the shape is the contract: [XIV-65]'s landing page is a
 * separate codebase posting into this, so "which fields exist and which are
 * optional" is an interface somebody else compiles against, not a detail of how
 * one action happens to read `$request`.
 *
 * The versioning rule that follows from that: within `v1` a field may be
 * **added** and it must be optional, because an existing caller will not send
 * it; a field may not be removed, renamed, or made required. Anything that
 * cannot be done under those rules is `/api/signup/v2/`, served beside v1 rather
 * than instead of it.
 *
 * **Unknown fields are ignored rather than refused**, which is the one liberal
 * choice in here and is deliberate: a caller written against a later version of
 * this contract, sending a field this deployment does not know yet, should
 * degrade to the behaviour of this version rather than fail outright. The
 * opposite rule — refuse anything unrecognised — makes every field addition a
 * coordinated deployment.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SignupSubmission
{
    private function __construct(
        /** Required. Where the confirmation goes, and therefore the only fact that gets verified. */
        public string $email,
        /** Required. What they call themselves; becomes `tenant.name`. */
        public string $companyName,
        /**
         * Optional. Empty means "derive it from the company name" —
         * {@see SelfServiceSlug::derive()} — which is what a caller that has not
         * asked the availability endpoint yet will send.
         */
        public string $slug,
        /**
         * Optional. Empty means the installation's first configured plan.
         *
         * A field at all because `Tenant::$plan` is a field at all: billing is out
         * of scope for this ticket and writing the intake as though `standard` is
         * the only possible answer would be building in the assumption that has to
         * be undone first.
         */
        public string $plan,
        /**
         * Optional. The language the visitor was reading the form in, which is
         * the language the confirmation mail is written in.
         *
         * There is nowhere else this could come from. The person has no account
         * on this installation, so there is no stored preference, and the
         * `Accept-Language` of a *server-to-server* POST is the calling server's
         * rather than the visitor's.
         */
        public string $locale,
        /**
         * Optional. The visitor's address as the calling site saw it.
         *
         * **Believed, because the caller holds the shared secret.** The
         * recommended shape of this integration is a server-side post
         * ([XIV-65]), which means every request arrives from one address — the
         * calling site's — and an IP limiter keyed on the transport address
         * would be one bucket for the whole internet. So the caller is asked to
         * forward what it saw. Somebody who can lie about this is somebody who
         * already holds the credential, and the answer to a compromised caller is
         * to rotate the secret rather than to distrust one field of theirs.
         *
         * Empty falls back to the transport address, which is right for a caller
         * that posts directly.
         */
        public string $clientIp,
    ) {
    }

    /**
     * @param mixed $payload whatever `json_decode` made of the body
     *
     * @throws SignupRefused when the body is not an object, or a field it carries is
     *                       not a string — a caller sending `{"email": ["a", "b"]}`
     *                       is told its request was invalid rather than having the
     *                       array quietly stringified into an address
     */
    public static function fromPayload(mixed $payload): self
    {
        if (!\is_array($payload) || array_is_list($payload)) {
            throw SignupRefused::invalidBody('the body must be a JSON object');
        }

        return new self(
            email: mb_strtolower(self::string($payload, 'email')),
            companyName: self::string($payload, 'company'),
            slug: mb_strtolower(self::string($payload, 'slug')),
            plan: self::string($payload, 'plan'),
            locale: self::string($payload, 'locale'),
            clientIp: self::string($payload, 'client_ip'),
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws SignupRefused
     */
    private static function string(array $payload, string $field): string
    {
        $value = $payload[$field] ?? '';

        if (!\is_string($value)) {
            throw SignupRefused::invalidBody(sprintf('"%s" must be a string', $field));
        }

        return trim($value);
    }
}

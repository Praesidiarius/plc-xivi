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
 * The secret in a confirmation link, and the digest that is stored instead of it
 * (XIV-64).
 *
 * A value rather than two loose strings, for the same reason `SenderIdentity` is
 * one: the plaintext and the hash are a pair, and a caller free to hold one
 * without the other is a caller free to store the wrong one. There is exactly one
 * way to make a token — {@see generate()} — and it hands back both halves at
 * once, so "store the digest, mail the secret" is the only sequence the type
 * permits.
 *
 * **Thirty-two bytes from `random_bytes`.** That is 256 bits of entropy in a
 * value whose only defence is being unguessable, which puts brute force out of
 * reach without any help from the rate limiter — worth stating because the rate
 * limiter is about volume rather than about this, and a token that needed it
 * would be a token that stopped being safe the day somebody widened a limit.
 *
 * **Base64url rather than hex**, so 32 bytes cost 43 characters instead of 64 and
 * the whole link stays inside the width mail clients wrap at. The alphabet has no
 * `+`, `/` or `=` in it, so nothing about the URL needs escaping and nothing about
 * it can be corrupted by a client that helpfully "fixes" a link.
 *
 * **SHA-256 rather than a password hasher**, and this is the one choice here that
 * looks wrong at a glance. `password_hash` exists to make *guessable* inputs
 * expensive to test; this input is not guessable, so a slow KDF would buy nothing
 * against an attacker and would cost a slow hash on every click of every
 * confirmation link. What the digest is for is the other threat entirely — a dump
 * of the control-plane database containing something that could be presented as
 * a confirmation — and a plain digest answers that completely.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ConfirmationToken
{
    /** Bytes of entropy. See the class docblock for why this number and not a smaller one. */
    private const int BYTES = 32;

    /** Base64url of {@see BYTES} bytes, and therefore the length of the `{token}` route parameter. */
    public const int LENGTH = 43;

    /**
     * What the route accepts, so that a malformed token never reaches a query.
     *
     * Not a security control — a wrong token is refused by the lookup anyway —
     * but it keeps the confirmation route from matching every stray path under
     * `/signup/confirm/`, which is one fewer thing answering anything at all.
     */
    public const string PATTERN = '[A-Za-z0-9_-]{' . self::LENGTH . '}';

    private function __construct(
        /** What goes in the mail, and nowhere else. */
        #[\SensitiveParameter]
        public string $plaintext,
    ) {
    }

    public static function generate(): self
    {
        return new self(self::encode(random_bytes(self::BYTES)));
    }

    /** What is written to the database: a digest of {@see $plaintext}. */
    public function hash(): string
    {
        return self::hashOf($this->plaintext);
    }

    /**
     * The digest of something that arrived in a URL, for looking the row up.
     *
     * Static, and the only way anything outside this class hashes a token —
     * `hash('sha256', …)` spelled out at a call site is how the two ends stop
     * agreeing about which algorithm is in use.
     */
    public static function hashOf(#[\SensitiveParameter] string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /** RFC 4648 §5: base64 with a URL-safe alphabet and no padding. */
    private static function encode(#[\SensitiveParameter] string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

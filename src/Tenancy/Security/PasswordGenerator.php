<?php

declare(strict_types=1);

namespace App\Tenancy\Security;

/**
 * Passwords from the CSPRNG, in two shapes.
 *
 * Both come from random_bytes. Deriving a password from the clock — as the
 * previous generation of this system did with `date +%s | sha256sum` — makes the
 * search space "which second was this account created in", which is a few
 * thousand guesses if you know the day.
 */
final class PasswordGenerator
{
    /**
     * For credentials only software ever types: database roles, service accounts.
     * 32 bytes, base64url so it needs no escaping in a DSN or a DDL literal.
     */
    public static function machine(): string
    {
        return self::encode(32);
    }

    /**
     * For a credential a human has to read off a screen and type once before
     * changing it. 12 bytes is 96 bits — far beyond guessing — in 16 characters.
     */
    public static function human(): string
    {
        return self::encode(12);
    }

    /** @param positive-int $bytes */
    private static function encode(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}

<?php

declare(strict_types=1);

namespace App\Tenancy\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts the per-tenant database passwords held in the control plane.
 *
 * What this defends against is narrow and worth being honest about: a dump of
 * the control database, a snapshot, a read replica, a support engineer with
 * SELECT rights. It does *not* defend against an attacker who already has the
 * application process, because the process must be able to decrypt on every
 * request. Isolation against that case comes from per-tenant roles, not from
 * this class.
 *
 * XSalsa20-Poly1305 via libsodium: authenticated, so a tampered ciphertext is
 * rejected instead of decrypting to garbage that we would then send to Postgres.
 *
 * Every value records the key it was written with:
 *
 *     v1:<key-id>:<base64 of nonce||ciphertext>
 *
 * so several keys can be valid at once. That makes rotation a resumable
 * background job rather than a single all-or-nothing rewrite: add a key, point
 * TENANT_SECRET_KEY_ID at it, run `tenant:rotate-secrets`, then drop the old key
 * once nothing reports as stale.
 */
final readonly class TenantSecretCipher
{
    private const string FORMAT = 'v1';

    /** Key ids appear in stored values and must not collide with the separator. */
    private const string KEY_ID_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

    /** @var array<string, string> raw 32-byte keys, by id */
    private array $keys;

    /**
     * @param array<array-key, mixed> $keys base64-encoded keys by id, e.g. {"2026-08": "..."}
     */
    public function __construct(
        /**
         * In dev these come from .env; in production from the secrets vault
         * (`bin/console secrets:set TENANT_SECRET_KEYS`) or an injected KMS value.
         * Several keys may be present at once — only during a rotation, normally one.
         */
        #[Autowire('%env(json:TENANT_SECRET_KEYS)%')]
        array $keys,
        /** The key new values are written with. */
        #[Autowire('%env(TENANT_SECRET_KEY_ID)%')]
        private string $activeKeyId,
    ) {
        $decoded = [];

        foreach ($keys as $id => $key) {
            $id = (string) $id;

            if (preg_match(self::KEY_ID_PATTERN, $id) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid tenant secret key id "%s": allowed are letters, digits, dot, dash and '
                    . 'underscore, up to 32 characters.',
                    $id,
                ));
            }

            if (!\is_string($key) || ($raw = base64_decode($key, true)) === false) {
                throw new \InvalidArgumentException(sprintf('Tenant secret key "%s" is not valid base64.', $id));
            }

            if (\strlen($raw) !== \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \LengthException(sprintf(
                    'Tenant secret key "%s" must decode to %d bytes, got %d. Generate one with: '
                    . 'php -r "echo base64_encode(random_bytes(32));"',
                    $id,
                    \SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                    \strlen($raw),
                ));
            }

            $decoded[$id] = $raw;
        }

        if (!isset($decoded[$this->activeKeyId])) {
            throw new \InvalidArgumentException(sprintf(
                'TENANT_SECRET_KEY_ID is "%s", but TENANT_SECRET_KEYS holds no such key (has: %s).',
                $this->activeKeyId,
                $decoded === [] ? 'none' : implode(', ', array_keys($decoded)),
            ));
        }

        $this->keys = $decoded;
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    /** @return string `v1:<key-id>:<base64>`, safe to store in a text column */
    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = $nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->keys[$this->activeKeyId]);

        return sprintf('%s:%s:%s', self::FORMAT, $this->activeKeyId, base64_encode($payload));
    }

    /**
     * @throws \RuntimeException when the value is malformed, was written with a key
     *                           we no longer hold, or was altered
     */
    public function decrypt(string $ciphertext): string
    {
        [$keyId, $payload] = $this->split($ciphertext);

        $raw = base64_decode($payload, true);

        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed tenant secret.');
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->keys[$keyId],
        );

        if ($plaintext === false) {
            throw new \RuntimeException(sprintf(
                'Could not decrypt a tenant secret written with key "%s": the stored value was altered, '
                . 'or that key id now holds different key material.',
                $keyId,
            ));
        }

        return $plaintext;
    }

    public function keyIdOf(string $ciphertext): string
    {
        return $this->split($ciphertext)[0];
    }

    /** True when the value was written with a key other than the active one. */
    public function needsRotation(string $ciphertext): bool
    {
        return $this->keyIdOf($ciphertext) !== $this->activeKeyId;
    }

    /**
     * @return array{string, string} key id and payload
     *
     * @throws \RuntimeException
     */
    private function split(string $ciphertext): array
    {
        $parts = explode(':', $ciphertext, 3);

        if (\count($parts) !== 3 || $parts[0] !== self::FORMAT) {
            throw new \RuntimeException(sprintf(
                'Tenant secret is not in "%s:<key-id>:<payload>" form. Values written before key ids '
                . 'were introduced cannot be read; those tenants need to be re-provisioned.',
                self::FORMAT,
            ));
        }

        if (!isset($this->keys[$parts[1]])) {
            throw new \RuntimeException(sprintf(
                'Tenant secret was written with key "%s", which is not in TENANT_SECRET_KEYS. Restore '
                . 'that key to decrypt, or re-provision the tenant.',
                $parts[1],
            ));
        }

        return [$parts[1], $parts[2]];
    }
}

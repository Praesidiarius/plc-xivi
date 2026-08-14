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

namespace App\Tests\Unit\Tenancy;

use App\Tenancy\Security\TenantSecretCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[CoversClass(TenantSecretCipher::class)]
final class TenantSecretCipherTest extends TestCase
{
    private const string OLD = 'k2026-02';
    private const string NEW = 'k2026-08';

    /** @var array<string, string> base64 keys by id */
    private array $keys;

    protected function setUp(): void
    {
        $this->keys = [
            self::OLD => base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            self::NEW => base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        ];
    }

    public function testRoundTrip(): void
    {
        $cipher = $this->cipher(self::NEW);

        self::assertSame('s3cret-pw', $cipher->decrypt($cipher->encrypt('s3cret-pw')));
    }

    public function testCiphertextDoesNotContainThePlaintext(): void
    {
        self::assertStringNotContainsString('s3cret-pw', $this->cipher(self::NEW)->encrypt('s3cret-pw'));
    }

    /** A fresh nonce per call, so identical passwords do not produce identical rows. */
    public function testSamePlaintextEncryptsDifferentlyEachTime(): void
    {
        $cipher = $this->cipher(self::NEW);

        self::assertNotSame($cipher->encrypt('s3cret-pw'), $cipher->encrypt('s3cret-pw'));
    }

    public function testStoredValueNamesTheKeyItWasWrittenWith(): void
    {
        $ciphertext = $this->cipher(self::NEW)->encrypt('s3cret-pw');

        self::assertStringStartsWith('v1:' . self::NEW . ':', $ciphertext);
        self::assertSame(self::NEW, $this->cipher(self::NEW)->keyIdOf($ciphertext));
    }

    /**
     * The point of the key id: during a rotation both keys are configured, and
     * values written with either one stay readable.
     */
    public function testValueWrittenWithAPreviousKeyIsStillReadable(): void
    {
        $written = $this->cipher(self::OLD)->encrypt('s3cret-pw');

        $afterRotation = $this->cipher(self::NEW);

        self::assertSame('s3cret-pw', $afterRotation->decrypt($written));
        self::assertTrue($afterRotation->needsRotation($written));
        self::assertFalse($afterRotation->needsRotation($afterRotation->encrypt('s3cret-pw')));
    }

    public function testValueWrittenWithAKeyWeNoLongerHoldIsReportedClearly(): void
    {
        $written = $this->cipher(self::OLD)->encrypt('s3cret-pw');

        $onlyNewKey = new TenantSecretCipher([self::NEW => $this->keys[self::NEW]], self::NEW);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not in TENANT_SECRET_KEYS/');

        $onlyNewKey->decrypt($written);
    }

    /** Values predating key ids are unreadable by design, and must say so. */
    public function testUnversionedValueIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/re-provisioned/');

        $this->cipher(self::NEW)->decrypt(base64_encode(random_bytes(64)));
    }

    /**
     * Authenticated encryption matters here: a silently corrupted password would
     * be sent to Postgres as a login attempt rather than rejected locally.
     */
    public function testTamperedCiphertextIsRejected(): void
    {
        $cipher = $this->cipher(self::NEW);
        [$format, $keyId, $payload] = explode(':', $cipher->encrypt('s3cret-pw'), 3);

        $raw = base64_decode($payload, true);
        self::assertIsString($raw);
        $raw[\strlen($raw) - 1] = $raw[\strlen($raw) - 1] === 'a' ? 'b' : 'a';

        $this->expectException(\RuntimeException::class);

        $cipher->decrypt(sprintf('%s:%s:%s', $format, $keyId, base64_encode($raw)));
    }

    public function testKeyMaterialSwappedUnderTheSameIdIsRejected(): void
    {
        $written = $this->cipher(self::NEW)->encrypt('s3cret-pw');

        $impostor = new TenantSecretCipher(
            [self::NEW => base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES))],
            self::NEW,
        );

        $this->expectException(\RuntimeException::class);

        $impostor->decrypt($written);
    }

    public function testActiveKeyMustBeConfigured(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/holds no such key/');

        new TenantSecretCipher([self::OLD => $this->keys[self::OLD]], self::NEW);
    }

    public function testShortKeyIsRejectedAtConstruction(): void
    {
        $this->expectException(\LengthException::class);

        new TenantSecretCipher(['k1' => base64_encode('too-short')], 'k1');
    }

    /** Key ids end up in stored values, so they may not contain the separator. */
    public function testKeyIdWithSeparatorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TenantSecretCipher(['bad:id' => $this->keys[self::NEW]], 'bad:id');
    }

    private function cipher(string $activeKeyId): TenantSecretCipher
    {
        return new TenantSecretCipher($this->keys, $activeKeyId);
    }
}

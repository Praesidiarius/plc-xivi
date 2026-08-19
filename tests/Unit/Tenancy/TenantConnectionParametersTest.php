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

use App\Registry\Entity\Tenant;
use App\Tenancy\Dbal\TenantConnectionParameters;
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenancy\Exception\NoTenantResolvedException;
use App\Tenancy\Exception\TenantCredentialMissingException;
use App\Tenancy\Security\TenantSecretCipher;
use App\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[CoversClass(TenantConnectionParameters::class)]
final class TenantConnectionParametersTest extends TestCase
{
    private const array PLACEHOLDER = [
        'driver' => 'pdo_pgsql',
        'host' => 'control-host',
        'port' => 5432,
        'dbname' => 'control_db',
        'user' => 'control_user',
        'password' => 'control_password',
        'serverVersion' => '18',
        'driverOptions' => ['connect_timeout' => 3],
    ];

    private TenantSecretCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new TenantSecretCipher(
            ['test' => base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES))],
            'test',
        );
    }

    public function testTenantDsnReplacesEveryIdentifyingParameter(): void
    {
        $params = $this->resolveFor('postgresql://acme_user@db.example.com:6432/tenant_acme?serverVersion=18', 'acme_pw');

        self::assertSame('db.example.com', $params['host']);
        self::assertSame(6432, $params['port']);
        self::assertSame('tenant_acme', $params['dbname']);
        self::assertSame('acme_user', $params['user']);
        self::assertSame('acme_pw', $params['password']);
    }

    public function testDriverLevelSettingsSurviveFromTheConfiguredConnection(): void
    {
        $params = $this->resolveFor('postgresql://acme_user@db.example.com:6432/tenant_acme', 'acme_pw');

        self::assertSame(['connect_timeout' => 3], $params['driverOptions']);
        self::assertSame('pdo_pgsql', $params['driver']);
    }

    /**
     * The dangerous case: a tenant DSN missing pieces must not silently inherit
     * the control plane's identity, which would connect us as the wrong user or,
     * worse, to the wrong database (docs/architecture/open-questions.md §7.4).
     */
    public function testIncompleteTenantDsnDoesNotInheritControlPlaneIdentity(): void
    {
        $params = $this->resolveFor('postgresql://db.example.com/tenant_acme', 'acme_pw');

        self::assertSame('tenant_acme', $params['dbname']);
        self::assertArrayNotHasKey('user', $params);
        self::assertSame('acme_pw', $params['password']);
    }

    public function testConnectingWithoutAResolvedTenantFailsLoudly(): void
    {
        $parameters = new TenantConnectionParameters(new TenantContext(), new TenantDsnParser(), $this->cipher);

        $this->expectException(NoTenantResolvedException::class);

        $parameters->resolve(self::PLACEHOLDER);
    }

    /** A tenant predating per-tenant roles has no credential; it must not fall back to one. */
    public function testTenantWithoutAStoredPasswordFailsLoudly(): void
    {
        $context = new TenantContext();
        $context->setTenant(new Tenant('acme', 'Acme AG', 'postgresql://acme_user@db/tenant_acme'));

        $this->expectException(TenantCredentialMissingException::class);

        (new TenantConnectionParameters($context, new TenantDsnParser(), $this->cipher))->resolve(self::PLACEHOLDER);
    }

    /** @return array<string, mixed> */
    private function resolveFor(string $dsn, string $password): array
    {
        $tenant = new Tenant('acme', 'Acme AG', $dsn);
        $tenant->setEncryptedDatabasePassword($this->cipher->encrypt($password));

        $context = new TenantContext();
        $context->setTenant($tenant);

        return (new TenantConnectionParameters($context, new TenantDsnParser(), $this->cipher))->resolve(self::PLACEHOLDER);
    }
}

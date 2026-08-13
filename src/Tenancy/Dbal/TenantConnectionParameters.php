<?php

declare(strict_types=1);

namespace App\Tenancy\Dbal;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\Exception\TenantCredentialMissingException;
use App\Tenancy\Security\TenantSecretCipher;
use App\Tenancy\TenantContext;

/**
 * Turns the placeholder parameters configured for the `tenant` connection into
 * the parameters of the tenant that is actually being served.
 *
 * Every parameter that identifies *which database and as whom* is taken from the
 * tenant's own DSN and its decrypted password; nothing that identifies a
 * database survives from the configured placeholder, so a missing piece in a
 * tenant DSN can never silently fall back to the control-plane database. Driver
 * level settings (driver options, wrapper class, server version, charset) do
 * survive, since they describe how we talk to Postgres, not to whom.
 */
final readonly class TenantConnectionParameters
{
    /** Parameters that name a database or an identity, and must come from the tenant. */
    private const array IDENTITY_PARAMS = ['host', 'port', 'dbname', 'user', 'password', 'path', 'url', 'primary', 'replica'];

    public function __construct(
        private TenantContext $context,
        private TenantDsnParser $dsnParser,
        private TenantSecretCipher $cipher,
    ) {
    }

    /**
     * @param array<string, mixed> $configured
     *
     * @return array<string, mixed>
     *
     * @throws \App\Tenancy\Exception\NoTenantResolvedException when called outside a tenant context
     * @throws TenantCredentialMissingException                 when the tenant has no stored password
     */
    public function resolve(array $configured): array
    {
        $tenant = $this->context->getTenant();

        foreach (self::IDENTITY_PARAMS as $param) {
            unset($configured[$param]);
        }

        return [
            ...$configured,
            ...$this->dsnParser->parse($tenant->getDatabaseDsn()),
            'password' => $this->passwordFor($tenant),
        ];
    }

    private function passwordFor(Tenant $tenant): string
    {
        $ciphertext = $tenant->getEncryptedDatabasePassword();

        if ($ciphertext === null) {
            throw new TenantCredentialMissingException($tenant);
        }

        return $this->cipher->decrypt($ciphertext);
    }
}

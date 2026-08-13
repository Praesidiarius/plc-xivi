<?php

declare(strict_types=1);

namespace App\Tenancy\Security;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Re-encrypts tenant secrets with the active key.
 *
 * Deliberately one tenant per flush: rotation across a large registry should be
 * resumable, and a run that dies halfway must leave every row readable rather
 * than a mix of committed and lost work. Rows already on the active key are
 * skipped, so re-running is free.
 */
final readonly class TenantSecretRotator
{
    public function __construct(
        private TenantRepository $tenants,
        private EntityManagerInterface $controlPlane,
        private TenantSecretCipher $cipher,
    ) {
    }

    /**
     * @return RotationReport what was rotated, skipped, and what could not be read
     */
    public function rotate(): RotationReport
    {
        $rotated = [];
        $skipped = [];
        $failed = [];

        foreach ($this->tenants->findAllOrdered() as $tenant) {
            $ciphertext = $tenant->getEncryptedDatabasePassword();

            if ($ciphertext === null) {
                $failed[$tenant->getSlug()] = 'no stored secret; re-provision this tenant';

                continue;
            }

            try {
                if (!$this->cipher->needsRotation($ciphertext)) {
                    $skipped[] = $tenant->getSlug();

                    continue;
                }

                $this->reEncrypt($tenant, $ciphertext);
                $rotated[] = $tenant->getSlug();
            } catch (\RuntimeException $e) {
                // One unreadable row must not stop the rest: the remaining
                // tenants still need to move off the old key.
                $failed[$tenant->getSlug()] = $e->getMessage();
            }
        }

        return new RotationReport($rotated, $skipped, $failed, $this->cipher->activeKeyId());
    }

    private function reEncrypt(Tenant $tenant, string $ciphertext): void
    {
        $plaintext = $this->cipher->decrypt($ciphertext);
        $tenant->setEncryptedDatabasePassword($this->cipher->encrypt($plaintext));
        sodium_memzero($plaintext);

        $this->controlPlane->flush();
    }
}

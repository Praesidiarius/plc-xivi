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

namespace App\Tenancy\Security;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Repository\TenantProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Re-encrypts tenant secrets with the active key.
 *
 * Deliberately one tenant per flush: rotation across a large registry should be
 * resumable, and a run that dies halfway must leave every row readable rather
 * than a mix of committed and lost work. Rows already on the active key are
 * skipped, so re-running is free.
 *
 * **There are two secrets per tenant now, and they live in different databases**
 * (XIV-37). The database password is a control-plane row; the SMTP password a
 * customer configured for their outgoing mail is in the customer's own database,
 * because it is their setting and that is where their settings live (§8.6). A
 * rotation that covered only the first would report "everything is on the active
 * key", the operator would drop the old key on the strength of it, and every
 * customer's mail password would become unreadable — quietly, until the next
 * invoice somebody tried to send. So this walks tenant databases as well.
 *
 * **The customer's database is rotated first, and the order is not incidental.**
 * The control-plane row *is* the key to that database: it holds the password the
 * connection is made with. Re-encrypt it first and a run that dies before
 * reaching the tenant side has left the door on a key the next attempt may no
 * longer hold, which turns a resumable job into one that can strand itself.
 * Doing it the other way round, the worst case is a tenant whose mail secret has
 * moved and whose database password has not — and both keys are valid until the
 * operator drops one, which is exactly what the report is for.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantSecretRotator
{
    public function __construct(
        private TenantRepository $tenants,
        private EntityManagerInterface $controlPlane,
        private TenantSecretCipher $cipher,
        private TenantSwitcher $switcher,
        private TenantProfileRepository $profiles,
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $tenantManager,
    ) {
    }

    /**
     * @return RotationReport what was rotated, skipped, and what could not be read
     */
    public function rotate(): RotationReport
    {
        $rotated = [];
        $mailRotated = [];
        $skipped = [];
        $failed = [];

        foreach ($this->tenants->findAllOrdered() as $tenant) {
            $slug = $tenant->getSlug();
            $ciphertext = $tenant->getEncryptedDatabasePassword();

            if ($ciphertext === null) {
                $failed[$slug] = 'no stored secret; re-provision this tenant';

                continue;
            }

            try {
                if ($this->rotateMailSecret($tenant)) {
                    $mailRotated[] = $slug;
                }
            } catch (\Throwable $e) {
                // The database password stays where it is: it is what opens the
                // database this just failed to write to, and moving it now would
                // make the retry harder rather than easier. \Throwable rather
                // than \RuntimeException because this one reaches a network — a
                // customer's database can simply be down.
                $failed[$slug] = sprintf('outgoing mail password: %s', $e->getMessage());

                continue;
            }

            try {
                if (!$this->cipher->needsRotation($ciphertext)) {
                    $skipped[] = $slug;

                    continue;
                }

                $this->reEncrypt($tenant, $ciphertext);
                $rotated[] = $slug;
            } catch (\RuntimeException $e) {
                // One unreadable row must not stop the rest: the remaining
                // tenants still need to move off the old key.
                $failed[$slug] = $e->getMessage();
            }
        }

        return new RotationReport($rotated, $mailRotated, $skipped, $failed, $this->cipher->activeKeyId());
    }

    private function reEncrypt(Tenant $tenant, string $ciphertext): void
    {
        $plaintext = $this->cipher->decrypt($ciphertext);
        $tenant->setEncryptedDatabasePassword($this->cipher->encrypt($plaintext));
        sodium_memzero($plaintext);

        $this->controlPlane->flush();
    }

    /**
     * @return bool whether there was one to move; a tenant sending through this
     *              instance has no SMTP password and is not a failure
     */
    private function rotateMailSecret(Tenant $tenant): bool
    {
        return $this->switcher->runFor($tenant, function (): bool {
            $profile = $this->profiles->current();
            $ciphertext = $profile->getEncryptedMailSmtpPassword();

            if ($ciphertext === null || $ciphertext === '' || !$this->cipher->needsRotation($ciphertext)) {
                return false;
            }

            $plaintext = $this->cipher->decrypt($ciphertext);
            $profile->setEncryptedMailSmtpPassword($this->cipher->encrypt($plaintext));
            sodium_memzero($plaintext);

            $this->tenantManager->persist($profile);
            $this->tenantManager->flush();

            return true;
        });
    }
}

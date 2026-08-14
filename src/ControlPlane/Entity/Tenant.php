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

namespace App\ControlPlane\Entity;

use App\ControlPlane\Repository\TenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One customer. Lives in the control-plane database only — never in a tenant
 * database (docs/architecture.md §4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenant')]
#[ORM\UniqueConstraint(name: 'uniq_tenant_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class Tenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, TenantDomain> */
    #[ORM\OneToMany(targetEntity: TenantDomain::class, mappedBy: 'tenant', cascade: ['persist', 'remove'])]
    private Collection $domains;

    #[ORM\Column(enumType: TenantStatus::class, length: 32)]
    private TenantStatus $status = TenantStatus::Provisioning;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $enabledModules = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $provisionedAt = null;

    /**
     * The tenant's database password, encrypted with TenantSecretCipher.
     *
     * Nullable in the schema so the column could be added without a destructive
     * migration (docs/architecture.md §4, expand/contract); required in practice — a tenant
     * without one cannot be connected to and has to be re-provisioned.
     */
    #[ORM\Column(name: 'database_password', type: 'text', nullable: true)]
    private ?string $encryptedDatabasePassword = null;

    public function __construct(
        /** Stable machine identifier; also the basis of the tenant's database name and role. */
        #[ORM\Column(length: 63)]
        private string $slug,
        #[ORM\Column(length: 255)]
        private string $name,
        /**
         * DBAL DSN of this tenant's database, *without* the password — that is
         * held encrypted in `database_password` and merged in at connect time,
         * so a dump of this table carries no usable credential.
         */
        #[ORM\Column(name: 'database_dsn', type: 'text')]
        private string $databaseDsn,
        #[ORM\Column(length: 64)]
        private string $plan = 'standard',
    ) {
        $this->domains = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDatabaseDsn(): string
    {
        return $this->databaseDsn;
    }

    public function getEncryptedDatabasePassword(): ?string
    {
        return $this->encryptedDatabasePassword;
    }

    /** @param string $ciphertext already encrypted; plaintext passwords never reach this entity */
    public function setEncryptedDatabasePassword(string $ciphertext): void
    {
        $this->encryptedDatabasePassword = $ciphertext;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    public function getStatus(): TenantStatus
    {
        return $this->status;
    }

    public function markProvisioned(TenantStatus $status = TenantStatus::Active): void
    {
        $this->status = $status;
        $this->provisionedAt = new \DateTimeImmutable();
    }

    public function setStatus(TenantStatus $status): void
    {
        $this->status = $status;
    }

    public function getProvisionedAt(): ?\DateTimeImmutable
    {
        return $this->provisionedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<string> */
    public function getEnabledModules(): array
    {
        return $this->enabledModules;
    }

    /** @param list<string> $modules */
    public function setEnabledModules(array $modules): void
    {
        $this->enabledModules = array_values(array_unique($modules));
    }

    public function hasModuleEnabled(string $module): bool
    {
        return \in_array($module, $this->enabledModules, true);
    }

    /** @return Collection<int, TenantDomain> */
    public function getDomains(): Collection
    {
        return $this->domains;
    }

    public function addDomain(string $hostname, bool $primary = false): TenantDomain
    {
        $domain = new TenantDomain($this, $hostname, $primary);
        $this->domains->add($domain);

        return $domain;
    }

    public function getPrimaryDomain(): ?TenantDomain
    {
        foreach ($this->domains as $domain) {
            if ($domain->isPrimary()) {
                return $domain;
            }
        }

        return $this->domains->first() ?: null;
    }
}

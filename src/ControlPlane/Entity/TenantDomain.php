<?php

declare(strict_types=1);

namespace App\ControlPlane\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A hostname routed to a tenant. Separate table rather than a JSON column on
 * Tenant: this is the lookup key of every single request, so it gets a real
 * unique index (docs/architecture.md §4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenant_domain')]
#[ORM\UniqueConstraint(name: 'uniq_tenant_domain_hostname', columns: ['hostname'])]
class TenantDomain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'domains')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        /** Lowercased, port-less hostname. 253 is the maximum length of a DNS name. */
        #[ORM\Column(length: 253)]
        private string $hostname,
        // `primary` is quoted in Postgres; name the column explicitly instead.
        #[ORM\Column(name: 'is_primary')]
        private bool $primary = false,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }
}

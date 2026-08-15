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

namespace App\Tenant\Entity;

use App\Tenant\Repository\TenantProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What this customer says about themselves: their company name, and the currency
 * their instance works in (XIV-12).
 *
 * In the customer's own database, next to their users and their definitions
 * (§8.1). It is their data, edited by them, and the control plane's `tenant.name`
 * is a different fact — the operator's label in the registry, which they cannot
 * see and would not want to be renaming.
 *
 * **One row, enforced by the primary key.** The id is a constant rather than a
 * sequence, so a second profile is a duplicate key rather than a thing to notice
 * later. Settings tables that allow two rows eventually have two.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity(repositoryClass: TenantProfileRepository::class)]
#[ORM\Table(name: 'tenant_profile')]
#[ORM\HasLifecycleCallbacks]
class TenantProfile
{
    /** @see the class docblock — the row is a singleton by primary key. */
    public const int ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ID;

    /**
     * Empty until somebody fills it in, rather than null.
     *
     * "Not set" is one state here, not two, and every reader of this asks the
     * same question — is there something to show — which `!== ''` answers without
     * a null check first.
     */
    #[ORM\Column(name: 'company_name', length: 255, options: ['default' => ''])]
    private string $companyName = '';

    /**
     * ISO 4217, e.g. `CHF`. Null means nobody has chosen.
     *
     * Null rather than a default, because a currency guessed for a customer is
     * wrong quietly: it would come out on the first priced thing they ever
     * printed. What is stored is the code, never a symbol or a formatted string —
     * symfony/intl turns it into either, in whatever language is being read.
     */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): void
    {
        $this->companyName = mb_substr(trim($companyName), 0, 255);
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    /** @param string|null $currency an ISO 4217 code; the caller checks it is one */
    public function setCurrency(?string $currency): void
    {
        $this->currency = $currency;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

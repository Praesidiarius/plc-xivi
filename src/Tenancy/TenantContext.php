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

namespace App\Tenancy;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\Exception\NoTenantResolvedException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds the tenant of the current request or console command.
 *
 * This is the only place the "current tenant" exists — deliberately a service,
 * never static state (docs/architecture.md §2). Under FrankenPHP the process outlives the
 * request, so it implements ResetInterface: autoconfiguration tags it
 * `kernel.reset`, and the container reset between requests empties it. A tenant
 * left over from a previous request is exactly the leak docs/architecture.md §7.4 warns about.
 *
 * Nothing here writes to the tenant connection; use TenantSwitcher to change
 * tenants, so that Doctrine's connection and identity map are dropped with it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantContext implements ResetInterface
{
    private ?Tenant $tenant = null;

    public function getTenant(): Tenant
    {
        return $this->tenant ?? throw NoTenantResolvedException::create();
    }

    public function tryGetTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    /** @internal use TenantSwitcher::switchTo() */
    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function reset(): void
    {
        $this->tenant = null;
    }
}

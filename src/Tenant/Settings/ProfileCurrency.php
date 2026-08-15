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

namespace App\Tenant\Settings;

use App\Tenancy\TenantContext;
use App\Tenant\Repository\TenantProfileRepository;
use Xivi\Core\Money\InstanceCurrency;

/**
 * The engine's currency question (XIV-11), answered from the tenant profile
 * (§8.6, XIV-12).
 *
 * The half of the boundary that belongs to the application: core declares the
 * interface and never learns what a tenant is, which is what keeps a field type
 * from reaching into the control plane or a customer's settings table on its own.
 *
 * **Nothing is remembered between requests.** The profile is read when asked, so
 * a currency changed on the settings page is in front of the next price rendered
 * — and a process that serves several tenants in sequence cannot hand one
 * customer's currency to the next (§7.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ProfileCurrency implements InstanceCurrency
{
    public function __construct(
        private TenantContext $context,
        private TenantProfileRepository $profiles,
    ) {
    }

    public function code(): ?string
    {
        // The console and the login page have no tenant, and asking the profile
        // repository outside one would query whatever connection is current.
        if (!$this->context->hasTenant()) {
            return null;
        }

        return $this->profiles->current()->getCurrency();
    }
}

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
use Xivi\Core\Payment\DefaultPaymentTerms;

/**
 * The engine's payment-terms question (XIV-67), answered from the tenant profile
 * (§8.6, XIV-12).
 *
 * The half of the boundary that belongs to the application, and deliberately the
 * same shape as {@see ProfileCurrency} beside it: core declares the interface and
 * never learns what a tenant is, so a deriver cannot reach into a customer's
 * settings table on its own.
 *
 * **Nothing is remembered between requests.** The profile is read when asked, so
 * a term changed on the settings page applies to the next invoice sent — and a
 * process serving several tenants in sequence cannot hand one customer's terms to
 * the next (§7.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ProfilePaymentTerms implements DefaultPaymentTerms
{
    public function __construct(
        private TenantContext $context,
        private TenantProfileRepository $profiles,
    ) {
    }

    public function days(): ?int
    {
        // The console and the login page have no tenant, and asking the profile
        // repository outside one would query whatever connection is current.
        if (!$this->context->hasTenant()) {
            return null;
        }

        return $this->profiles->current()->getPaymentTermsDays();
    }
}

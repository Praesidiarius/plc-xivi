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
use Xivi\Core\Money\DefaultVatMode;
use Xivi\Core\Money\VatMode;

/**
 * The engine's "do this installation's prices include VAT" question (XIV-116),
 * answered from the tenant profile (§8.6, XIV-12).
 *
 * The half of the boundary that belongs to the application, and deliberately the
 * same shape as {@see ProfileCurrency} and {@see ProfilePaymentTerms} beside it:
 * core declares the interface and never learns what a tenant is, so nothing in
 * the engine can reach into a customer's settings table on its own.
 *
 * **Nothing is remembered between requests.** The profile is read when asked, so
 * a shop that switches on inclusive pricing gets it on the next document they
 * start — and a process serving several tenants in sequence cannot hand one
 * customer's answer to the next (§7.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ProfileVatMode implements DefaultVatMode
{
    public function __construct(
        private TenantContext $context,
        private TenantProfileRepository $profiles,
    ) {
    }

    public function mode(): ?VatMode
    {
        // The console and the login page have no tenant, and asking the profile
        // repository outside one would query whatever connection is current.
        if (!$this->context->hasTenant()) {
            return null;
        }

        $stored = $this->profiles->current()->getVatMode();

        // `VatMode::of()` would turn null into `Excluded`, which is the right
        // reading of a *record's* empty field and the wrong reading of an
        // unanswered *setting*: this method's null means "nobody has been asked",
        // and the difference is that nothing then gets written onto a new
        // document at all. See DefaultVatMode::mode().
        return $stored === null ? null : VatMode::tryFrom($stored);
    }
}

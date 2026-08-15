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

/**
 * What to call this installation (XIV-12).
 *
 * Their own company name when they have set one, and the registry's label for
 * them otherwise. Two facts rather than one: `tenant.name` is what the operator
 * filed them under and is not theirs to change, so a customer who has said what
 * they are called should be reading that instead of it.
 *
 * Its own class because the rule now has two readers — the bar at the top of
 * every page, and the `[tenant.name]` marker a letter is written with (XIV-4) —
 * and a rule with two copies is a rule that eventually has two answers.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class InstanceName
{
    public function __construct(
        private TenantContext $context,
        private TenantProfileRepository $profiles,
    ) {
    }

    /** Empty outside a tenant — the login page and the console have none. */
    public function current(): string
    {
        $tenant = $this->context->tryGetTenant();

        if ($tenant === null) {
            return '';
        }

        $company = $this->profiles->current()->getCompanyName();

        return $company !== '' ? $company : $tenant->getName();
    }
}

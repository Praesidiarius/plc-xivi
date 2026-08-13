<?php

declare(strict_types=1);

namespace App\Tenancy\Exception;

use App\ControlPlane\Entity\Tenant;

/**
 * The tenant row carries no encrypted database password. Predates per-tenant
 * roles, or was written by hand — either way there is no credential to connect
 * with, and guessing one is not an option.
 */
final class TenantCredentialMissingException extends \RuntimeException
{
    public function __construct(public readonly Tenant $tenant)
    {
        parent::__construct(sprintf(
            'Tenant "%s" has no stored database password. It needs to be re-provisioned.',
            $tenant->getSlug(),
        ));
    }
}

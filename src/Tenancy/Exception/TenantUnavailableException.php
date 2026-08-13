<?php

declare(strict_types=1);

namespace App\Tenancy\Exception;

use App\ControlPlane\Entity\Tenant;

/** The tenant exists but its status forbids serving requests. */
final class TenantUnavailableException extends \RuntimeException
{
    public function __construct(public readonly Tenant $tenant)
    {
        parent::__construct(sprintf(
            'Tenant "%s" is %s and cannot serve requests.',
            $tenant->getSlug(),
            $tenant->getStatus()->value,
        ));
    }
}

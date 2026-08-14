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

namespace App\Tenancy\Exception;

use App\ControlPlane\Entity\Tenant;

/**
 * The tenant exists but its status forbids serving requests.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
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

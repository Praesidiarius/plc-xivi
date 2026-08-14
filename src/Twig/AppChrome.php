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

namespace App\Twig;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantContext;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * What every signed-in page needs around its content: whose installation this is,
 * and which modules they have.
 *
 * A Twig global rather than something each controller remembers to pass, since
 * forgetting it would mean a page with no navigation. Both accessors are lazy —
 * the login page renders without a tenant and must not query for modules.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class AppChrome
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MetadataRepository $metadata,
    ) {
    }

    public function getTenant(): ?Tenant
    {
        return $this->context->tryGetTenant();
    }

    /** @return list<ModuleDefinition> */
    public function getModules(): array
    {
        return $this->context->hasTenant() ? $this->metadata->all() : [];
    }
}

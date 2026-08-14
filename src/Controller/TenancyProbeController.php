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

namespace App\Controller;

use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reports which tenant the current Host resolved to, and which database the
 * tenant connection actually reached.
 *
 * This exists because tenant leakage under a long-running worker (docs/architecture.md §7.4)
 * is invisible from the outside: the wrong database still answers. Asking
 * Postgres itself, over the same connection the application uses, is the only
 * answer that cannot be faked by a stale service. Debug builds only.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenancyProbeController
{
    public function __construct(
        private readonly TenantContext $context,
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly Connection $tenantConnection,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    #[Route('/_tenancy/whoami', name: 'tenancy_whoami', methods: ['GET'])]
    public function __invoke(
        #[MapQueryParameter]
        bool $connect = true,
    ): JsonResponse {
        if (!$this->debug) {
            throw new NotFoundHttpException();
        }

        $tenant = $this->context->tryGetTenant();

        return new JsonResponse([
            'tenant' => $tenant?->getSlug(),
            'status' => $tenant?->getStatus()->value,
            // Straight from the connection, not from the tenant row: this is the
            // value that would expose a leaked connection.
            'database' => $connect && $tenant !== null
                ? $this->tenantConnection->fetchOne('SELECT current_database()')
                : null,
        ]);
    }
}

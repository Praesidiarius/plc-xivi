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

use App\Registry\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Xivi\Core\Metadata\MetadataCache;

/**
 * The only supported way to enter (or leave) a tenant.
 *
 * Switching tenants means more than swapping a DSN: the open connection points
 * at the previous tenant's database and the entity manager's identity map is
 * full of the previous tenant's objects. Under a long-running worker both
 * outlive the request unless they are dropped explicitly (docs/architecture.md §7.4), so
 * every switch drops both, unconditionally — closing a connection that was never
 * opened is free, and "was it the same tenant?" is not a question worth being
 * wrong about.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantSwitcher
{
    public const string CONNECTION = 'tenant';
    public const string ENTITY_MANAGER = 'tenant';

    public function __construct(
        private TenantContext $context,
        private ManagerRegistry $registry,
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private Connection $tenantConnection,
        private MetadataCache $metadata,
    ) {
    }

    public function switchTo(Tenant $tenant): void
    {
        $this->releaseTenantResources();
        $this->context->setTenant($tenant);
    }

    /**
     * Leave the tenant context entirely. Any subsequent use of the tenant
     * connection fails loudly rather than reaching the previous tenant.
     */
    public function clear(): void
    {
        $this->releaseTenantResources();
        $this->context->reset();
    }

    /**
     * Run a callback in the context of $tenant, restoring the previous tenant
     * (if any) afterwards. Used by console commands that iterate over tenants.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function runFor(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->context->tryGetTenant();
        $this->switchTo($tenant);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->clear() : $this->switchTo($previous);
        }
    }

    private function releaseTenantResources(): void
    {
        // Definitions belong to whichever tenant was current, and the objects are
        // bound to the connection about to be closed (XIV-53). Emptying the cache
        // here rather than keying it by tenant is the point: a console command
        // walking every customer is the one place a stale shape would be handed
        // to the next one, and that failure would look like the wrong labels
        // rather than like an error (§7.4).
        $this->metadata->clear();

        // Drops the identity map, and replaces the manager if it was closed by a
        // previous failure. Cheap while the manager is still an uninitialised proxy.
        $this->registry->resetManager(self::ENTITY_MANAGER);

        // Forces the next query to reconnect, which re-runs the connection
        // parameters through TenantConnectionMiddleware with the new tenant.
        $this->tenantConnection->close();
    }
}

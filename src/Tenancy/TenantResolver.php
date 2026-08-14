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

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\Exception\UnknownTenantHostException;

/**
 * Maps a request hostname to a tenant, from the control-plane database.
 *
 * Deliberately stateless: no memoisation across calls. A host→tenant map cached
 * in a worker process is a cross-tenant leak waiting to happen (docs/architecture.md §7.4),
 * and the lookup is one indexed query on a small table. If it ever shows up in a
 * profile, the fix is a cache with an explicit, invalidated key — not a static
 * array that quietly survives the request.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantResolver
{
    public function __construct(private TenantRepository $tenants)
    {
    }

    /**
     * @throws UnknownTenantHostException
     */
    public function resolve(string $host): Tenant
    {
        $hostname = self::normalize($host);

        return $this->tenants->findOneByHostname($hostname)
            ?? throw new UnknownTenantHostException($hostname);
    }

    /** Lowercase, without port or the trailing dot of a fully qualified name. */
    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return rtrim($host, '.');
    }
}

<?php

declare(strict_types=1);

namespace App\Tenancy\Dbal;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/** Registered on the `tenant` connection only; the control plane keeps its fixed DSN. */
#[AsMiddleware(connections: ['tenant'])]
final readonly class TenantConnectionMiddleware implements Middleware
{
    public function __construct(private TenantConnectionParameters $parameters)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TenantDriver($driver, $this->parameters);
    }
}

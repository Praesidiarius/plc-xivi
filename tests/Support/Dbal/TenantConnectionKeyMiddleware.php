<?php

declare(strict_types=1);

namespace App\Tests\Support\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Registered on the `tenant` connection in the test environment only; see
 * TenantConnectionKeyDriver for what it is for and why its priority matters.
 */
final readonly class TenantConnectionKeyMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new TenantConnectionKeyDriver($driver);
    }
}

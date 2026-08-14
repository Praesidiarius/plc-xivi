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

namespace App\Tenancy\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Substitutes the current tenant's connection parameters at connect time.
 *
 * Connect time is the point that matters: DBAL resolves parameters once per
 * physical connection, not once per container build, so a worker process that
 * closes the connection between requests (see TenantSwitcher) picks up the new
 * tenant here instead of reusing the previous one's socket.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly TenantConnectionParameters $parameters,
    ) {
        parent::__construct($driver);
    }

    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverConnection {
        return parent::connect($this->parameters->resolve($params));
    }
}

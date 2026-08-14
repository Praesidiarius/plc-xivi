<?php

declare(strict_types=1);

namespace App\Tests\Support\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Gives DAMA's static connection a key per tenant database.
 *
 * DAMA caches one open, transaction-wrapped connection per *configured*
 * connection, keyed by `dama.connection_key` — a value baked in when the
 * container is compiled. That is right for an application with one database and
 * wrong for this one: every tenant is served by the same configured connection,
 * so all of them would share the cached connection of whichever tenant happened
 * to open it first, and a test would read another tenant's database while
 * believing it had proved isolation.
 *
 * The key is taken from the resolved database name rather than from
 * TenantContext, so it cannot disagree with the database actually connected to.
 * That works because this runs *outside* TenantDriver: middlewares connect from
 * the lowest priority up, so by the time these parameters arrive they name the
 * tenant's own database.
 */
final class TenantConnectionKeyDriver extends AbstractDriverMiddleware
{
    private const string KEY = 'dama.connection_key';

    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverConnection {
        if (isset($params[self::KEY], $params['dbname'])) {
            $params[self::KEY] = $params[self::KEY] . '@' . $params['dbname'];
        }

        return parent::connect($params);
    }
}

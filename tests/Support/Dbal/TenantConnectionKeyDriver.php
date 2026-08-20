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

namespace App\Tests\Support\Dbal;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

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
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantConnectionKeyDriver extends AbstractDriverMiddleware
{
    private const string KEY = 'dama.connection_key';

    /**
     * Every database DAMA has cached a static connection to, in this process.
     *
     * Recorded here because this middleware is the last thing that sees the
     * connection parameters before StaticDriver does, so "will DAMA cache
     * this?" can be answered from exactly the two facts StaticDriver itself
     * uses: static connections are being kept, and the key is present. DAMA
     * offers no way to ask its private cache afterwards, and asking *before*
     * is what {@see SharesATenant} needs anyway. The question there is "may
     * this database still be deprovisioned?", and the answer stops being yes
     * the moment a static connection to it exists, because deprovisioning
     * terminates every session on the database ([XIV-94], deployment brief
     * §4.1) and a terminated connection in DAMA's cache poisons every test
     * that runs after it (XIV-148).
     *
     * @var array<string, true>
     */
    private static array $staticallyConnected = [];

    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        if (isset($params[self::KEY], $params['dbname'])) {
            $params[self::KEY] = $params[self::KEY] . '@' . $params['dbname'];

            if (StaticDriver::isKeepStaticConnections()) {
                self::$staticallyConnected[(string) $params['dbname']] = true;
            }
        }

        return parent::connect($params);
    }

    /** Whether DAMA holds a cached static connection to $database in this process. */
    public static function holdsStaticConnectionTo(string $database): bool
    {
        return isset(self::$staticallyConnected[$database]);
    }
}

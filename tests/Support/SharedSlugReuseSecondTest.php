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

namespace App\Tests\Support;

use App\Tenancy\TenantSwitcher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The second half of {@see SharedSlugReuseFirstTest}'s experiment (XIV-148).
 *
 * This class claims the slug the first class already claimed, then uses the
 * shared static connection the first class opened. Both steps carry the
 * assertion:
 *
 *  * the query answering at all is the safety property: on the broken trait
 *    the second claim deprovisioned the tenant, `pg_terminate_backend` killed
 *    DAMA's cached connection, and this query was the first of an
 *    ever-growing pile of "terminating connection due to administrator
 *    command" failures;
 *  * the registry id matching is the reuse property: a rebuilt tenant would
 *    answer the query happily on a fresh connection and still be the wrong
 *    outcome, because everything the first class committed would be gone.
 *
 * The id half only speaks when the first class ran in this process; under
 * paratest the two classes are strangers and there is nothing to compare.
 *
 * @author Nathanael Kammermann <nathanael.kammermann@gmail.com>
 */
final class SharedSlugReuseSecondTest extends KernelTestCase
{
    use SharesATenant;

    public function testClaimingTheSameSlugReusesTheTenantAndItsConnection(): void
    {
        self::bootKernel();

        $tenant = $this->sharedTenant(SharedSlugReuseFirstTest::SLUG, [SharedSlugReuseFirstTest::HOST]);

        $switcher = self::getContainer()->get(TenantSwitcher::class);
        \assert($switcher instanceof TenantSwitcher);

        $database = (string) $switcher->runFor($tenant, function (): string {
            $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
            \assert($connection instanceof Connection);

            return (string) $connection->fetchOne('SELECT current_database()');
        });

        self::assertStringEndsWith(
            SharedSlugReuseFirstTest::SLUG,
            $database,
            'the shared connection is still alive and still points at the shared tenant',
        );

        if (SharedSlugReuseFirstTest::$tenantId !== null) {
            self::assertSame(
                SharedSlugReuseFirstTest::$tenantId,
                $tenant->getId(),
                'the tenant was reused, not deprovisioned and made again',
            );
        }
    }
}

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
 * The first half of a two-class experiment on the harness itself (XIV-148).
 *
 * `SharesATenant` promises that two classes may ask for the same slug and the
 * second will reuse what the first made, because a deprovision at that moment
 * would terminate DAMA's cached connection out from under every test still to
 * run ([XIV-94]). That promise was broken for months without a single red test:
 * the bookkeeping behind it lived in a static property *on the trait*, and PHP
 * gives every class that uses a trait its own copy of its statics, so the
 * "already provisioned in this process" check was always asked of an empty
 * array, and the second class always deprovisioned. The six browser classes
 * share the `e2e` slug in exactly this way, which is where a serial run's
 * cascade of "terminating connection due to administrator command" came from.
 *
 * So the property is proved here rather than described, by doing the dangerous
 * thing on purpose: this class claims a slug and opens the shared static
 * connection to its database; {@see SharedSlugReuseSecondTest}, which sorts and
 * therefore runs directly after it, claims the same slug and then uses that
 * same connection. If the trait ever deprovisions on the second claim again,
 * the second class's query dies with the terminated connection, the exact
 * failure the suite saw at scale, instead of the defect surfacing 200 tests
 * later in a class that did nothing wrong.
 *
 * The pair proves nothing under paratest, and that is fine: the classes land on
 * different workers, each process claims the slug once, and both halves pass
 * trivially. The serial run is the one where the shared process state exists to
 * be tested, and the one that was broken. CI exercises it on every push,
 * because the coverage leg runs PHPUnit serially.
 *
 * @author Nathanael Kammermann <nathanael.kammermann@gmail.com>
 */
final class SharedSlugReuseFirstTest extends KernelTestCase
{
    use SharesATenant;

    public const string SLUG = 'test_shared_slug';
    public const string HOST = 'shared-slug.localhost';

    /**
     * Handed to the second class so it can tell reuse from rebuild: a reused
     * tenant keeps its registry id, a deprovision-and-reprovision gets a new
     * one. Null whenever the two classes run in different processes, which is
     * the second class's signal that there is nothing to compare.
     */
    public static ?int $tenantId = null;

    public function testClaimingTheSlugOpensTheSharedConnection(): void
    {
        self::bootKernel();

        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);
        self::$tenantId = $tenant->getId();

        // The query is the point, not the assertion: it is what creates DAMA's
        // static connection to this database, keyed by the database name. That
        // is the connection the second class inherits, and the one a
        // deprovision over there would terminate.
        self::assertSame(
            $this->objectPrefix() . self::SLUG,
            $this->askTheSharedConnection(),
            'the shared connection reaches the claimed tenant database',
        );
    }

    /** What this run names tenant databases after; see TenantIsolationTest for why it is read, not written down. */
    private function objectPrefix(): string
    {
        $prefix = self::getContainer()->getParameter('app.tenant_object_prefix');
        \assert(\is_string($prefix));

        return $prefix;
    }

    private function askTheSharedConnection(): string
    {
        $switcher = self::getContainer()->get(TenantSwitcher::class);
        \assert($switcher instanceof TenantSwitcher);
        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        return (string) $switcher->runFor($tenant, function (): string {
            $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
            \assert($connection instanceof Connection);

            return (string) $connection->fetchOne('SELECT current_database()');
        });
    }
}

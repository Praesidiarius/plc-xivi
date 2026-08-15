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

namespace App\Tests\Functional\Tenancy;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Entity\TenantStatus;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\Exception\NoTenantResolvedException;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tenant resolution layer end to end: two customers, two databases, one
 * process.
 *
 * The client is created with reboots disabled, so every request in a test reuses
 * one container instance — the FrankenPHP worker situation from docs/architecture.md §7.4,
 * minus the between-request container reset. Isolation therefore has to come
 * from TenantSwitcher dropping the connection and identity map, not from the
 * reset cleaning up afterwards.
 */
/**
 * Provisions and drops tenants of its own, so it stays outside DAMA's
 * transaction: a database cannot be created or dropped inside one, and a test
 * that proves one tenant cannot see another has to be looking at what is
 * actually committed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class TenantIsolationTest extends WebTestCase
{
    private const string ALPHA = 'test_alpha';
    private const string BETA = 'test_beta';
    private const string HALTED = 'test_halted';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->removeTenants();

        $provisioner = self::getContainer()->get(TenantProvisioner::class);
        \assert($provisioner instanceof TenantProvisioner);

        $provisioner->provision(self::ALPHA, 'Alpha', ['alpha.localhost']);
        $provisioner->provision(self::BETA, 'Beta', ['beta.localhost']);
        $provisioner->provision(self::HALTED, 'Halted', ['halted.localhost'], status: TenantStatus::Suspended);
    }

    protected function tearDown(): void
    {
        $this->removeTenants();

        parent::tearDown();
    }

    /**
     * What this run names tenant databases and roles after (XIV-9).
     *
     * Read rather than written down, because parallel workers each get their
     * own prefix so they cannot claim each other's cluster objects. The
     * assertion below is about *which* database a tenant reaches, not about the
     * string it is called, so taking the namespace from configuration keeps the
     * claim intact and stops the test asserting the test runner's own bookkeeping.
     */
    private function objectPrefix(): string
    {
        $prefix = self::getContainer()->getParameter('app.tenant_object_prefix');
        \assert(\is_string($prefix));

        return $prefix;
    }

    public function testEachHostReachesItsOwnDatabaseWithinOneProcess(): void
    {
        self::assertSame(
            ['tenant' => self::ALPHA, 'status' => 'active', 'database' => $this->objectPrefix() . self::ALPHA],
            $this->whoami('alpha.localhost'),
        );

        self::assertSame(
            ['tenant' => self::BETA, 'status' => 'active', 'database' => $this->objectPrefix() . self::BETA],
            $this->whoami('beta.localhost'),
        );

        // Back to the first one: the connection opened for beta must not be the
        // one alpha ends up using.
        self::assertSame(
            ['tenant' => self::ALPHA, 'status' => 'active', 'database' => $this->objectPrefix() . self::ALPHA],
            $this->whoami('alpha.localhost'),
        );
    }

    public function testUnknownHostIsRejected(): void
    {
        $this->client->request('GET', 'https://not-a-tenant.localhost/_tenancy/whoami');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testSuspendedTenantDoesNotServeRequests(): void
    {
        $this->client->request('GET', 'https://halted.localhost/_tenancy/whoami');

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->client->getResponse()->getStatusCode());
    }

    public function testSystemHostIsServedWithoutATenant(): void
    {
        self::assertSame(
            ['tenant' => null, 'status' => null, 'database' => null],
            $this->whoami('localhost'),
        );
    }

    /**
     * The tenant connection has no fallback: without a tenant it refuses to
     * connect rather than reaching whatever database it saw last.
     */
    public function testTenantConnectionRefusesToOpenWithoutATenant(): void
    {
        $this->whoami('alpha.localhost');
        $this->whoami('localhost');

        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        \assert($connection instanceof Connection);

        $this->expectException(NoTenantResolvedException::class);

        $connection->fetchOne('SELECT current_database()');
    }

    /** @return array<string, mixed> */
    private function whoami(string $host): array
    {
        $this->client->request('GET', sprintf('https://%s/_tenancy/whoami', $host));

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($payload));

        return $payload;
    }

    /** Drops the test tenants' rows, databases and roles, from a previous run or this one. */
    private function removeTenants(): void
    {
        $provisioner = self::getContainer()->get(TenantProvisioner::class);
        \assert($provisioner instanceof TenantProvisioner);

        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);

        foreach ([self::ALPHA, self::BETA, self::HALTED] as $slug) {
            $tenant = $tenants->findOneBySlug($slug);

            if ($tenant instanceof Tenant) {
                $provisioner->deprovision($tenant);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenancy\Security\TenantSecretCipher;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves that tenant isolation is enforced by Postgres, not only by the
 * application choosing the right DSN.
 *
 * This is the difference between "a bug leaks another customer's data" and "a
 * bug throws a connection error": with per-tenant roles and CONNECT revoked from
 * PUBLIC, credentials that reach the wrong database simply do not work.
 */
/**
 * Provisions and drops tenants of its own, so it stays outside DAMA's
 * transaction: a database cannot be created or dropped inside one, and a test
 * that proves one tenant cannot see another has to be looking at what is
 * actually committed.
 */
#[SkipDatabaseRollback]
final class TenantCredentialIsolationTest extends KernelTestCase
{
    private const string ALPHA = 'test_cred_alpha';
    private const string BETA = 'test_cred_beta';

    private TenantProvisioner $provisioner;

    protected function setUp(): void
    {
        self::bootKernel();

        $provisioner = self::getContainer()->get(TenantProvisioner::class);
        \assert($provisioner instanceof TenantProvisioner);
        $this->provisioner = $provisioner;

        $this->removeTenants();
        $this->provisioner->provision(self::ALPHA, 'Alpha', ['cred-alpha.localhost']);
        $this->provisioner->provision(self::BETA, 'Beta', ['cred-beta.localhost']);
    }

    protected function tearDown(): void
    {
        $this->removeTenants();

        parent::tearDown();
    }

    public function testEachTenantConnectsWithItsOwnCredentials(): void
    {
        $alpha = $this->tenant(self::ALPHA);

        $connection = DriverManager::getConnection($this->paramsFor($alpha));

        self::assertSame('tenant_' . self::ALPHA, $connection->fetchOne('SELECT current_database()'));
        self::assertSame('tenant_' . self::ALPHA, $connection->fetchOne('SELECT current_user'));

        $connection->close();
    }

    /** The whole point of the per-tenant role: alpha's credentials cannot open beta's database. */
    public function testOneTenantsCredentialsCannotOpenAnotherTenantsDatabase(): void
    {
        $params = $this->paramsFor($this->tenant(self::ALPHA));
        $params['dbname'] = 'tenant_' . self::BETA;

        $connection = DriverManager::getConnection($params);

        $this->expectException(DbalException::class);

        $connection->fetchOne('SELECT current_database()');
    }

    /** The stored DSN alone is not a credential — the password lives encrypted, in another column. */
    public function testStoredDsnCarriesNoPassword(): void
    {
        $dsn = $this->tenant(self::ALPHA)->getDatabaseDsn();

        self::assertStringContainsString('//tenant_' . self::ALPHA . '@', $dsn);
        self::assertArrayNotHasKey('password', $this->parser()->parse($dsn));
    }

    /** @return array<string, mixed> */
    private function paramsFor(Tenant $tenant): array
    {
        $cipher = self::getContainer()->get(TenantSecretCipher::class);
        \assert($cipher instanceof TenantSecretCipher);

        $ciphertext = $tenant->getEncryptedDatabasePassword();
        self::assertIsString($ciphertext);

        $params = $this->parser()->parse($tenant->getDatabaseDsn());
        $params['password'] = $cipher->decrypt($ciphertext);

        return $params;
    }

    private function parser(): TenantDsnParser
    {
        $parser = self::getContainer()->get(TenantDsnParser::class);
        \assert($parser instanceof TenantDsnParser);

        return $parser;
    }

    private function tenant(string $slug): Tenant
    {
        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);

        $tenant = $tenants->findOneBySlug($slug);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }

    private function removeTenants(): void
    {
        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);

        foreach ([self::ALPHA, self::BETA] as $slug) {
            $tenant = $tenants->findOneBySlug($slug);

            if ($tenant instanceof Tenant) {
                $this->provisioner->deprovision($tenant);
            }
        }
    }
}

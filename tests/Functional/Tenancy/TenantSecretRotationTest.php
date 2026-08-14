<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\Security\TenantSecretCipher;
use App\Tenancy\Security\TenantSecretRotator;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A full key rotation against real rows: the tenant keeps working throughout,
 * and the password that comes out the far side is the one Postgres knows.
 */
/**
 * Provisions and drops tenants of its own, so it stays outside DAMA's
 * transaction: a database cannot be created or dropped inside one, and a test
 * that proves one tenant cannot see another has to be looking at what is
 * actually committed.
 */
#[SkipDatabaseRollback]
final class TenantSecretRotationTest extends KernelTestCase
{
    private const string SLUG = 'test_rotation';
    private const string NEW_KEY_ID = 'test-rotated';

    private TenantProvisioner $provisioner;

    protected function setUp(): void
    {
        self::bootKernel();

        $provisioner = self::getContainer()->get(TenantProvisioner::class);
        \assert($provisioner instanceof TenantProvisioner);
        $this->provisioner = $provisioner;

        $this->removeTenant();
        $this->provisioner->provision(self::SLUG, 'Rotation', ['rotation.localhost']);
    }

    protected function tearDown(): void
    {
        $this->removeTenant();

        parent::tearDown();
    }

    public function testRotationReEncryptsWithoutChangingThePassword(): void
    {
        $deployed = $this->deployedCipher();
        $before = $this->storedSecret();

        self::assertSame($this->activeKeyId(), $deployed->keyIdOf($before));
        $password = $deployed->decrypt($before);

        // What an operator does: add a key, point the active id at it, run the job.
        $rotating = $this->cipherWithExtraKey();
        $report = (new TenantSecretRotator($this->tenants(), $this->controlPlane(), $rotating))->rotate();

        self::assertContains(self::SLUG, $report->rotated);
        self::assertTrue($report->isComplete());

        $after = $this->storedSecret();
        self::assertNotSame($before, $after);
        self::assertSame(self::NEW_KEY_ID, $rotating->keyIdOf($after));
        self::assertSame($password, $rotating->decrypt($after));
    }

    public function testRotationIsIdempotent(): void
    {
        $rotating = $this->cipherWithExtraKey();
        $rotator = new TenantSecretRotator($this->tenants(), $this->controlPlane(), $rotating);

        $rotator->rotate();
        $second = $rotator->rotate();

        self::assertSame([], $second->rotated);
        self::assertContains(self::SLUG, $second->skipped);
    }

    /** Nothing to do while the deployed key is still the active one. */
    public function testRotationSkipsTenantsAlreadyOnTheActiveKey(): void
    {
        $report = (new TenantSecretRotator($this->tenants(), $this->controlPlane(), $this->deployedCipher()))->rotate();

        self::assertContains(self::SLUG, $report->skipped);
        self::assertSame([], $report->rotated);
    }

    private function deployedCipher(): TenantSecretCipher
    {
        $cipher = self::getContainer()->get(TenantSecretCipher::class);
        \assert($cipher instanceof TenantSecretCipher);

        return $cipher;
    }

    /** The deployed keys plus a new one, which becomes active — mid-rotation state. */
    private function cipherWithExtraKey(): TenantSecretCipher
    {
        $keys = json_decode((string) ($_SERVER['TENANT_SECRET_KEYS'] ?? '{}'), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($keys));

        $keys[self::NEW_KEY_ID] = base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        return new TenantSecretCipher($keys, self::NEW_KEY_ID);
    }

    private function activeKeyId(): string
    {
        return (string) ($_SERVER['TENANT_SECRET_KEY_ID'] ?? '');
    }

    private function storedSecret(): string
    {
        $this->controlPlane()->clear();

        $secret = $this->tenant()->getEncryptedDatabasePassword();
        self::assertIsString($secret);

        return $secret;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenants()->findOneBySlug(self::SLUG);
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }

    private function tenants(): TenantRepository
    {
        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);

        return $tenants;
    }

    private function controlPlane(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.control_entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function removeTenant(): void
    {
        $tenant = $this->tenants()->findOneBySlug(self::SLUG);

        if ($tenant instanceof Tenant) {
            $this->provisioner->deprovision($tenant);
        }
    }
}

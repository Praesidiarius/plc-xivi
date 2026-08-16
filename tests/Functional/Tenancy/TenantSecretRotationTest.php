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
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\Security\TenantSecretCipher;
use App\Tenancy\Security\TenantSecretRotator;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Repository\TenantProfileRepository;
use App\Tenant\Settings\TenantProfileManager;
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
 *
 * **Every assertion names this test's own tenant**, and none of them is about
 * the registry as a whole. The registry is one database shared by every class in
 * the run and by the runs before it — tenants outlive a class on purpose (see
 * SharesATenant) — so `rotated === []` or `isComplete()` would be assertions
 * about somebody else's fixtures, true or false depending on what ran first.
 * Rotation is a job over everything there is, which is exactly why a test of it
 * has to be careful to speak only about what it owns.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class TenantSecretRotationTest extends KernelTestCase
{
    private const string SLUG = 'test_rotation';
    private const string NEW_KEY_ID = 'test-rotated';
    private const string SMTP_PASSWORD = 'the-smtp-password';

    /** Fixed for the whole class, so the booted kernel and these tests hold the same one. */
    private static string $newKey;

    /** What TENANT_SECRET_KEYS said before this class started pretending to be mid-rotation. */
    private static string $deployedKeys;

    private TenantProvisioner $provisioner;

    /**
     * The kernel is booted **holding both keys**, which is not a convenience but
     * the state a rotation actually runs in.
     *
     * An operator adds the new key beside the old one, points the active id at
     * it, and only removes the old one once this command reports nothing stale —
     * so the running application can read a value written with either the whole
     * time. It matters more than it used to (XIV-37): the job now opens each
     * customer's *own* database to reach their outgoing-mail password, and the
     * password it connects with is one of the values being rotated. A kernel that
     * knew only the old key would lock itself out of the second tenant it
     * touched, which is a property of the test rig and not of the feature.
     */
    protected function setUp(): void
    {
        self::$newKey ??= base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        self::$deployedKeys ??= (string) ($_SERVER['TENANT_SECRET_KEYS'] ?? '{}');

        $keys = json_decode(self::$deployedKeys, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($keys));
        $keys[self::NEW_KEY_ID] = self::$newKey;

        // Both, because Symfony's env resolution reads either depending on how
        // the variable arrived — Dotenv writes to both, a real environment to one.
        $_SERVER['TENANT_SECRET_KEYS'] = $_ENV['TENANT_SECRET_KEYS'] = json_encode($keys, \JSON_THROW_ON_ERROR);

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

        // Put the environment back for whatever runs next in this process: the
        // extra key is this class's fiction and nobody else's.
        $_SERVER['TENANT_SECRET_KEYS'] = $_ENV['TENANT_SECRET_KEYS'] = self::$deployedKeys;

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
        $report = $this->rotatorWith($rotating)->rotate();

        self::assertContains(self::SLUG, $report->rotated);
        self::assertArrayNotHasKey(self::SLUG, $report->failed);

        $after = $this->storedSecret();
        self::assertNotSame($before, $after);
        self::assertSame(self::NEW_KEY_ID, $rotating->keyIdOf($after));
        self::assertSame($password, $rotating->decrypt($after));
    }

    public function testRotationIsIdempotent(): void
    {
        $rotating = $this->cipherWithExtraKey();
        $rotator = $this->rotatorWith($rotating);

        $rotator->rotate();
        $second = $rotator->rotate();

        self::assertNotContains(self::SLUG, $second->rotated);
        self::assertContains(self::SLUG, $second->skipped);
    }

    /** Nothing to do while the deployed key is still the active one. */
    public function testRotationSkipsTenantsAlreadyOnTheActiveKey(): void
    {
        $report = $this->rotatorWith($this->deployedCipher())->rotate();

        self::assertContains(self::SLUG, $report->skipped);
        self::assertNotContains(self::SLUG, $report->rotated);
    }

    /**
     * The second secret, and the one whose absence from a rotation would not be
     * noticed until somebody sent an invoice (XIV-37).
     *
     * It lives in the *customer's* database rather than the registry, so what is
     * being proved is that the job crosses that boundary at all — a rotation
     * reporting "everything is on the active key" while leaving this behind is
     * how an operator drops a key that is still in use.
     */
    public function testRotationReachesTheOutgoingMailPasswordInTheTenantsOwnDatabase(): void
    {
        $this->configureOutgoingMail();

        $before = $this->storedMailSecret();
        self::assertNotNull($before);
        self::assertSame($this->activeKeyId(), $this->deployedCipher()->keyIdOf($before));

        $rotating = $this->cipherWithExtraKey();
        $report = $this->rotatorWith($rotating)->rotate();

        self::assertContains(self::SLUG, $report->mailRotated);
        self::assertArrayNotHasKey(self::SLUG, $report->failed);

        $after = $this->storedMailSecret();
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
        self::assertSame(self::NEW_KEY_ID, $rotating->keyIdOf($after));
        self::assertSame(self::SMTP_PASSWORD, $rotating->decrypt($after));
    }

    /** A customer sending through this instance has no such secret, and that is not a failure. */
    public function testATenantWithNoOutgoingMailPasswordIsNotReportedAsRotated(): void
    {
        $report = $this->rotatorWith($this->cipherWithExtraKey())->rotate();

        self::assertNotContains(self::SLUG, $report->mailRotated);
        self::assertContains(self::SLUG, $report->rotated);
        self::assertArrayNotHasKey(self::SLUG, $report->failed);
    }

    /**
     * The rotator as the container wires it, but with a cipher of this test's
     * choosing — which is the only part of a rotation an operator changes.
     */
    private function rotatorWith(TenantSecretCipher $cipher): TenantSecretRotator
    {
        $switcher = self::getContainer()->get(TenantSwitcher::class);
        \assert($switcher instanceof TenantSwitcher);

        $profiles = self::getContainer()->get(TenantProfileRepository::class);
        \assert($profiles instanceof TenantProfileRepository);

        $tenantManager = self::getContainer()->get('doctrine.orm.tenant_entity_manager');
        \assert($tenantManager instanceof EntityManagerInterface);

        return new TenantSecretRotator(
            $this->tenants(),
            $this->controlPlane(),
            $cipher,
            $switcher,
            $profiles,
            $tenantManager,
        );
    }

    /** Gives the tenant an SMTP server of its own, through the path the settings page uses. */
    private function configureOutgoingMail(): void
    {
        $profiles = self::getContainer()->get(TenantProfileManager::class);
        \assert($profiles instanceof TenantProfileManager);

        $this->switcher()->runFor(
            $this->tenant(),
            fn () => $profiles->applyMail('billing@rotation.test', 'smtp.rotation.test', 587, 'billing', self::SMTP_PASSWORD),
        );
    }

    private function storedMailSecret(): ?string
    {
        $profiles = self::getContainer()->get(TenantProfileRepository::class);
        \assert($profiles instanceof TenantProfileRepository);

        return $this->switcher()->runFor(
            $this->tenant(),
            fn (): ?string => $profiles->current()->getEncryptedMailSmtpPassword(),
        );
    }

    private function switcher(): TenantSwitcher
    {
        $switcher = self::getContainer()->get(TenantSwitcher::class);
        \assert($switcher instanceof TenantSwitcher);

        return $switcher;
    }

    private function deployedCipher(): TenantSecretCipher
    {
        $cipher = self::getContainer()->get(TenantSecretCipher::class);
        \assert($cipher instanceof TenantSecretCipher);

        return $cipher;
    }

    /** The same keys the kernel holds, with the new one active — mid-rotation state. */
    private function cipherWithExtraKey(): TenantSecretCipher
    {
        $keys = json_decode((string) ($_SERVER['TENANT_SECRET_KEYS'] ?? '{}'), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($keys));

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

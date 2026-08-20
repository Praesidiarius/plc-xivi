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

namespace App\Tests\Unit\ControlPlane;

use App\Registry\Repository\TenantRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Command\MigrateTenantsCommand;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;

/**
 * What `tenant:migrate` does when the registry has nobody in it (XIV-61, §4.2).
 *
 * ## Why this is a unit test and the rest of the contract is not
 *
 * {@see \App\Tests\Functional\ControlPlane\MigrateTenantsExitCodeTest} covers the
 * other exit codes against a real registry, which is right for them: a tenant
 * that fails to migrate is only meaningful with a database behind it.
 *
 * An empty registry cannot be reached that way without emptying the registry,
 * and the first version of this test did exactly that. **It is not a safe thing
 * to do here.** The suite shares a control-plane database between the classes in
 * a worker, and `SharesATenant` provisions with the rollback deliberately
 * switched off, because `CREATE DATABASE` cannot run in a transaction. So a
 * `DELETE FROM tenant` is not reliably inside the transaction that would undo it,
 * and I watched it take other cases in this class down with it: a run that had
 * passed six times came back with a tenant it expected to be current reporting
 * `TENANT_FAILED`, from a class that had not asked for any of this.
 *
 * The command needs a repository and a provisioner, and the empty case never
 * reaches the provisioner. A repository that answers with nothing is the whole
 * fixture, and it cannot damage anything.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MigrateTenantsAllowEmptyTest extends TestCase
{
    /**
     * **The default stays a failure, and that is the decision this protects.**.
     *
     * A registry that has lost its tenants looks from inside this command
     * exactly like one that never had any, and the first is a catastrophe worth
     * stopping a deploy over. Only the deployment knows which it is.
     */
    public function testAnEmptyRegistryStillStopsADeployByDefault(): void
    {
        self::assertSame(Command::FAILURE, $this->migrate(allowEmpty: false));
    }

    /**
     * The case the flag exists for: an instance waiting for its first
     * self-service signup is legitimately empty, and while it was, every deploy
     * to it failed before the serving containers were replaced. Removing the
     * last tenant from an installation should not make it undeployable.
     */
    public function testAnInstallationThatSaysItIsEmptyOnPurposeCanDeploy(): void
    {
        self::assertSame(Command::SUCCESS, $this->migrate(allowEmpty: true));
    }

    /**
     * Asserted as a pair with the case above, because the flag has to change
     * this answer and nothing else. A `--force` that made every empty-ish
     * situation succeed would pass the previous test just as well.
     */
    public function testTheFlagDoesNotExcuseASlugNothingAnswersTo(): void
    {
        self::assertSame(Command::FAILURE, $this->migrate(allowEmpty: true, slug: 'no-such-tenant'));
    }

    public function testTheRefusalSaysHowToSayItIsEmptyOnPurpose(): void
    {
        // A deploy that stops has to tell whoever reads the log what the choice
        // is. Without this line the only way to find the flag is the source.
        $output = new BufferedOutput();

        $this->migrate(allowEmpty: false, output: $output);

        self::assertStringContainsString('--allow-empty', $output->fetch());
    }

    private function migrate(
        bool $allowEmpty,
        ?string $slug = null,
        ?BufferedOutput $output = null,
    ): int {
        // A stub rather than a mock: nothing here asserts how the repository was
        // called, only what the command does when it answers with nothing.
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('findAllOrdered')->willReturn([]);
        $tenants->method('findOneBySlug')->willReturn(null);

        // **Built without its constructor, because it is final and is never
        // called.** The empty case returns before anything is migrated, so what
        // this argument needs to be is "an instance", and `TenantProvisioner` is
        // `final readonly` so PHPUnit cannot double it. Constructing it properly
        // would mean a database connection, an entity manager and a migration
        // factory for an object this test never touches.
        $provisioner = (new \ReflectionClass(TenantProvisioner::class))->newInstanceWithoutConstructor();

        $command = new MigrateTenantsCommand($tenants, $provisioner);
        $output ??= new BufferedOutput();

        return $command(new SymfonyStyle(new ArrayInput([]), $output), $slug, $allowEmpty);
    }
}

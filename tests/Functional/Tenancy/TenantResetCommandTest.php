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

use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Record\RecordRepository;

/**
 * `tenant:reset` end to end (XIV-72): a tenant thrown away and built again, with
 * the module order taken out of the blueprints and every refusal happening
 * before anything is destroyed.
 *
 * Two records per module, not three hundred. What is being tested is that the
 * steps happen in an order that works — the module that others need installed
 * first, and filled first so the ones after it have something to point at — and
 * a size that makes the point in two seconds makes it as well as one that takes
 * a minute.
 *
 * Outside DAMA's transaction for the reason the tests beside it are: databases
 * are created and dropped here, which cannot happen inside one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class TenantResetCommandTest extends KernelTestCase
{
    private const string SLUG = 'test_reset';
    private const string HOSTNAME = 'test_reset.localhost';
    private const int RECORDS = 2;

    private TenantProvisioner $provisioner;

    protected function setUp(): void
    {
        putenv('COLUMNS=200');

        self::bootKernel();

        $provisioner = self::getContainer()->get(TenantProvisioner::class);
        \assert($provisioner instanceof TenantProvisioner);
        $this->provisioner = $provisioner;

        $this->removeTenant();
        $this->provisioner->provision(self::SLUG, 'Reset', [self::HOSTNAME]);
    }

    protected function tearDown(): void
    {
        $this->removeTenant();

        putenv('COLUMNS');

        parent::tearDown();
    }

    /**
     * The whole point, in one call: the old tenant is gone, a new one is
     * standing with its modules installed and filled, and the password to sign
     * in with is on the screen.
     *
     * **`--modules=order,contact` is deliberately backwards.** An order cannot be
     * installed into a tenant with no contacts (XIV-23), so a run that obeyed the
     * list would fail on the first module — which makes success here the
     * assertion that the order came out of the blueprints instead.
     */
    public function testItRebuildsATenantWithItsModulesInTheOrderTheBlueprintsAsk(): void
    {
        $before = $this->tenant()->getEncryptedDatabasePassword();

        $tester = $this->reset(['--modules' => 'order,contact', '--records' => self::RECORDS, '--seed' => 7]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Password:', $tester->getDisplay(), 'the point of the exercise');
        self::assertStringContainsString('admin@' . self::HOSTNAME, $tester->getDisplay());

        self::assertSame(self::RECORDS, $this->countIn('contact'));
        self::assertSame(self::RECORDS, $this->countIn('order'));

        // A new role with a new password: the database was made again rather than
        // reused, which is the difference between a reset and a truncate.
        self::assertNotSame($before, $this->tenant()->getEncryptedDatabasePassword());
    }

    /** Twice over, since a scratch tenant is reset far more often than it is created. */
    public function testResettingAnAlreadyResetTenantLeavesTheAskedForSize(): void
    {
        $this->reset(['--modules' => 'contact', '--records' => 5])->assertCommandIsSuccessful();
        self::assertSame(5, $this->countIn('contact'));

        $this->reset(['--modules' => 'contact', '--records' => self::RECORDS])->assertCommandIsSuccessful();

        // Two, not seven: the first run's records went with the database rather
        // than being added to.
        self::assertSame(self::RECORDS, $this->countIn('contact'));
    }

    /**
     * The failure mode the ticket calls out by name. A developer who mistypes the
     * module list must not be left with less than they started with.
     */
    public function testAModuleSetThatCannotBeSatisfiedIsRefusedBeforeAnythingIsDestroyed(): void
    {
        $tester = $this->reset(['--modules' => 'order']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('cannot be installed without "contact"', $tester->getDisplay());
        self::assertStringContainsString('--modules=contact,order', $tester->getDisplay(), 'the corrected line, to paste');

        // Still standing, and still the one setUp made.
        self::assertInstanceOf(Tenant::class, $this->findTenant());
    }

    public function testAModuleThisBuildDoesNotCarryIsRefusedBeforeAnythingIsDestroyed(): void
    {
        $tester = $this->reset(['--modules' => 'ghost']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('No module named "ghost"', $tester->getDisplay());
        self::assertInstanceOf(Tenant::class, $this->findTenant());
    }

    /** A hostname somebody else answers on would make provisioning throw — after the drop. */
    public function testAHostnameAnotherTenantOwnsIsRefusedBeforeAnythingIsDestroyed(): void
    {
        $this->provisioner->provision('test_reset_other', 'Other', ['test_reset_other.localhost']);

        // Removed by tearDown rather than here: the assertions below clear the
        // control-plane manager to read past its identity map, which detaches
        // whatever this method was holding.
        $tester = $this->reset(['hostnames' => ['test_reset_other.localhost'], '--modules' => 'contact']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('belongs to tenant "test_reset_other"', $tester->getDisplay());
        self::assertInstanceOf(Tenant::class, $this->findTenant());
    }

    /** Modules with nothing in them, for somebody who wants an empty tenant to type into. */
    public function testZeroRecordsInstallsTheModulesAndFillsNothing(): void
    {
        $this->reset(['--modules' => 'contact', '--records' => 0])->assertCommandIsSuccessful();

        self::assertSame(0, $this->countIn('contact'));
    }

    /**
     * XIV-74, from the side that can be asserted on in ten seconds.
     *
     * The bug was that the whole reset happens in **one** process while Doctrine's
     * development query log keeps every statement it sees, with its parameters and
     * a backtrace each — so a run at `--records=2000` exhausted 128 MB before the
     * first module had finished filling. What cannot be tested here is the fatal
     * itself: it is a fatal, it needs thousands of records to reach, and a test
     * that provoked it would take the worker down with it.
     *
     * What *can* be tested is the property that makes the fatal impossible. The
     * log is emptied at every seam and after every generated batch, so the number
     * of statements it is still holding when the command returns is a function of
     * nothing — not of the modules, and above all not of `--records`, which is the
     * knob that broke it. Four hundred contacts is two batches and several
     * thousand statements; if more than a handful survive, the resets have stopped
     * happening and the ceiling is back.
     */
    public function testTheQueryLogDoesNotGrowWithTheNumberOfRecords(): void
    {
        $queryLog = self::getContainer()->get('doctrine.debug_data_holder');
        \assert($queryLog instanceof DebugDataHolder);
        $queryLog->reset();

        $this->reset(['--modules' => 'contact', '--records' => 400])->assertCommandIsSuccessful();

        $held = array_sum(array_map(\count(...), $queryLog->getData()));

        self::assertLessThan(
            50,
            $held,
            'the reset left the whole run in the query log; XIV-74 is back',
        );
    }

    /**
     * The other half of XIV-74: a reset destroys before it builds, so a failure
     * after the drop costs the tenant. It is not made impossible — §4.1 argues why
     * a temporary slug and a rename were rejected — so what it owes instead is a
     * precise account of what is gone, what is standing and what to type next.
     *
     * **An empty `--admin-email` is the provocation**, and it is a good one for
     * two reasons beyond being easy to arrange: it fails after the deprovision and
     * after the provision, which is the worst moment; and `UserCreator` refuses it
     * with an `\InvalidArgumentException`, which the handler this replaces —
     * `catch (\RuntimeException)` — would have walked straight past, leaving a
     * stack trace that never mentioned the database it had just dropped.
     */
    public function testAFailureAfterTheDestructionSaysExactlyWhatIsLeft(): void
    {
        $tester = $this->tester();

        try {
            $tester->execute([
                'slug' => self::SLUG,
                '--modules' => 'contact,article',
                '--records' => 1,
                '--admin-email' => ' ',
            ], ['interactive' => false]);

            self::fail('an empty admin email should not have been accepted');
        } catch (\InvalidArgumentException) {
            // Re-thrown on purpose, so Symfony renders it and `-v` still has a
            // stack trace to show. The report below is printed before it.
        }

        $display = $tester->getDisplay();

        self::assertStringContainsString('Where "test_reset" stands now', $display);
        self::assertStringContainsString('destroyed', $display, 'the old tenant is gone and must be said to be');
        self::assertStringContainsString('a row for "test_reset" in status "active"', $display, 'read back, not guessed');
        self::assertStringContainsString('not created', $display, 'the admin user is the step that failed');
        self::assertStringContainsString('contact not reached; article not reached', $display);

        // The line to paste, with the options that were typed still on it.
        self::assertStringContainsString(
            'bin/console tenant:reset test_reset --modules=contact,article --records=1',
            $display,
        );

        // And it is true: the wreckage is a tenant this command can reset again.
        self::assertInstanceOf(Tenant::class, $this->findTenant());
        $this->reset(['--modules' => 'contact', '--records' => 1])->assertCommandIsSuccessful();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function reset(array $arguments): CommandTester
    {
        $tester = $this->tester();

        // Unattended, which for *this* command is allowed to go ahead — the
        // confirmation defaults to yes and the command does not exist in a build
        // that has customers. `tenant:deprovision` is the opposite and its own
        // test says so.
        $tester->execute(['slug' => self::SLUG] + $arguments, ['interactive' => false]);

        return $tester;
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        return new CommandTester((new Application($kernel))->find('tenant:reset'));
    }

    private function countIn(string $moduleKey): int
    {
        $switcher = self::getContainer()->get(TenantSwitcher::class);
        \assert($switcher instanceof TenantSwitcher);

        $metadata = self::getContainer()->get(MetadataRepository::class);
        \assert($metadata instanceof MetadataRepository);

        $records = self::getContainer()->get(RecordRepository::class);
        \assert($records instanceof RecordRepository);

        return $switcher->runFor(
            $this->tenant(),
            static fn (): int => $records->countAll($metadata->get($moduleKey)),
        );
    }

    private function tenant(): Tenant
    {
        $tenant = $this->findTenant();
        self::assertInstanceOf(Tenant::class, $tenant);

        return $tenant;
    }

    private function findTenant(): ?Tenant
    {
        $controlPlane = self::getContainer()->get('doctrine.orm.control_entity_manager');
        \assert($controlPlane instanceof EntityManagerInterface);
        $controlPlane->clear();

        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);

        return $tenants->findOneBySlug(self::SLUG);
    }

    private function removeTenant(): void
    {
        foreach ([self::SLUG, 'test_reset_other'] as $slug) {
            $tenants = self::getContainer()->get(TenantRepository::class);
            \assert($tenants instanceof TenantRepository);

            $tenant = $tenants->findOneBySlug($slug);

            if ($tenant instanceof Tenant) {
                $this->provisioner->deprovision($tenant);
            }
        }
    }
}

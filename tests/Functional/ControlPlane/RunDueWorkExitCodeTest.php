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

namespace App\Tests\Functional\ControlPlane;

use App\Registry\Entity\Tenant;
use App\Registry\Entity\TenantStatus;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Settings\DisplayTimezone;
use App\Tests\Support\Module\JobModule;
use App\Tests\Support\Schedule\RehearsedWork;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Xivi\ControlPlane\Command\RunDueWorkCommand;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Schedule\DueWorkRunner;
use Xivi\Core\Schedule\Occurrence;

/**
 * What the clock tells the thing that called it (XIV-155, §4.2's three codes).
 *
 * The walk is `tenant:migrate`'s, so the exit codes are too, and they are a
 * contract rather than a detail: a run in which one customer's invoice generation
 * failed must not look to cron like a run in which there was nothing to do. That
 * kind of contract regresses invisibly: a `return Command::FAILURE` typed out of
 * habit changes no behaviour anybody can see and breaks every caller reading the
 * number, so the codes are asserted rather than described.
 *
 * **Every run here is scoped with `--slug`**, for the reason
 * {@see MigrateTenantsExitCodeTest} gives: a whole-registry run would sweep up
 * every other test class's leftover customers and its exit code would be a fact
 * about the suite. The one exception is the empty-registry case, which cannot be
 * scoped by definition and is built out of a repository that answers "nobody"
 * instead.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RunDueWorkExitCodeTest extends KernelTestCase
{
    use SharesATenant;

    /** A real customer, with the rehearsal's module installed. */
    private const string HEALTHY = 'test_xiv155_healthy';
    private const string HEALTHY_HOST = 'xiv155-healthy.test';

    /** A row whose provisioning died before a credential was ever stored (§4.1). */
    private const string BROKEN = 'test_xiv155_no_credential';

    /** Points nowhere, and nothing here ever connects to it. */
    private const string BROKEN_DSN = 'postgresql://xiv155role@xiv155-nowhere.invalid:5432/xiv155db?serverVersion=18';

    private Tenant $tenant;
    private RehearsedWork $work;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->tenant = $this->sharedTenant(self::HEALTHY, [self::HEALTHY_HOST]);
        $this->work = self::service(RehearsedWork::class);
        $this->work->reset();

        // Both ends, for the reason MigrateTenantsExitCodeTest spells out: the
        // provisioning in `sharedTenant()` runs with DAMA switched off, so what
        // the first test of the class writes to the registry is committed rather
        // than rolled back.
        $this->forgetBrokenTenant();

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            if (!self::service(MetadataRepository::class)->isInstalled(JobModule::KEY)) {
                self::service(ModuleInstaller::class)->install(
                    self::service(ModuleRegistry::class)->get(JobModule::KEY),
                );
            }
        });
    }

    protected function tearDown(): void
    {
        $this->forgetBrokenTenant();

        parent::tearDown();
    }

    /** A customer with something due: it happens, and the run says so. */
    public function testACustomerWithWorkDueRunsItAndExitsSuccessfully(): void
    {
        $this->work->offer = [$this->anOccurrence()];

        $tester = $this->clock(self::HEALTHY);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $this->work->ran);
        self::assertStringContainsString('1 occurrence(s) run', $tester->getDisplay());
    }

    /** And a customer with nothing due is not a failure, it is an ordinary hour. */
    public function testACustomerWithNothingDueExitsSuccessfullyAndSaysNothing(): void
    {
        $tester = $this->clock(self::HEALTHY);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('0 occurrence(s) run', $tester->getDisplay());
    }

    /**
     * The acceptance criterion, stated as the comparison it is: a customer whose
     * work failed is distinguishable from one whose work did not.
     */
    public function testATenantThatFailsIsDistinguishableFromOneThatDidNot(): void
    {
        $this->addBrokenTenant();

        $failed = $this->clock(self::BROKEN)->getStatusCode();
        $succeeded = $this->clock(self::HEALTHY)->getStatusCode();

        self::assertNotSame($succeeded, $failed);
        self::assertSame(RunDueWorkCommand::TENANT_FAILED, $failed);
    }

    /** And from a run that could not happen at all, which is the other 1. */
    public function testATenantThatFailsIsDistinguishableFromARunThatCouldNotHappen(): void
    {
        $this->addBrokenTenant();

        self::assertSame(Command::FAILURE, $this->clock('test_xiv155_no_such_tenant')->getStatusCode());
        self::assertSame(RunDueWorkCommand::TENANT_FAILED, $this->clock(self::BROKEN)->getStatusCode());
    }

    /**
     * Work that failed inside a reachable customer is the *same* 3 as a customer
     * that could not be reached at all, and it names both the tenant and the
     * occurrence.
     *
     * The two are one exit code on purpose, because from cron's side they are
     * both "go and look at this customer", and the output is where they are told
     * apart.
     */
    public function testWorkThatFailsInsideATenantExitsThreeAndNamesIt(): void
    {
        $this->work->offer = [$this->anOccurrence()];
        $this->work->cannotRun = ['7'];

        $tester = $this->clock(self::HEALTHY);

        self::assertSame(RunDueWorkCommand::TENANT_FAILED, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString(self::HEALTHY, $display);
        self::assertStringContainsString(RehearsedWork::KEY, $display);
        self::assertStringContainsString('--slug=' . self::HEALTHY, $display, 'and how to try it again');
        self::assertStringContainsString('Nothing is lost', $display);
    }

    /**
     * **A customer who is not being served is not being billed either.**.
     *
     * `suspended` is somebody's decision that this instance does nothing, and a
     * clock that went on raising their invoices while their staff cannot sign in
     * would be that decision quietly not taken. It is a skip and not a failure:
     * nothing is wrong.
     */
    public function testASuspendedCustomerIsSkippedRatherThanRun(): void
    {
        $this->work->offer = [$this->anOccurrence()];
        $this->suspendTheCustomer();

        $tester = $this->clock(self::HEALTHY);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $this->work->ran, 'nothing was run in a customer who is not being served');
        // A short substring, because SymfonyStyle wraps its success block at
        // the terminal width and an assertion on the whole sentence is really an
        // assertion about where the line broke.
        self::assertStringContainsString('not serving requests', $tester->getDisplay());
    }

    /**
     * **An empty registry is 0 here, and 1 in `tenant:migrate`**, which is the one
     * place this command departs from the shape it copied.
     *
     * `tenant:migrate` runs once per release inside a deploy, where stopping to
     * ask about a registry that appears to have lost its customers is cheap and
     * right. This runs every hour, unattended, for ever, and an installation
     * waiting for its first self-service signup (§8.14) is a real state that lasts
     * as long as it takes somebody to fill in a form. A clock that mailed the
     * operator a failure every hour throughout it would teach them to filter its
     * mail, which is §4.5's own failure arriving through the channel built to
     * prevent it.
     *
     * The command is built by hand here with a registry that answers "nobody",
     * because emptying the real one is not something a test in a shared cluster
     * gets to do.
     */
    public function testAnInstallationWithNoCustomersIsNotAFailure(): void
    {
        // A stub rather than a mock: nothing here is about how many times the
        // command asks, only about what it does with the answer.
        $registry = $this->createStub(TenantRepository::class);
        $registry->method('findAllOrdered')->willReturn([]);

        $command = new RunDueWorkCommand(
            $registry,
            self::service(TenantSwitcher::class),
            self::service(DueWorkRunner::class),
            self::service(DisplayTimezone::class),
        );

        $output = new BufferedOutput();
        $code = $command(new SymfonyStyle(new ArrayInput([]), $output));

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('nothing is due', $output->fetch());
    }

    /** One definition, one period, on a boundary rather than on a clock reading. */
    private function anOccurrence(): Occurrence
    {
        return new Occurrence('7', new \DateTimeImmutable('2026-08-01 00:00:00', new \DateTimeZone('UTC')));
    }

    /**
     * The customer suspended, through the entity manager the command will read
     * from.
     *
     * Not `$this->tenant->setStatus()`: that object came out of the kernel
     * `sharedTenant()` provisioned with, and the command loads its own from the
     * registry, so setting the status on this copy changes nothing the command
     * can see. Rolled back with the test like everything else written here.
     */
    private function suspendTheCustomer(): void
    {
        $manager = self::service(EntityManagerInterface::class);
        $tenant = self::service(TenantRepository::class)->findOneBySlug(self::HEALTHY);
        \assert($tenant instanceof Tenant);

        $tenant->setStatus(TenantStatus::Suspended);
        $manager->flush();
    }

    /** A registry row and nothing else: no database, no role, no credential. */
    private function addBrokenTenant(): void
    {
        $manager = self::service(EntityManagerInterface::class);
        $tenant = new Tenant(self::BROKEN, 'Customer whose database went away', self::BROKEN_DSN);

        // **Active, and that is the fixture rather than a detail.** A brand-new
        // registry row is `provisioning`, which this command skips before it
        // opens anything, so a `provisioning` row would prove that the skip
        // works and nothing about what happens when a customer who *is* being
        // served cannot be reached. That is the failure a deploy trips over and
        // the one this class is about.
        $tenant->setStatus(TenantStatus::Active);

        $manager->persist($tenant);
        $manager->flush();
    }

    private function forgetBrokenTenant(): void
    {
        $manager = self::service(EntityManagerInterface::class);
        $manager->getConnection()->executeStatement(
            'DELETE FROM tenant WHERE slug = :slug',
            ['slug' => self::BROKEN],
        );
        $manager->clear();
    }

    private function clock(string $slug): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        $tester = new CommandTester((new Application($kernel))->find('tenant:work:run'));
        $tester->execute(['--slug' => $slug]);

        return $tester;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}

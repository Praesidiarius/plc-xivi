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
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Xivi\ControlPlane\Command\MigrateTenantsCommand;

/**
 * "Migrated 49 of 50" must not look like success to the thing that called it
 * (XIV-61, docs/architecture/deployment.md §4.2).
 *
 * ## What was wrong, and it was not the loop
 *
 * `tenant:migrate` catches per tenant and carries on, which is right: one
 * customer's unreachable database must not cost the other forty-nine theirs,
 * because stopping at the first failure leaves everybody after it in the
 * registry serving new code against the old schema. That behaviour is not
 * changed here and is not what this class is about.
 *
 * What was wrong is what it told the caller afterwards. Measured on `main`
 * before this ticket, `tenant:migrate` with an empty registry exited **1**,
 * `tenant:migrate --slug=nope` exited **1**, and a run in which some tenants
 * failed exited **1**. So a deploy script could not distinguish "there is
 * nothing to do" from "one of your customers is on the wrong schema and is
 * being served by the new code right now", and the safest thing it could do
 * with that information was treat a healthy installation with no customers as a
 * failed deploy.
 *
 * The three codes are asserted here rather than described, because a contract a
 * caller reads is the one kind of thing that regresses invisibly: a `return
 * Command::FAILURE` typed out of habit changes no behaviour anybody can see and
 * breaks every deploy that reads the number.
 *
 * ## Why the failing tenant has no database rather than a broken one
 *
 * The fixture is a registry row with **no stored credential**, which
 * `TenantConnectionParameters` refuses before it opens a socket. Two reasons,
 * and the second is the one that made the choice:
 *
 *   * It is instant and cannot flake. A DSN pointing at a host that does not
 *     resolve depends on how long the container's resolver takes to say so, and
 *     a test whose duration is a DNS timeout is a test somebody eventually
 *     marks skipped.
 *   * **It is a real state.** §4.1 describes it exactly: a tenant whose
 *     provisioning died halfway leaves a row that no credential was ever written
 *     to. That is precisely the customer a deploy trips over, so the fixture is
 *     the failure rather than a stand-in for it.
 *
 * ## Why every run here is scoped with `--slug`
 *
 * A whole-registry run would sweep up every other test class's leftovers —
 * `SharesATenant` leaves its databases standing on purpose, and `bin/ci`
 * reclaims them on the way in rather than on the way out — so its exit code
 * would be a fact about the suite rather than about this command. The broken row
 * below is written inside the test transaction and rolled back with it, which is
 * the other half of the same care.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MigrateTenantsExitCodeTest extends KernelTestCase
{
    use SharesATenant;

    /** A real customer, provisioned and therefore already at the latest version. */
    private const string HEALTHY = 'test_xiv61_healthy';
    private const string HEALTHY_HOST = 'xiv61-healthy.test';

    /** A row whose provisioning died before a credential was ever stored (§4.1). */
    private const string BROKEN = 'test_xiv61_no_credential';

    /**
     * Points nowhere, and nothing here ever connects to it: the refusal happens
     * a step earlier, when the missing password is asked for.
     */
    private const string BROKEN_DSN = 'postgresql://xiv61role@xiv61-nowhere.invalid:5432/xiv61db?serverVersion=18';

    protected function setUp(): void
    {
        self::bootKernel();

        $this->sharedTenant(self::HEALTHY, [self::HEALTHY_HOST]);

        // Both ends, and that is not belt and braces — it is the one detail
        // this class had to learn the hard way. `sharedTenant()` provisions with
        // DAMA's static connections switched off, because `CREATE DATABASE`
        // cannot run inside a transaction; the connection the *first* test then
        // works on is therefore a plain one, and everything it writes to the
        // registry is committed rather than rolled back. So the broken row
        // outlives exactly one test in the class, which presents as a unique-key
        // violation in the second and reads like anything but its real cause.
        //
        // Removing it at both ends costs one statement and is correct whichever
        // side of that DAMA is on: when the transaction really does roll back,
        // both the insert and this delete go with it.
        $this->forgetBrokenTenant();
    }

    protected function tearDown(): void
    {
        $this->forgetBrokenTenant();

        parent::tearDown();
    }

    public function testATenantAlreadyAtTheLatestVersionExitsSuccessfully(): void
    {
        $tester = $this->migrate(self::HEALTHY);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('already up to date', $tester->getDisplay());
    }

    /**
     * The acceptance criterion, stated as the comparison it actually is: the two
     * exit codes differ, so a caller can tell the two situations apart.
     */
    public function testATenantThatFailsIsDistinguishableFromOneThatDidNot(): void
    {
        $this->addBrokenTenant();

        $failed = $this->migrate(self::BROKEN)->getStatusCode();
        $succeeded = $this->migrate(self::HEALTHY)->getStatusCode();

        self::assertNotSame($succeeded, $failed);
        self::assertSame(MigrateTenantsCommand::TENANT_FAILED, $failed);
    }

    /**
     * And distinguishable from the run that never happened, which is the
     * distinction that did not exist before: both used to be 1.
     */
    public function testATenantThatFailsIsDistinguishableFromARunThatCouldNotHappen(): void
    {
        $this->addBrokenTenant();

        // The two codes are compared against the two situations rather than
        // against each other: `assertNotSame(Command::FAILURE,
        // MigrateTenantsCommand::TENANT_FAILED)` would be two constants and
        // PHPStan is right to call that already-known. What is not already known
        // is which code each of these runs actually produces.
        self::assertSame(Command::FAILURE, $this->migrate('test_xiv61_no_such_tenant')->getStatusCode());
        self::assertSame(MigrateTenantsCommand::TENANT_FAILED, $this->migrate(self::BROKEN)->getStatusCode());
    }

    /**
     * The flag is about an empty registry and nothing else.
     *
     * A slug nothing answers to is a typo, or a tenant that has gone missing
     * since somebody wrote the retry line down. Neither is an installation that
     * is empty on purpose, and both share an exit code with one, so this is the
     * case where the distinction could quietly be lost.
     */
    public function testAllowEmptyDoesNotExcuseASlugNothingAnswersTo(): void
    {
        self::assertSame(
            Command::FAILURE,
            $this->migrate('test_xiv61_no_such_tenant', allowEmpty: true)->getStatusCode(),
        );
    }

    /**
     * A deploy captures this output and a person reads it later, so it has to
     * carry the slug and the line to type — an exit code alone tells somebody
     * that something is wrong and nothing about which customer.
     */
    public function testTheFailureNamesTheTenantAndHowToRetryIt(): void
    {
        $this->addBrokenTenant();

        $display = $this->migrate(self::BROKEN)->getDisplay();

        self::assertStringContainsString(self::BROKEN, $display);
        self::assertStringContainsString('--slug=' . self::BROKEN, $display);
    }

    /**
     * A registry row and nothing else — no database, no role, no credential.
     *
     * Written through the default entity manager, so DAMA rolls it back with the
     * test and the next one starts from a registry this class has not touched.
     */
    private function addBrokenTenant(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($manager instanceof EntityManagerInterface);

        $manager->persist(new Tenant(self::BROKEN, 'Half-provisioned Customer', self::BROKEN_DSN));
        $manager->flush();
    }

    /**
     * The row gone from the registry, whether or not it is there.
     *
     * DBAL rather than the ORM: the entity manager's identity map is a
     * complication with nothing to offer here, and a plain `DELETE` on a slug is
     * both what this means and safe to run when there is nothing to delete. The
     * row has no domains and nothing references it, so there is no order to get
     * right.
     */
    private function forgetBrokenTenant(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($manager instanceof EntityManagerInterface);

        $manager->getConnection()->executeStatement(
            'DELETE FROM tenant WHERE slug = :slug',
            ['slug' => self::BROKEN],
        );

        $manager->clear();
    }

    private function migrate(string $slug, bool $allowEmpty = false): CommandTester
    {
        return $this->migrateWith($allowEmpty ? ['--slug' => $slug, '--allow-empty' => true] : ['--slug' => $slug]);
    }

    /**
     * @param array<string, bool|string> $input
     */
    private function migrateWith(array $input): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        $tester = new CommandTester((new Application($kernel))->find('tenant:migrate'));
        $tester->execute($input);

        return $tester;
    }
}

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

namespace App\Tests\Functional\Tenant;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\PermissionGroup;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\PermissionResolver;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * Resolving what one person may do, from their groups and their own grants
 * (§7.5).
 *
 * The claim under test is that resolution is a *union with no denies*, so the
 * order grants are found in cannot change the answer — and that everybody starts
 * with nothing, because the alternative is an upgrade that quietly hands out
 * access nobody chose to give.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PermissionResolutionTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_permissions';
    private const string HOST = 'permissions.localhost';
    private const string ADMIN = 'admin@permissions.test';
    private const string MEMBER = 'member@permissions.test';
    private const string OTHER = 'other@permissions.test';
    private const string PASSWORD = 'a-long-enough-password';
    private const string MODULE = ContactModule::KEY;

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(self::MODULE),
        ));

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);
        $users->create($this->tenant, self::OTHER, 'Other', self::PASSWORD, []);
    }

    /**
     * The default, and the whole reason the migration writes no grants: a person
     * who has been given nothing has nothing.
     */
    public function testAUserWithNoGrantsMayDoNothing(): void
    {
        $this->inTenant(function (): void {
            $set = $this->resolve(self::MEMBER);

            foreach (ModuleAction::cases() as $action) {
                self::assertFalse(
                    $set->allows(self::MODULE, $action),
                    sprintf('%s should not be allowed by default', $action->value),
                );
            }

            self::assertFalse($set->isUnrestricted());
        });
    }

    public function testAnAdministratorMayDoEverythingWithoutAnyGrant(): void
    {
        $this->inTenant(function (): void {
            $set = $this->resolve(self::ADMIN);

            self::assertTrue($set->isUnrestricted());

            foreach (ModuleAction::cases() as $action) {
                self::assertTrue($set->allows(self::MODULE, $action));
                self::assertSame(PermissionScope::All, $set->scopeFor(self::MODULE, $action));
            }

            // Including modules this customer has never installed: an
            // administrator is not described by a list of what they hold.
            self::assertTrue($set->allows('nothing_like_this', ModuleAction::Edit));
        });
    }

    public function testAGrantOnAGroupReachesItsMembers(): void
    {
        $this->inTenant(function (): void {
            $group = $this->group('sales', 'Sales', [
                [ModuleAction::List, PermissionScope::All],
                [ModuleAction::Edit, PermissionScope::Own],
            ]);
            $this->join(self::MEMBER, $group);

            $set = $this->resolve(self::MEMBER);

            self::assertSame(PermissionScope::All, $set->scopeFor(self::MODULE, ModuleAction::List));
            self::assertSame(PermissionScope::Own, $set->scopeFor(self::MODULE, ModuleAction::Edit));
            self::assertTrue($set->isLimitedToOwn(self::MODULE, ModuleAction::Edit));
            self::assertFalse($set->allows(self::MODULE, ModuleAction::Delete));
        });
    }

    /** A group is not a way to give one person something quietly. */
    public function testAGrantOnAGroupDoesNotReachSomebodyElse(): void
    {
        $this->inTenant(function (): void {
            $group = $this->group('sales', 'Sales', [[ModuleAction::List, PermissionScope::All]]);
            $this->join(self::MEMBER, $group);

            self::assertFalse($this->resolve(self::OTHER)->allows(self::MODULE, ModuleAction::List));
        });
    }

    /**
     * The additive half of the model: a grant on the person adds to what their
     * groups already gave them, and nothing anywhere can take something away.
     */
    public function testAUsersOwnGrantsAddToTheirGroups(): void
    {
        $this->inTenant(function (): void {
            $group = $this->group('sales', 'Sales', [[ModuleAction::List, PermissionScope::All]]);
            $user = $this->join(self::MEMBER, $group);

            $this->entityManager()->persist(
                PermissionGrant::forUser($user, self::MODULE, ModuleAction::Export, PermissionScope::Own),
            );
            $this->entityManager()->flush();

            $set = $this->resolve(self::MEMBER);

            self::assertSame(PermissionScope::All, $set->scopeFor(self::MODULE, ModuleAction::List));
            self::assertSame(PermissionScope::Own, $set->scopeFor(self::MODULE, ModuleAction::Export));
        });
    }

    /**
     * Two grants on the same action resolve to the wider one, whichever was found
     * first. This is what makes the model explainable — there is no precedence
     * rule to look up, only a maximum.
     */
    public function testTheWiderOfTwoGrantsWins(): void
    {
        $this->inTenant(function (): void {
            $narrow = $this->group('narrow', 'Narrow', [[ModuleAction::Edit, PermissionScope::Own]]);
            $wide = $this->group('wide', 'Wide', [[ModuleAction::Edit, PermissionScope::All]]);

            $user = $this->join(self::MEMBER, $narrow);
            $user->addPermissionGroup($wide);
            $this->entityManager()->flush();

            self::assertSame(
                PermissionScope::All,
                $this->resolve(self::MEMBER)->scopeFor(self::MODULE, ModuleAction::Edit),
            );
        });
    }

    /**
     * "Add, but only the ones you own" describes nothing, so it is corrected
     * rather than stored — at the entity, so no caller can write one by mistake.
     */
    public function testAnActionThatCannotBeScopedIsAlwaysGrantedInFull(): void
    {
        $this->inTenant(function (): void {
            $group = $this->group('adders', 'Adders', [
                [ModuleAction::Add, PermissionScope::Own],
                [ModuleAction::Import, PermissionScope::Own],
            ]);
            $this->join(self::MEMBER, $group);

            $set = $this->resolve(self::MEMBER);

            self::assertSame(PermissionScope::All, $set->scopeFor(self::MODULE, ModuleAction::Add));
            self::assertSame(PermissionScope::All, $set->scopeFor(self::MODULE, ModuleAction::Import));
        });
    }

    /**
     * Deactivation already refuses the sign-in and ends a live session (§8.4.1).
     * This is the third answer, because a permission set is the wrong thing to
     * hand out on the strength of another check being right.
     */
    public function testADeactivatedUserResolvesToNothing(): void
    {
        $this->inTenant(function (): void {
            $group = $this->group('sales', 'Sales', [[ModuleAction::List, PermissionScope::All]]);
            $user = $this->join(self::MEMBER, $group);

            $user->setActive(false);
            $this->entityManager()->flush();

            self::assertFalse($this->resolve(self::MEMBER)->allows(self::MODULE, ModuleAction::List));
        });
    }

    /** The upgrade path, and the way back into a locked-out installation. */
    public function testTheGrantAllCommandGivesEverybodyEverything(): void
    {
        self::assertSame(0, $this->runGrantAll());

        $this->inTenant(function (): void {
            $set = $this->resolve(self::MEMBER);

            foreach (ModuleAction::cases() as $action) {
                self::assertSame(
                    PermissionScope::All,
                    $set->scopeFor(self::MODULE, $action),
                    sprintf('%s should have been granted', $action->value),
                );
            }
        });
    }

    /**
     * Running it twice is not an error and does not double anything — the unique
     * index would refuse a second row anyway, so the command has to be the thing
     * that notices.
     */
    public function testTheGrantAllCommandIsIdempotent(): void
    {
        self::assertSame(0, $this->runGrantAll());
        $output = '';

        self::assertSame(0, $this->runGrantAll($output));

        self::assertStringContainsString('0 grant(s) added', $output);
        self::assertStringContainsString('0 user(s) joined', $output);

        $this->inTenant(function (): void {
            $grants = $this->entityManager()
                ->getRepository(PermissionGrant::class)
                ->findBy(['moduleKey' => self::MODULE]);

            self::assertCount(\count(ModuleAction::cases()), $grants);
        });
    }

    /** ROLE_ADMIN is a bypass, so putting an administrator in a group would only
     * suggest it could be taken away again. */
    public function testTheGrantAllCommandLeavesAdministratorsAlone(): void
    {
        self::assertSame(0, $this->runGrantAll());

        $this->inTenant(function (): void {
            $admin = $this->user(self::ADMIN);

            self::assertCount(0, $admin->getPermissionGroups());
        });
    }

    // -- helpers ----------------------------------------------------------

    /** @param callable():void $work */
    private function inTenant(callable $work): void
    {
        $this->switcher->runFor($this->tenant, $work);
    }

    private function runGrantAll(string &$output = ''): int
    {
        $tester = new CommandTester(
            (new Application(self::$kernel ?? self::bootKernel()))->find('tenant:permissions:grant-all'),
        );

        $status = $tester->execute(['tenant' => self::SLUG, '--force' => true]);
        $output = $tester->getDisplay();

        return $status;
    }

    /**
     * @param list<array{0: ModuleAction, 1: PermissionScope}> $grants
     */
    private function group(string $key, string $label, array $grants): PermissionGroup
    {
        $group = new PermissionGroup($key, $label);
        $this->entityManager()->persist($group);

        foreach ($grants as [$action, $scope]) {
            $this->entityManager()->persist(
                PermissionGrant::forGroup($group, self::MODULE, $action, $scope),
            );
        }

        $this->entityManager()->flush();

        return $group;
    }

    private function join(string $email, PermissionGroup $group): User
    {
        $user = $this->user($email);
        $user->addPermissionGroup($group);
        $this->entityManager()->flush();

        return $user;
    }

    private function user(string $email): User
    {
        $user = self::service(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function resolve(string $email): \Xivi\Core\Permission\PermissionSet
    {
        // A fresh resolver each time: the real one memoises per user object for
        // the length of a request, and a test asserting on a set it just changed
        // must not be answered from that.
        return (new PermissionResolver(
            self::service(\App\Tenant\Repository\PermissionGrantRepository::class),
        ))->forUser($this->user($email));
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get('doctrine')->getManager('tenant');
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
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

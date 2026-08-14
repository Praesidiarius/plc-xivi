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
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\PermissionResolver;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * Granting permissions from a screen rather than from a console command (§7.5).
 *
 * The point of the whole slice: until this existed the only way to grant anything
 * was `tenant:permissions:grant-all`, which is all of it or none of it, and a
 * console command against the customer's database is not a thing a customer has —
 * the same argument §8.4.1 made for building the user manager first.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PermissionGroupUiTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_group_ui';
    private const string HOST = 'groups.localhost';
    private const string ADMIN = 'admin@groups.test';
    private const string MEMBER = 'member@groups.test';
    private const string PASSWORD = 'a-long-enough-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            ),
        );

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Member', self::PASSWORD, []);
    }

    public function testAnOrdinaryUserCannotReachTheGroupScreens(): void
    {
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url('/users/groups'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * The whole journey, in one test, because it is the thing that either works
     * or does not: name a group, say what it may do, put somebody in it, and have
     * that person's permissions actually change.
     */
    public function testAnAdminCanCreateAGroupGrantItAndAddSomebodyToIt(): void
    {
        $this->signIn(self::ADMIN);

        $this->client->request('GET', $this->url('/users/groups/new'));
        $this->client->submitForm('Create', ['label' => 'Sales']);

        // Created, and sent straight to the matrix: a group with no grants does
        // nothing, so naming one is the start of the job.
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Sales');

        $member = $this->user(self::MEMBER);

        $this->client->submitForm('Save', [
            'label' => 'Sales',
            'grants[contact][list]' => PermissionScope::All->value,
            'grants[contact][view]' => PermissionScope::Own->value,
            sprintf('members[%d]', $member->getId()) => (string) $member->getId(),
        ]);

        self::assertResponseRedirects($this->url('/users/groups'));

        $set = $this->resolve(self::MEMBER);

        self::assertSame(PermissionScope::All, $set->scopeFor(ContactModule::KEY, ModuleAction::List));
        self::assertSame(PermissionScope::Own, $set->scopeFor(ContactModule::KEY, ModuleAction::View));
        self::assertFalse($set->allows(ContactModule::KEY, ModuleAction::Delete));
    }

    /**
     * A cell set back to "no" removes the grant. Merging instead of replacing
     * would make taking a permission away the one edit this screen could not do.
     */
    public function testClearingACellRemovesTheGrant(): void
    {
        $this->signIn(self::ADMIN);
        $id = $this->createGroupGranting(['grants[contact][list]' => PermissionScope::All->value]);

        self::assertTrue($this->resolve(self::MEMBER)->allows(ContactModule::KEY, ModuleAction::List));

        $this->client->request('GET', $this->url('/users/groups/' . $id));
        $this->client->submitForm('Save', [
            'label' => 'Sales',
            'grants[contact][list]' => '',
            sprintf('members[%d]', $this->user(self::MEMBER)->getId()) => (string) $this->user(self::MEMBER)->getId(),
        ]);

        self::assertFalse($this->resolve(self::MEMBER)->allows(ContactModule::KEY, ModuleAction::List));
    }

    /** Unticking somebody takes away what the group gave them, and nothing else. */
    public function testRemovingSomebodyFromAGroupTakesItsPermissionsAway(): void
    {
        $this->signIn(self::ADMIN);
        $id = $this->createGroupGranting(['grants[contact][list]' => PermissionScope::All->value]);

        $crawler = $this->client->request('GET', $this->url('/users/groups/' . $id));
        $form = $crawler->selectButton('Save')->form();

        // Actually untick it. Submitting the form as rendered would send the box
        // back still checked, which is what a browser does too — and would have
        // been this test passing while proving nothing.
        $form->remove(sprintf('members[%d]', $this->user(self::MEMBER)->getId()));
        $this->client->submit($form);

        self::assertFalse($this->resolve(self::MEMBER)->allows(ContactModule::KEY, ModuleAction::List));
        self::assertTrue($this->user(self::MEMBER)->isActive(), 'the person is untouched');
        self::assertCount(0, $this->user(self::MEMBER)->getPermissionGroups());
    }

    /** Two groups called the same thing is a screen nobody can navigate. */
    public function testTwoGroupsCannotShareAName(): void
    {
        $this->signIn(self::ADMIN);

        $this->client->request('GET', $this->url('/users/groups/new'));
        $this->client->submitForm('Create', ['label' => 'Sales']);

        $this->client->request('GET', $this->url('/users/groups/new'));
        $this->client->submitForm('Create', ['label' => 'sales']);

        self::assertSelectorTextContains('.alert-warning', 'already a group called');
    }

    public function testDeletingAGroupSaysHowManyPeopleItAffects(): void
    {
        $this->signIn(self::ADMIN);
        $id = $this->createGroupGranting(['grants[contact][list]' => PermissionScope::All->value]);

        $text = $this->client->request('GET', $this->url('/users/groups/' . $id . '/delete'))->filter('main')->text();

        self::assertStringContainsString('1 person', $text);

        $this->client->submitForm('Delete the group');

        self::assertResponseRedirects($this->url('/users/groups'));
        self::assertFalse($this->resolve(self::MEMBER)->allows(ContactModule::KEY, ModuleAction::List));
        self::assertTrue($this->user(self::MEMBER)->isActive(), 'deleting a group deletes nobody');
    }

    /**
     * "Add, but only the ones you own" describes nothing, so the matrix does not
     * offer it — the enum says which actions can be scoped and the form asks it.
     */
    public function testTheMatrixOffersScopeOnlyWhereItMeansSomething(): void
    {
        $this->signIn(self::ADMIN);
        $id = $this->createGroupGranting([]);

        $page = $this->client->request('GET', $this->url('/users/groups/' . $id));

        $listOptions = $page->filter('select[name="grants[contact][list]"] option')->count();
        $addOptions = $page->filter('select[name="grants[contact][add]"] option')->count();

        self::assertSame(3, $listOptions, 'no / own / all');
        self::assertSame(2, $addOptions, 'no / yes');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * A group called Sales, granting what is passed, with the member in it.
     *
     * @param array<string, string> $grants
     */
    private function createGroupGranting(array $grants): int
    {
        $this->client->request('GET', $this->url('/users/groups/new'));
        $this->client->submitForm('Create', ['label' => 'Sales']);
        $this->client->followRedirect();

        $id = (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));

        $this->client->submitForm('Save', [
            'label' => 'Sales',
            sprintf('members[%d]', $this->user(self::MEMBER)->getId()) => (string) $this->user(self::MEMBER)->getId(),
            ...$grants,
        ]);

        return $id;
    }

    private function resolve(string $email): \Xivi\Core\Permission\PermissionSet
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email) {
            // A fresh resolver, since the real one memoises for the length of a
            // request and this test has just changed what it would have cached.
            $resolver = new PermissionResolver(
                self::service(\App\Tenant\Repository\PermissionGrantRepository::class),
            );

            return $resolver->forUser($this->user($email));
        });
    }

    private function user(string $email): User
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email): User {
            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            return $user;
        });
    }

    private function signIn(string $email): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
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

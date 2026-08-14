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
 * Two screens: a group's, which is where permissions normally come from, and one
 * person's, which is the exception on top of it.
 *
 * The point of the whole slice: until this existed the only way to grant anything
 * was `tenant:permissions:grant-all`, which is all of it or none of it, and a
 * console command against the customer's database is not a thing a customer has —
 * the same argument §8.4.1 made for building the user manager first.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PermissionUiTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_permission_ui';
    private const string HOST = 'permissionui.localhost';
    private const string ADMIN = 'admin@permissionui.test';
    private const string MEMBER = 'member@permissionui.test';
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

    // -- one person's own grants --------------------------------------------

    /**
     * The exception the group model cannot express: "Anna, and only Anna, may
     * also export" without inventing a group of one that nobody can read the
     * purpose of.
     */
    public function testAGrantMadeToOnePersonAddsToWhatTheirGroupsGive(): void
    {
        $this->signIn(self::ADMIN);
        $this->createGroupGranting(['grants[contact][list]' => PermissionScope::All->value]);

        $member = $this->user(self::MEMBER);
        $this->client->request('GET', $this->url('/users/' . $member->getId()));
        $this->client->submitForm('Save', [
            'email' => self::MEMBER,
            'name' => 'Member',
            'grants[contact][export]' => PermissionScope::Own->value,
        ]);

        $set = $this->resolve(self::MEMBER);

        // Both, from two different holders, folded into one answer.
        self::assertSame(PermissionScope::All, $set->scopeFor(ContactModule::KEY, ModuleAction::List));
        self::assertSame(PermissionScope::Own, $set->scopeFor(ContactModule::KEY, ModuleAction::Export));
    }

    /**
     * Additive means additive. A personal grant narrower than the group's cannot
     * pull it back — the resolver takes the widest, and this is the screen where
     * somebody would most expect otherwise.
     */
    public function testAPersonalGrantCannotNarrowWhatAGroupGave(): void
    {
        $this->signIn(self::ADMIN);
        $this->createGroupGranting(['grants[contact][view]' => PermissionScope::All->value]);

        $member = $this->user(self::MEMBER);
        $this->client->request('GET', $this->url('/users/' . $member->getId()));
        $this->client->submitForm('Save', [
            'email' => self::MEMBER,
            'name' => 'Member',
            'grants[contact][view]' => PermissionScope::Own->value,
        ]);

        self::assertSame(
            PermissionScope::All,
            $this->resolve(self::MEMBER)->scopeFor(ContactModule::KEY, ModuleAction::View),
        );
    }

    /** What the groups already give is shown, so nobody grants it twice. */
    public function testTheUserFormSaysWhatTheGroupsAlreadyGive(): void
    {
        $this->signIn(self::ADMIN);
        $this->createGroupGranting(['grants[contact][list]' => PermissionScope::All->value]);

        $member = $this->user(self::MEMBER);
        $text = $this->client->request('GET', $this->url('/users/' . $member->getId()))
            ->filter('main')->text();

        self::assertStringContainsString('from groups: all', $text);
    }

    /**
     * The select shows what the person can actually do, and cannot go below what
     * their groups give.
     *
     * Showing the difference instead would put "no" beside the words "groups
     * give: all", which describes a delta nobody asked about. Anything narrower
     * than the group's floor is disabled, because grants only ever add.
     */
    public function testTheSelectShowsTheEffectivePermissionAndCannotGoBelowIt(): void
    {
        $this->signIn(self::ADMIN);
        $this->createGroupGranting([
            'grants[contact][view]' => PermissionScope::All->value,
            'grants[contact][edit]' => PermissionScope::Own->value,
        ]);

        $member = $this->user(self::MEMBER);
        $page = $this->client->request('GET', $this->url('/users/' . $member->getId()));

        // The group gives all: it is what the select shows, and nothing narrower
        // is reachable.
        self::assertSame(
            ['All records'],
            $this->labels($page->filter('select[name="grants[contact][view]"] option:not([disabled])')),
        );
        self::assertSame(
            PermissionScope::All->value,
            $page->filter('select[name="grants[contact][view]"] option[selected]')->attr('value'),
        );

        // The group gives own: shown as own, and still widenable to all.
        self::assertSame(
            ['Own records', 'All records'],
            $this->labels($page->filter('select[name="grants[contact][edit]"] option:not([disabled])')),
        );
        self::assertSame(
            PermissionScope::Own->value,
            $page->filter('select[name="grants[contact][edit]"] option[selected]')->attr('value'),
        );

        // Nothing inherited here, so every choice stays open and "No" is shown.
        self::assertCount(
            3,
            $page->filter('select[name="grants[contact][delete]"] option:not([disabled])'),
        );
        self::assertSame(
            '',
            $page->filter('select[name="grants[contact][delete]"] option[selected]')->attr('value'),
        );
    }

    /**
     * Saving the form back unchanged stores nothing personal.
     *
     * The form asks what the person may do, so a cell echoing the group's own
     * answer is somebody leaving it alone. Writing that down would fill the
     * table with grants that change nothing and then have to be reasoned about
     * forever.
     */
    public function testSavingWhatTheGroupAlreadyGivesStoresNoPersonalGrant(): void
    {
        $this->signIn(self::ADMIN);
        $this->createGroupGranting(['grants[contact][view]' => PermissionScope::All->value]);

        $member = $this->user(self::MEMBER);
        $crawler = $this->client->request('GET', $this->url('/users/' . $member->getId()));
        $this->client->submit($crawler->selectButton('Save')->form());

        self::assertCount(0, $this->user(self::MEMBER)->getPermissionGrants());

        // And the permission itself is untouched: it was the group's all along.
        self::assertSame(
            PermissionScope::All,
            $this->resolve(self::MEMBER)->scopeFor(ContactModule::KEY, ModuleAction::View),
        );
    }

    /** A redundant grant left over from before the group existed is tidied away. */
    public function testAPersonalGrantTheGroupHasSinceCoveredIsRemovedOnSave(): void
    {
        $this->signIn(self::ADMIN);
        $member = $this->user(self::MEMBER);

        // Personal grant first, no group at all.
        $this->client->request('GET', $this->url('/users/' . $member->getId()));
        $this->client->submitForm('Save', [
            'email' => self::MEMBER,
            'name' => 'Member',
            'grants[contact][view]' => PermissionScope::Own->value,
        ]);
        self::assertCount(1, $this->user(self::MEMBER)->getPermissionGrants());

        // Now a group covers it more widely; the next save drops the personal one.
        $this->createGroupGranting(['grants[contact][view]' => PermissionScope::All->value]);

        $crawler = $this->client->request('GET', $this->url('/users/' . $member->getId()));
        $this->client->submit($crawler->selectButton('Save')->form());

        self::assertCount(0, $this->user(self::MEMBER)->getPermissionGrants());
        self::assertSame(
            PermissionScope::All,
            $this->resolve(self::MEMBER)->scopeFor(ContactModule::KEY, ModuleAction::View),
        );
    }

    /** Group membership is editable from the person as well as from the group. */
    public function testGroupsCanBeSetFromTheUserPage(): void
    {
        $this->signIn(self::ADMIN);
        $id = $this->createGroupGranting(['grants[contact][list]' => PermissionScope::All->value]);

        $member = $this->user(self::MEMBER);
        $crawler = $this->client->request('GET', $this->url('/users/' . $member->getId()));
        $form = $crawler->selectButton('Save')->form();
        $form->remove(sprintf('groups[%d]', $id));
        $this->client->submit($form);

        self::assertFalse($this->resolve(self::MEMBER)->allows(ContactModule::KEY, ModuleAction::List));
    }

    /**
     * The tab bar says where you are, and /users is not the dashboard.
     *
     * Overview used to light up on every page with no module in its URL, which
     * meant user management claimed to be the dashboard while plainly not being
     * it. There is no tab for users, so the answer is that no tab is active and
     * the button in the bar above says so instead.
     */
    public function testUserManagementDoesNotLightUpTheOverviewTab(): void
    {
        $this->signIn(self::ADMIN);

        foreach (['/users', '/users/groups'] as $path) {
            $page = $this->client->request('GET', $this->url($path));

            self::assertCount(0, $page->filter('.nav-tabs .nav-link.active'), $path . ' lights up a tab');
            self::assertCount(1, $page->filter('.navbar a.btn.active'), $path . ' does not mark the Users button');
        }

        // The dashboard still does light its own tab, so the assertion above is
        // known to be able to move.
        $page = $this->client->request('GET', $this->url('/'));
        self::assertSame('Overview', trim($page->filter('.nav-tabs .nav-link.active')->text()));
    }

    /** Adding somebody has no permissions section: grants need an account first. */
    public function testTheAddUserFormDoesNotAskForPermissions(): void
    {
        $this->signIn(self::ADMIN);

        $text = $this->client->request('GET', $this->url('/users/new'))->filter('main')->text();

        self::assertStringNotContainsString('Extra permissions', $text);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function labels(\Symfony\Component\DomCrawler\Crawler $options): array
    {
        return $options->each(static fn (\Symfony\Component\DomCrawler\Crawler $o): string => trim($o->text()));
    }

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

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

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\PermissionArea;
use App\Tenant\Security\StoreAction;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Permission\PermissionVerb;

/**
 * The top bar's menu, and what is in it for whom (XIV-77).
 *
 * The five buttons that used to sit along the right-hand edge became one menu
 * under the signed-in person's name, and the risk in that change is not that the
 * menu fails to open — a browser test covers that, {@see
 * \App\Tests\Browser\TopbarMenuTest} — but that it opens showing everybody
 * everything. Each of those buttons was gated: the profile behind a grant on
 * `@profile` (XIV-12), the store behind the second axis's `browse` (XIV-6,
 * §8.4.3), user management behind `ROLE_ADMIN`. Three different mechanisms,
 * rewritten in one template in one sitting, and a menu that quietly showed all
 * of them would look like a feature rather than a regression.
 *
 * So each item is asked for twice: once by somebody who has been granted nothing
 * and must not see it, and once by somebody who has been granted exactly that one
 * thing and must. Granting one at a time is what makes the second half mean
 * something — "the admin sees everything" would pass just as well if the template
 * had no conditions in it at all.
 *
 * The rest of what this file is for is the things a redesign silently loses: the
 * page you are on is still named, on a menu that is shut; sign out is still a
 * POST; and the avatar asks nothing of the network.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TopbarMenuTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_topbar';
    private const string HOST = 'topbar.localhost';
    private const string ADMIN = 'admin@topbar.test';
    private const string MEMBER = 'member@topbar.test';
    private const string PASSWORD = 'a-long-enough-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Ada Lovelace', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::MEMBER, 'Tim Fässler', self::PASSWORD, []);
    }

    // -- who sees which item -------------------------------------------------

    /**
     * Granted nothing: your own account, a way to ask for help, and the way out.
     * Nothing else.
     *
     * Two items are in the list unconditionally and both rightly. The account
     * page is your own; **Support** is [XIV-123]'s decision that every signed-in
     * user may reach whoever runs the installation, because asking commits
     * nothing and the person who met the problem is the person who can describe
     * it (§8.17). That is the floor rather than an empty menu.
     */
    public function testSomebodyGrantedNothingSeesOnlyTheirOwnAccount(): void
    {
        $this->signIn(self::MEMBER);

        self::assertSame(['Account', 'Support'], $this->menuItems());
        self::assertTrue($this->hasSignOut(), 'and the way out, which is nobody\'s privilege');
    }

    /** The profile is a grant, not a role (XIV-12) — one grant, one item. */
    public function testTheCompanyProfileAppearsOnlyWithItsGrant(): void
    {
        $this->signIn(self::MEMBER);
        self::assertNotContains('Company', $this->menuItems());

        $this->grant(self::MEMBER, PermissionArea::Profile->value, ModuleAction::View);

        self::assertContains('Company', $this->menuItems());
    }

    /**
     * The store is on the other axis (§8.4.3), so this is not the same check
     * twice: `browse` is a StoreAction against `@store`, and a template asking
     * the wrong axis would hide it from everybody or show it to everybody.
     */
    public function testTheStoreAppearsOnlyWithItsOwnAxisGrant(): void
    {
        $this->signIn(self::MEMBER);
        self::assertNotContains('Store', $this->menuItems());

        $this->grant(self::MEMBER, StoreAction::SUBJECT, StoreAction::Browse);

        self::assertContains('Store', $this->menuItems());
    }

    /**
     * User management is the one that is still a role, and deliberately so
     * (§8.4.1): it is the screen that can lock everybody out, and `ROLE_ADMIN` is
     * a bypass rather than a group.
     */
    public function testUserManagementStaysBehindTheAdminRole(): void
    {
        $this->signIn(self::MEMBER);
        self::assertNotContains('Users', $this->menuItems());

        // Every grant this tenant can express, and it is still not the role.
        $this->grant(self::MEMBER, PermissionArea::Profile->value, ModuleAction::View);
        $this->grant(self::MEMBER, StoreAction::SUBJECT, StoreAction::Browse);
        $this->grant(self::MEMBER, StoreAction::SUBJECT, StoreAction::Install);

        self::assertNotContains('Users', $this->menuItems());
    }

    /**
     * And the whole menu, for somebody who may use all of it.
     *
     * On its own rather than at the end of the test above, because a second
     * `signIn()` in one test signs nobody in: `/login` redirects an authenticated
     * request straight back to the dashboard, and the form it looks for is not
     * there. The order is also the order somebody reads — yours first, then the
     * installation's, and **Support** last (XIV-123): it is what somebody hunts
     * for when the rest of the product has already disappointed them, so it
     * belongs at the end rather than competing with what they came to do.
     *
     * **Lists** sits beside Users and behind the same role ([XIV-127]). It is in
     * this menu rather than on a module's tab because a shared list belongs to no
     * module — *Kunden → Region* and *Aufträge → Region* being one list is
     * precisely what a per-module page cannot say (§5.26).
     */
    public function testAnAdministratorSeesTheWholeMenu(): void
    {
        $this->signIn(self::ADMIN);

        self::assertSame(['Account', 'Company', 'Store', 'Users', 'Lists', 'Support'], $this->menuItems());
    }

    // -- what the redesign could have lost -----------------------------------

    /**
     * The page you are on is named on the button, which is what you can see when
     * the menu is shut.
     *
     * A dropdown that marks its current item only once opened would take the
     * answer out of the bar altogether — you would have to open a menu to find
     * out which page you are reading. The item climbs onto the toggle instead,
     * and `aria-current` stays on the link inside, where it means something.
     */
    public function testTheClosedMenuNamesThePageYouAreOn(): void
    {
        $this->signIn(self::ADMIN);

        $page = $this->client->request('GET', $this->url('/users'));

        self::assertStringContainsString('Users', $this->toggle($page)->text(), 'the shut button says where you are');
        self::assertSame(
            ['Users'],
            $page->filter('.navbar .dropdown-menu .dropdown-item.active')->each(
                static fn (Crawler $node): string => trim($node->text()),
            ),
            'and exactly one item is marked inside',
        );
        self::assertSame(
            'page',
            $page->filter('.navbar .dropdown-menu .dropdown-item.active')->attr('aria-current'),
            'as the page, in the attribute a screen reader reads — not as an escaped "page" with the quotes in it',
        );
    }

    /** And nothing is marked on a page that is not in the menu at all. */
    public function testNothingIsMarkedOnAPageTheMenuDoesNotHold(): void
    {
        $this->signIn(self::ADMIN);

        $page = $this->client->request('GET', $this->url('/'));

        self::assertCount(0, $page->filter('.navbar .dropdown-menu .dropdown-item.active'));
        self::assertStringNotContainsString('active', (string) $this->toggle($page)->attr('class'));
    }

    /**
     * Sign out moved into the menu and stayed a POST.
     *
     * The whole objection to putting it there was a second click; the thing that
     * would actually have broken is this. A `dropdown-item` is normally an `<a>`,
     * and rewriting the form as one would make signing out something a link
     * prefetcher can do to somebody.
     */
    public function testSignOutIsStillAFormInsideTheMenu(): void
    {
        $this->signIn(self::MEMBER);

        $page = $this->client->request('GET', $this->url('/'));
        $form = $page->filter('.navbar .dropdown-menu form');

        self::assertCount(1, $form);
        self::assertSame('post', mb_strtolower((string) $form->attr('method')));
        self::assertSame('/logout', $form->attr('action'));
        self::assertCount(1, $form->filter('button[type="submit"]'));

        // And it still signs you out when it is submitted.
        $this->client->submit($form->selectButton('Sign out')->form());
        $this->client->followRedirect();

        self::assertStringContainsString('/login', (string) $this->client->getRequest()->getUri());
    }

    /**
     * The avatar is drawn, not downloaded.
     *
     * Initials and a hue, both computed on the server — so the assertion that
     * matters is the negative one: nothing in this bar asks the network for a
     * picture of anybody. Gravatar would have been four lines and would have told
     * a third party, on every page load, that this person is signed in.
     */
    public function testTheAvatarIsGeneratedRatherThanFetched(): void
    {
        $this->signIn(self::MEMBER);

        $page = $this->client->request('GET', $this->url('/'));
        $avatar = $page->filter('.navbar .avatar');

        self::assertSame('TF', trim($avatar->text()), 'Tim Fässler, in two letters');
        self::assertStringContainsString('--avatar-hue:', (string) $avatar->attr('style'));
        self::assertCount(0, $page->filter('.navbar .dropdown img'), 'no picture is fetched for it');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('gravatar', mb_strtolower($html));
    }

    // -- helpers -------------------------------------------------------------

    /**
     * The menu's items as somebody reads them, on the dashboard.
     *
     * The dashboard rather than a page behind a permission, so that what is being
     * measured is the menu and not whether this person could open the page it was
     * measured on.
     *
     * @return list<string>
     */
    private function menuItems(): array
    {
        $page = $this->client->request('GET', $this->url('/'));

        return $page->filter('.navbar .dropdown-menu a.dropdown-item')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
    }

    private function hasSignOut(): bool
    {
        $page = $this->client->request('GET', $this->url('/'));

        return $page->filter('.navbar .dropdown-menu form button')->count() === 1;
    }

    private function toggle(Crawler $page): Crawler
    {
        $toggle = $page->filter('.navbar .dropdown-toggle');
        self::assertCount(1, $toggle, 'the bar has exactly one menu');

        return $toggle;
    }

    /** Straight into the table, the way ModuleStoreTest does — no screen involved. */
    private function grant(string $email, string $subject, PermissionVerb $action): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email, $subject, $action): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            $manager->persist(PermissionGrant::forUser($user, $subject, $action, PermissionScope::All));
            $manager->flush();
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

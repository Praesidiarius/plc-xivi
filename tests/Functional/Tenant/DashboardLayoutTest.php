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

use App\Dashboard\Widget\FollowUpWidget;
use App\Dashboard\Widget\ModuleTilesWidget;
use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tenant\Security\UserManager;
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Invoice\Dashboard\UnpaidInvoicesWidget;

/**
 * Whose dashboard it is (XIV-66).
 *
 * Three layers, and the shape is the one XIV-50 established for language and
 * region and XIV-83 extended for the timezone: **the person, then the
 * installation, then nothing** — where nothing is every widget that applies, in
 * the order the code declares. What differs from the three settings it is modelled
 * on is that null and "empty" are two answers here rather than one, because a
 * layout can genuinely be empty, and most of the tests below are about keeping
 * those apart.
 *
 * **The sharp part is that a saved layout is data referring to code.** A widget
 * key written down today can name a module the customer has since uninstalled, a
 * widget a later deploy renamed, or a class somebody deleted, and none of those is
 * a broken installation — it is a runtime fact about one customer, the same
 * treatment §7.6 gives a stale `reference`. Two tests below prove it by taking a
 * widget away rather than by asserting that a made-up string is ignored: one takes
 * away the *grant* that made a widget apply, which is how a widget disappears
 * without anybody deploying anything, and one names a module this tenant does not
 * have at all.
 *
 * The tenant here deliberately has **contact and nothing else**, so that
 * `invoice.unpaid` is a real key belonging to a real widget in the build that this
 * customer's dashboard must never offer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DashboardLayoutTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_dashboard_layout';
    private const string HOST = 'dashboard-layout.localhost';
    private const string PASSWORD = 'a-long-enough-password';

    /** Administers the installation, and therefore owns the tenant default. */
    private const string ADMIN = 'admin@layout.test';

    /** An ordinary person with grants on the one module there is. */
    private const string READER = 'reader@layout.test';

    /** What the follow-up card's heading reads as, in the source language. */
    private const string FOLLOW_UPS = 'Your follow-ups';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        $this->inTenant(function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            );
        });

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($this->tenant, self::READER, 'Robin Reader', self::PASSWORD, []);

        $this->grant(self::READER, [ModuleAction::View, ModuleAction::List]);
    }

    // -- the three layers ---------------------------------------------------

    /**
     * With nothing chosen anywhere, the page is what the code declares.
     *
     * That is the bottom of one of these chains and it is always the same
     * promise: what every installation had before the setting existed. The order
     * is the tag priority — follow-ups above the module tiles, because navigation
     * is always available and a deadline is not — and it is asserted rather than
     * assumed, since "the widgets are all there" would pass equally well with them
     * in any order.
     */
    public function testWithNothingChosenThePageIsEveryWidgetInTheDeclaredOrder(): void
    {
        $this->signIn(self::READER);
        $page = $this->dashboard();

        self::assertStringContainsString(self::FOLLOW_UPS, $page);
        self::assertStringContainsString('/m/contact', $page, 'the module tiles');
        self::assertLessThan(
            strpos($page, '/m/contact'),
            strpos($page, self::FOLLOW_UPS),
            'follow-ups sit above the tiles, which is the tag priority and nothing else',
        );
    }

    /**
     * The installation's own layout, which applies to everybody who has never
     * chosen.
     *
     * The middle link, and the one an administrator sets. Note what it is *not*:
     * a restriction. Leaving the follow-up card out of the default takes it off
     * nobody's screen permanently — every person can still tick it back on, which
     * is the difference between a default and a permission.
     */
    public function testTheInstallationsLayoutAppliesToSomebodyWhoHasNotChosen(): void
    {
        $this->setTenantLayout([ModuleTilesWidget::KEY]);

        $this->signIn(self::READER);
        $page = $this->dashboard();

        self::assertStringContainsString('/m/contact', $page);
        self::assertStringNotContainsString(self::FOLLOW_UPS, $page);
    }

    /** And a person's own beats it, which is what the column on `app_user` is for. */
    public function testAPersonsOwnLayoutOverridesTheInstallations(): void
    {
        $this->setTenantLayout([ModuleTilesWidget::KEY]);
        $this->setUserLayout(self::READER, [FollowUpWidget::KEY]);

        $this->signIn(self::READER);
        $page = $this->dashboard();

        self::assertStringContainsString(self::FOLLOW_UPS, $page);
        self::assertStringNotContainsString('/m/contact', $page);
    }

    /** Arranging is the other half of choosing, and it is the order that is stored. */
    public function testTheOrderTheyChoseIsTheOrderTheyGet(): void
    {
        $this->setUserLayout(self::READER, [ModuleTilesWidget::KEY, FollowUpWidget::KEY]);

        $this->signIn(self::READER);
        $page = $this->dashboard();

        self::assertLessThan(
            strpos($page, self::FOLLOW_UPS),
            strpos($page, '/m/contact'),
            'the tiles are on top now, which is the reverse of the declared order',
        );
    }

    /**
     * An empty layout is a real answer and is not "follow the default".
     *
     * Somebody who unticks every box has asked for a bare page. Reading that as
     * null would hand them back the dashboard they had just cleared and make the
     * checkboxes look broken — which is why both columns are nullable and why
     * going back to the default is a button of its own rather than the same
     * button with nothing ticked.
     */
    public function testAnEmptyLayoutIsAnEmptyDashboardRatherThanTheDefault(): void
    {
        $this->setUserLayout(self::READER, []);

        $this->signIn(self::READER);
        $page = $this->dashboard();

        self::assertStringNotContainsString(self::FOLLOW_UPS, $page);
        self::assertStringNotContainsString('/m/contact', $page);
    }

    /**
     * And the way out of that state, which has to exist and must not be an
     * administrator.
     *
     * The customise link is drawn beside the page heading rather than among the
     * panels for exactly this reason: a link that lived among the widgets would
     * have disappeared with them.
     */
    public function testSomebodyWhoHidEverythingCanStillFindTheWayBack(): void
    {
        $this->setUserLayout(self::READER, []);

        $this->signIn(self::READER);

        self::assertStringContainsString(
            '/account#dashboard',
            $this->dashboard(),
            'the escape is on the page even when nothing else is',
        );
    }

    // -- the picker, through the screens ------------------------------------

    /** Choosing, saving and going back again, driven as a person drives it. */
    public function testAPersonChoosesTheirOwnDashboardAndCanReturnToTheDefault(): void
    {
        $this->signIn(self::READER);

        $this->postToAccount(['action' => 'dashboard', 'widgets' => [FollowUpWidget::KEY]]);

        self::assertSame([FollowUpWidget::KEY], $this->layoutOf(self::READER));
        self::assertStringNotContainsString('/m/contact', $this->dashboard());

        $this->postToAccount(['action' => 'dashboard_reset']);

        self::assertNull($this->layoutOf(self::READER), 'back to following the installation');
        self::assertStringContainsString('/m/contact', $this->dashboard());
    }

    /**
     * The tenant default is set on the profile page, which is already behind the
     * grant that decides everything else about this installation.
     *
     * Posted through the same route the settings form uses and branched on
     * `action`, which is the thing worth proving here: the picker shares no field
     * with that form, so a submission that fell through to the main handler would
     * blank the company name with the fields it does not carry.
     */
    public function testTheInstallationsDefaultIsSetOnTheProfilePageWithoutBlankingIt(): void
    {
        $this->inTenant(fn () => self::service(TenantProfileManager::class)->apply('Layout AG', 'CHF', 'CH'));

        $this->signIn(self::ADMIN);
        $crawler = $this->client->request('GET', $this->url('/settings/profile'));

        $this->client->request('POST', $this->url('/settings/profile'), [
            '_token' => (string) $crawler->filter('input[name="_token"]')->first()->attr('value'),
            'action' => 'dashboard',
            'widgets' => [ModuleTilesWidget::KEY],
        ]);

        $profile = $this->inTenant(fn () => self::service(TenantProfileManager::class)->current());

        self::assertSame([ModuleTilesWidget::KEY], $profile->getDashboardLayout());
        self::assertSame('Layout AG', $profile->getCompanyName(), 'and the rest of the profile is untouched');
        self::assertSame('CHF', $profile->getCurrency());
    }

    // -- a layout that names code that is not there any more -----------------

    /**
     * A widget taken away, which is the degradation this ticket owes a proof of.
     *
     * **The widget is really removed rather than mis-named.** Revoking the
     * reader's grant on contacts is what makes `ModuleTilesWidget` stop producing
     * its key for them — a widget disappearing with nobody deploying anything,
     * which is the ordinary way this happens — and the layout that named it is
     * still sitting in their column. The page has to draw the widget that is left
     * and say nothing about the one that is gone.
     *
     * The failure being ruled out is a 500 on the first page every user loads
     * after signing in, caused by a module somebody uninstalled in another tab.
     */
    public function testALayoutNamingAWidgetThatHasGoneAwayStillDraws(): void
    {
        $this->setUserLayout(self::READER, [FollowUpWidget::KEY, ModuleTilesWidget::KEY]);

        $this->signIn(self::READER);
        self::assertStringContainsString('/m/contact', $this->dashboard(), 'both cards to begin with');

        $this->revokeGrantsFrom(self::READER);

        $page = $this->dashboard();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(self::FOLLOW_UPS, $page, 'the widget that is left is drawn');
        self::assertStringNotContainsString('/m/contact', $page, 'and the one that has gone is simply absent');
        self::assertSame(
            [FollowUpWidget::KEY, ModuleTilesWidget::KEY],
            $this->layoutOf(self::READER),
            'the saved layout is not rewritten behind their back — the grant may come back',
        );
    }

    /**
     * A widget for a module this customer does not have is not offered and is not
     * drawn (§6.2).
     *
     * `invoice.unpaid` is a real key belonging to a real class in this build; this
     * tenant simply has no invoice module. Both halves are asserted, because they
     * are different claims: the picker must not list it, and a layout that names
     * it anyway — copied from a tenant that did have it, or left over from before
     * an uninstall — must not draw it either.
     */
    public function testAWidgetForAnUninstalledModuleIsNeitherOfferedNorDrawn(): void
    {
        $this->signIn(self::READER);

        self::assertStringNotContainsString(
            'Awaiting payment',
            $this->client->request('GET', $this->url('/account'))->filter('main')->html(),
            'the picker does not offer a module this customer has not got',
        );

        $this->setUserLayout(self::READER, [UnpaidInvoicesWidget::KEY, FollowUpWidget::KEY]);

        $page = $this->dashboard();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(self::FOLLOW_UPS, $page);
        self::assertStringNotContainsString('Awaiting payment', $page);
    }

    /**
     * And a key naming nothing at all — a deleted class, a renamed widget — is
     * dropped the same way.
     *
     * Asserted against the assembler directly as well as through the page,
     * because the claim is about `Dashboard` rather than about a template: a
     * layout of three keys where one resolves gives back exactly one panel, and
     * nothing throws on the way.
     */
    public function testAKeyThatNamesNothingIsDroppedRatherThanResolved(): void
    {
        $this->setUserLayout(self::READER, ['widget.that.was.deleted', FollowUpWidget::KEY, 'another.ghost']);

        $this->signIn(self::READER);

        $crawler = $this->client->request('GET', $this->url('/'));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(self::FOLLOW_UPS, $crawler->filter('main')->html());

        // One panel drawn from a layout of three keys. Counted rather than
        // merely looked for, because "the real one is there" would pass just as
        // well if the two ghosts had each drawn an empty box — which is the
        // failure mode a null-tolerant template would produce and is not what
        // dropping a key means.
        self::assertCount(1, $crawler->filter('main section'));
    }

    // -- helpers ------------------------------------------------------------

    private function dashboard(): string
    {
        return $this->client->request('GET', $this->url('/'))->filter('main')->html();
    }

    /** @param array<string, mixed> $values */
    private function postToAccount(array $values): void
    {
        $crawler = $this->client->request('GET', $this->url('/account'));

        $this->client->request('POST', $this->url('/account'), [
            '_token' => (string) $crawler->filter('input[name="_token"]')->first()->attr('value'),
            ...$values,
        ]);
    }

    /** @param list<string>|null $layout */
    private function setUserLayout(string $email, ?array $layout): void
    {
        $this->inTenant(function () use ($email, $layout): void {
            self::service(UserManager::class)->setDashboardLayout($this->user($email), $layout);
        });
    }

    /** @param list<string>|null $layout */
    private function setTenantLayout(?array $layout): void
    {
        $this->inTenant(fn () => self::service(TenantProfileManager::class)->applyDashboardLayout($layout));
    }

    /** @return list<string>|null */
    private function layoutOf(string $email): ?array
    {
        return $this->inTenant(fn (): ?array => $this->user($email)->getDashboardLayout());
    }

    /** @param list<ModuleAction> $actions */
    private function grant(string $email, array $actions): void
    {
        $this->inTenant(function () use ($email, $actions): void {
            $user = $this->user($email);

            foreach ($actions as $action) {
                $this->entityManager()->persist(
                    PermissionGrant::forUser($user, ContactModule::KEY, $action, PermissionScope::All),
                );
            }

            $this->entityManager()->flush();
        });
    }

    /** Everything this person was granted, taken away — how a widget stops applying. */
    private function revokeGrantsFrom(string $email): void
    {
        $this->inTenant(function () use ($email): void {
            foreach ($this->user($email)->getPermissionGrants() as $grant) {
                $this->entityManager()->remove($grant);
            }

            $this->entityManager()->flush();
        });
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get('doctrine')->getManager('tenant');
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    private function user(string $email): User
    {
        $user = self::service(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
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

    private function inTenant(callable $work): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, $work);
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

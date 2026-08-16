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
use App\Tenancy\EventListener\TenantSessionGuard;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Version;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in, and the ways it must not work across tenants.
 *
 * Users live in the tenant databases, so "who is this email" has a different
 * answer per customer. Every assertion here is about that boundary holding.
 */
/**
 * Provisions and drops tenants of its own, so it stays outside DAMA's
 * transaction: a database cannot be created or dropped inside one, and a test
 * that proves one tenant cannot see another has to be looking at what is
 * actually committed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class LoginTest extends WebTestCase
{
    private const string ALPHA = 'test_login_alpha';
    private const string BETA = 'test_login_beta';

    /** The same address at both customers — the realistic collision. */
    private const string EMAIL = 'admin@example.test';
    private const string ALPHA_PASSWORD = 'alpha-password';
    private const string BETA_PASSWORD = 'beta-password';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->removeTenants();

        $provisioner = self::getContainer()->get(TenantProvisioner::class);
        \assert($provisioner instanceof TenantProvisioner);
        $users = self::getContainer()->get(UserCreator::class);
        \assert($users instanceof UserCreator);

        $alpha = $provisioner->provision(self::ALPHA, 'Alpha', ['login-alpha.localhost']);
        $beta = $provisioner->provision(self::BETA, 'Beta', ['login-beta.localhost']);

        $users->create($alpha, self::EMAIL, 'Alpha Admin', self::ALPHA_PASSWORD, ['ROLE_ADMIN']);
        $users->create($beta, self::EMAIL, 'Beta Admin', self::BETA_PASSWORD, ['ROLE_ADMIN']);
    }

    protected function tearDown(): void
    {
        $this->removeTenants();

        parent::tearDown();
    }

    public function testAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', 'https://login-alpha.localhost/');

        self::assertResponseRedirects('https://login-alpha.localhost/login');
    }

    public function testSigningIn(): void
    {
        $this->signIn('login-alpha.localhost', self::ALPHA_PASSWORD);

        self::assertResponseRedirects('https://login-alpha.localhost/');

        $this->client->request('GET', 'https://login-alpha.localhost/');
        self::assertResponseIsSuccessful();

        // The *name*, not the email. The bar showed the address until XIV-77 put
        // the menu under the person's name instead — and the name is the better
        // assertion here anyway: both tenants have a user at this address, so
        // "the page says admin@example.test" was true of either database, which
        // is the one thing this class exists to tell apart.
        self::assertSelectorTextContains('body', 'Alpha Admin');
    }

    /** The passwords differ, so the wrong one proves the lookup hit the right database. */
    public function testTheOtherTenantsPasswordDoesNotWork(): void
    {
        $this->signIn('login-alpha.localhost', self::BETA_PASSWORD);

        self::assertResponseRedirects('https://login-alpha.localhost/login');

        $this->client->request('GET', 'https://login-alpha.localhost/');
        self::assertResponseRedirects('https://login-alpha.localhost/login');
    }

    public function testEachTenantAuthenticatesAgainstItsOwnDatabase(): void
    {
        $this->signIn('login-beta.localhost', self::BETA_PASSWORD);

        self::assertResponseRedirects('https://login-beta.localhost/');
    }

    /**
     * The attack the session stamp exists for: a cookie minted on one customer,
     * replayed against another where the same email happens to exist. Without the
     * stamp the firewall restores the identifier, the provider finds *that*
     * tenant's user of the same name, and the request is authenticated.
     */
    public function testASessionFromAnotherTenantIsRefused(): void
    {
        $this->signIn('login-alpha.localhost', self::ALPHA_PASSWORD);
        $this->client->request('GET', 'https://login-alpha.localhost/');
        self::assertResponseIsSuccessful();

        // The browser would not send it; an attacker with the cookie value would.
        $this->client->request('GET', 'https://login-beta.localhost/');

        self::assertResponseRedirects('https://login-beta.localhost/login');
    }

    public function testTheSessionRecordsWhichTenantMintedIt(): void
    {
        $this->signIn('login-alpha.localhost', self::ALPHA_PASSWORD);
        $this->client->request('GET', 'https://login-alpha.localhost/');

        self::assertSame(
            self::ALPHA,
            $this->client->getRequest()->getSession()->get(TenantSessionGuard::SESSION_KEY),
        );
    }

    /** The column existed from the first migration; nothing wrote to it until now. */
    public function testSigningInStampsTheLastLogin(): void
    {
        $switcher = self::getContainer()->get(TenantSwitcher::class);
        \assert($switcher instanceof TenantSwitcher);
        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);
        $alpha = $tenants->findOneBySlug(self::ALPHA);
        self::assertNotNull($alpha);

        $before = $switcher->runFor($alpha, fn () => $this->userInCurrentTenant()?->getLastLoginAt());
        self::assertNull($before);

        $this->signIn('login-alpha.localhost', self::ALPHA_PASSWORD);

        $after = $switcher->runFor($alpha, fn () => $this->userInCurrentTenant()?->getLastLoginAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $after);
    }

    private function userInCurrentTenant(): ?User
    {
        $users = self::getContainer()->get(UserRepository::class);
        \assert($users instanceof UserRepository);

        return $users->findOneByEmail(self::EMAIL);
    }

    public function testTheLoginPageItselfIsPublic(): void
    {
        $this->client->request('GET', 'https://login-alpha.localhost/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_csrf_token"]');
    }

    /**
     * Every page says which version it is, signed in or not.
     *
     * The first question about any bug report, and one nobody can answer from
     * memory — so it is on the page somebody is looking at when they hit the bug,
     * including the one they never get past.
     *
     * Two assertions rather than one because the version is now in two different
     * places (XIV-79): the page frame's footer once you are inside, and the
     * sign-in card itself on the page that has no frame. This test is about the
     * fact being present, which has not changed; where to look for it has.
     */
    public function testEveryPageSaysWhichVersionThisIs(): void
    {
        $crawler = $this->client->request('GET', 'https://login-alpha.localhost/login');
        self::assertStringContainsString('Xivi ' . Version::CURRENT, $crawler->filter('.card-body')->text());

        $this->signIn('login-alpha.localhost', self::ALPHA_PASSWORD);
        $crawler = $this->client->request('GET', 'https://login-alpha.localhost/');

        self::assertStringContainsString('Xivi ' . Version::CURRENT, $crawler->filter('footer')->text());
    }

    /**
     * The login page does not wear the application's footer (XIV-79).
     *
     * It is one centred card on an empty page, and the bar every signed-in page
     * carries at its foot sat in the bottom-left corner of that emptiness saying
     * nothing the card could not say for itself. The version it held is still
     * here — the assertion above finds it inside the card — but the frame around
     * it belonged to an application this visitor has not been admitted to.
     */
    public function testTheLoginPageHasNoApplicationFooter(): void
    {
        $this->client->request('GET', 'https://login-alpha.localhost/login');

        self::assertSelectorNotExists('footer');
    }

    /**
     * Exactly one `<h1>`, and it is the hostname.
     *
     * The heading used to say *Sign in*, above a card with an email field, a
     * password field and a button already saying Sign in. What replaces it is the
     * one line on this page that a reader cannot deduce from looking: **which
     * installation this is**. Every tenant's login is otherwise identical, so a
     * bookmark that has gone stale or a second tab open on another customer is
     * only distinguishable here.
     *
     * The count is asserted as well as the content: deleting a heading is an easy
     * way to leave a document with no title at all in a screen reader's heading
     * list, and adding a second one is an easy way to leave it with two.
     */
    public function testTheLoginPageIsHeadedByTheHostnameAndNothingElse(): void
    {
        $crawler = $this->client->request('GET', 'https://login-alpha.localhost/login');

        $headings = $crawler->filter('h1');
        self::assertCount(1, $headings);
        self::assertSame('login-alpha.localhost', trim($headings->text()));
        self::assertStringNotContainsString('Sign in', trim($headings->text()));
    }

    /**
     * A refused password still says so, in Symfony's words.
     *
     * The framework's own `security` domain supplies the sentence (XIV-8), and
     * the page has to keep rendering it: an alert that is emptied along with the
     * furniture around it is a form that silently forgets what you typed.
     */
    public function testAFailedSignInSaysWhatWentWrong(): void
    {
        $this->signIn('login-alpha.localhost', self::BETA_PASSWORD);
        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        self::assertStringContainsString('Invalid credentials', $crawler->filter('.alert-danger')->text());
    }

    private function signIn(string $host, string $password): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', $host));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => $password,
        ]));
    }

    private function removeTenants(): void
    {
        $provisioner = self::getContainer()->get(TenantProvisioner::class);
        \assert($provisioner instanceof TenantProvisioner);

        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);

        foreach ([self::ALPHA, self::BETA] as $slug) {
            $tenant = $tenants->findOneBySlug($slug);

            if ($tenant instanceof Tenant) {
                $provisioner->deprovision($tenant);
            }
        }
    }
}

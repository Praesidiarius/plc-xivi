<?php

declare(strict_types=1);

namespace App\Tests\Functional\Tenancy;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\EventListener\TenantSessionGuard;
use App\Tenant\Security\UserCreator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in, and the ways it must not work across tenants.
 *
 * Users live in the tenant databases, so "who is this email" has a different
 * answer per customer. Every assertion here is about that boundary holding.
 */
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
        self::assertSelectorTextContains('body', self::EMAIL);
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

    public function testTheLoginPageItselfIsPublic(): void
    {
        $this->client->request('GET', 'https://login-alpha.localhost/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_csrf_token"]');
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

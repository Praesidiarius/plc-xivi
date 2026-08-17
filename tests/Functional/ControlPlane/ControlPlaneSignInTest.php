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
use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantContext;
use App\Tenant\Security\UserCreator;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\ControlPlane\Entity\Operator;
use Xivi\ControlPlane\Provisioning\ProvisioningFailed;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\ControlPlane\Repository\OperatorRepository;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorCreator;

/**
 * Signing in to the control plane, and every way a customer must not be able to
 * (XIV-57).
 *
 * The sibling of {@see \App\Tests\Functional\Tenancy\LoginTest}, and it is
 * arranged the same way for the same reason: **one email address exists on both
 * sides with two different passwords**. That collision is the whole instrument.
 * A control-plane sign-in that accepted the tenant's password would have been
 * answered by the tenant's database, and no amount of asserting that the right
 * page rendered would have caught it.
 *
 * Provisions and drops a tenant of its own, so it stays outside DAMA's
 * transaction — a database cannot be created or dropped inside one, and a test
 * about what is really committed has to be looking at what is really committed.
 * The operator row is cleaned up by hand for the same reason: the control plane
 * is deliberately not rolled back.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class ControlPlaneSignInTest extends WebTestCase
{
    private const string TENANT = 'test_control_plane';
    private const string TENANT_HOST = 'control-plane-tenant.localhost';

    /** The same address on both sides — the collision this class is built around. */
    private const string EMAIL = 'admin@example.test';
    private const string OPERATOR_PASSWORD = 'operator-password';
    private const string TENANT_PASSWORD = 'tenant-password';

    private KernelBrowser $client;
    private string $controlPlaneHost;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->removeFixtures();

        $host = self::service(ControlPlaneHost::class);
        $this->controlPlaneHost = $host->normalisedHost();

        $tenant = self::service(TenantProvisioner::class)->provision(self::TENANT, 'Customer', [self::TENANT_HOST]);

        // A tenant *admin*, deliberately, and with ROLE_OPERATOR written into
        // their own row besides. The question this class answers is not whether
        // an ordinary user is kept out — it is whether the most privileged person
        // in a customer's database, holding the exact role the control plane
        // asks for, is still nobody here. A role is a string a customer's
        // administrator can write; the boundary must not be a role.
        self::service(UserCreator::class)->create(
            $tenant,
            self::EMAIL,
            'Customer Admin',
            self::TENANT_PASSWORD,
            ['ROLE_ADMIN', Operator::ROLE],
        );

        self::service(OperatorCreator::class)->create(self::EMAIL, 'The Operator', self::OPERATOR_PASSWORD);
    }

    protected function tearDown(): void
    {
        $this->removeFixtures();

        parent::tearDown();
    }

    public function testAnOperatorSignsIn(): void
    {
        $this->signInToControlPlane(self::OPERATOR_PASSWORD);

        self::assertResponseRedirects(sprintf('https://%s/control/', $this->controlPlaneHost));

        $this->client->request('GET', sprintf('https://%s/control/', $this->controlPlaneHost));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'The Operator');
    }

    /**
     * The assertion the whole ticket exists for.
     *
     * Both accounts are `admin@example.test`; only the passwords differ. If the
     * control-plane firewall ever resolved its provider against `tenant_users` —
     * because it was reordered below `main`, because somebody "simplified" the
     * provider, because a `host:` regular expression stopped matching — this is
     * the password that would start working.
     */
    public function testATenantPasswordIsNotAnOperatorPassword(): void
    {
        $this->signInToControlPlane(self::TENANT_PASSWORD);

        self::assertResponseRedirects(sprintf('https://%s/control/login', $this->controlPlaneHost));

        $this->client->request('GET', sprintf('https://%s/control/', $this->controlPlaneHost));
        self::assertResponseRedirects(sprintf('https://%s/control/login', $this->controlPlaneHost));
    }

    /** And the mirror image: the operator's password is not a way into the customer's installation. */
    public function testAnOperatorPasswordIsNotATenantPassword(): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', self::TENANT_HOST));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::OPERATOR_PASSWORD,
        ]));

        self::assertResponseRedirects(sprintf('https://%s/login', self::TENANT_HOST));
    }

    /**
     * A control-plane route does not exist on a customer's hostname.
     *
     * 404 rather than 403: the path is not there, in the plainest sense. A 403
     * would confirm to somebody poking at their own installation that there is
     * something at `/control/` worth being refused from.
     */
    public function testATenantHostnameCannotReachAControlPlaneRoute(): void
    {
        foreach (['/control/', '/control/login', '/control/logout'] as $path) {
            $this->client->request('GET', sprintf('https://%s%s', self::TENANT_HOST, $path));

            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $path . ' should not exist on a tenant host.');
        }
    }

    /**
     * And it still does not exist once a tenant administrator has signed in —
     * including one whose own database says they hold ROLE_OPERATOR.
     *
     * The separate test rather than a second assertion in the one above, because
     * they fail for different reasons and the difference is the point: an
     * anonymous 404 could be `access_control` turning somebody away before the
     * route was consulted, and this one cannot be.
     */
    public function testASignedInTenantAdminCannotReachAControlPlaneRoute(): void
    {
        $this->signInToTenant();
        $this->client->request('GET', sprintf('https://%s/', self::TENANT_HOST));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('https://%s/control/', self::TENANT_HOST));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * A tenant session presented on the control-plane host is nobody's session.
     *
     * The cookie a browser would never send and an attacker with the value
     * would. Two things refuse it and either would be enough: `TenantSessionGuard`
     * discards a session stamped for a tenant on a host that resolved none, and
     * the two firewalls store their tokens under different context keys, so a
     * `main` token is not one `control_plane` would look for.
     */
    public function testATenantSessionIsNotAnOperatorSession(): void
    {
        $this->signInToTenant();

        $this->client->request('GET', sprintf('https://%s/control/', $this->controlPlaneHost));

        self::assertResponseRedirects(sprintf('https://%s/control/login', $this->controlPlaneHost));
    }

    /** And the other way round: an operator is not admitted to a customer's installation. */
    public function testAnOperatorSessionIsNotATenantSession(): void
    {
        $this->signInToControlPlane(self::OPERATOR_PASSWORD);

        $this->client->request('GET', sprintf('https://%s/', self::TENANT_HOST));

        self::assertResponseRedirects(sprintf('https://%s/login', self::TENANT_HOST));
    }

    /**
     * The two sessions are kept under different keys, which is what "separate
     * session contexts" means once it stops being a word in a configuration file.
     */
    public function testTheOperatorTokenIsStoredUnderItsOwnKey(): void
    {
        $this->signInToControlPlane(self::OPERATOR_PASSWORD);
        $this->client->request('GET', sprintf('https://%s/control/', $this->controlPlaneHost));

        $session = $this->client->getRequest()->getSession();

        self::assertTrue($session->has('_security_control_plane'));
        self::assertFalse($session->has('_security_main'));
    }

    /**
     * **A control-plane request resolves no tenant at all**, rather than falling
     * back to one (§4).
     *
     * Asserted on the context the request left behind rather than on a page,
     * because "no tenant" is exactly the thing a rendered page cannot show you.
     * The kernel is not rebooted between requests here, so the context is the one
     * the request itself was served with.
     */
    public function testAControlPlaneRequestResolvesNoTenant(): void
    {
        $this->signInToControlPlane(self::OPERATOR_PASSWORD);
        $this->client->request('GET', sprintf('https://%s/control/', $this->controlPlaneHost));
        self::assertResponseIsSuccessful();

        self::assertNull(self::service(TenantContext::class)->tryGetTenant());
    }

    /**
     * And nothing of the tenant application is served there either.
     *
     * Not required by the boundary — no tenant resolves, so those pages could
     * only fail — but a 404 is the honest answer where the alternative is a 500
     * from a controller reaching for a connection that is deliberately unusable,
     * or a customer's sign-in form drawn with no customer behind it.
     */
    public function testTheTenantApplicationIsNotServedOnTheControlPlaneHost(): void
    {
        foreach (['/', '/login'] as $path) {
            $this->client->request('GET', sprintf('https://%s%s', $this->controlPlaneHost, $path));

            self::assertResponseStatusCodeSame(
                Response::HTTP_NOT_FOUND,
                $path . ' should not be served on the control-plane host.',
            );
        }
    }

    public function testTheControlPlaneSignInPageIsPublicAndCarriesACsrfToken(): void
    {
        $this->client->request('GET', sprintf('https://%s/control/login', $this->controlPlaneHost));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_csrf_token"]');
    }

    /**
     * Signing in lands on the tenant list (XIV-58), which is what replaced this
     * ticket's placeholder.
     *
     * Asserted from here as well as from `TenantListTest` because it is a
     * different claim: that ticket's page is the *default target path* of this
     * ticket's firewall, so the two are coupled through `security.yaml` and a
     * renamed route would break the landing without breaking the page. One
     * assertion, on the customer this class already provisions.
     */
    public function testSigningInLandsOnTheTenantList(): void
    {
        $this->signInToControlPlane(self::OPERATOR_PASSWORD);
        $crawler = $this->client->request('GET', sprintf('https://%s/control/', $this->controlPlaneHost));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::TENANT, $crawler->filter('body')->text());
        self::assertStringContainsString(self::TENANT_HOST, $crawler->filter('body')->text());
    }

    /**
     * **A customer cannot be moved onto the control plane's hostname** — the same
     * boundary approached from the registry rather than from a request.
     *
     * Without this refusal the mistake is silent in the worst possible way: the
     * row is created, the tenancy listener never consults it because the host is
     * a system host, and the customer's users are shown the platform's sign-in
     * page instead of their own.
     */
    public function testATenantCannotBeProvisionedOnTheControlPlaneHostname(): void
    {
        $this->expectException(ProvisioningFailed::class);

        self::service(TenantProvisioner::class)->provision('test_cp_clash', 'Clash', [$this->controlPlaneHost]);
    }

    private function signInToControlPlane(#[\SensitiveParameter] string $password): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/control/login', $this->controlPlaneHost));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => $password,
        ]));
    }

    private function signInToTenant(): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', self::TENANT_HOST));
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::TENANT_PASSWORD,
        ]));
    }

    /**
     * The tenant and the operator row, both of which outlive a test on purpose:
     * the tenant because its database cannot be made inside a transaction, the
     * operator because the control plane is not rolled back at all.
     */
    private function removeFixtures(): void
    {
        $tenants = self::service(TenantRepository::class);
        $provisioner = self::service(TenantProvisioner::class);

        foreach ([self::TENANT, 'test_cp_clash'] as $slug) {
            $tenant = $tenants->findOneBySlug($slug);

            if ($tenant instanceof Tenant) {
                $provisioner->deprovision($tenant);
            }
        }

        $operator = self::service(OperatorRepository::class)->findOneByEmail(self::EMAIL);

        if ($operator instanceof Operator) {
            $entityManager = self::service(EntityManagerInterface::class);
            $entityManager->remove($operator);
            $entityManager->flush();
        }
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

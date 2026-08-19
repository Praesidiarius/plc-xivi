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

namespace App\Tests\Functional\Deployment;

use App\Deployment\TrustedHosts;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Xivi\ControlPlane\Provisioning\ProvisioningFailed;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;

/**
 * That the pattern actually reaches the framework, and what a request gets when
 * it is outside it (XIV-93, docs/architecture/deployment.md §4.3).
 *
 * ## What this proves that the unit test cannot
 *
 * {@see \App\Tests\Unit\Deployment\TrustedHostsTest} proves that
 * `App\Deployment\TrustedHosts` writes the right expressions. It says nothing
 * about whether anything ever gives them to Symfony — and the wiring is the part
 * with somewhere to go wrong, because it runs through a custom env-var
 * processor, a configuration node that only accepts scalars, a parameter, and a
 * comma-split in `Kernel::preBoot()`. Every one of those links is silent when it
 * breaks: `framework.trusted_hosts` simply stays unset, the application serves
 * every `Host` header exactly as it did before, and nothing anywhere is red.
 *
 * So this boots a kernel with `XIVI_TRUSTED_DOMAINS` in the environment and asks
 * the framework what it ended up believing.
 *
 * ## The three outcomes, and why the middle one is the interesting one
 *
 * - A hostname outside the pattern gets **400** and never reaches the
 *   application. That is the acceptance criterion.
 * - A hostname *inside* it gets **404** from tenancy, because no tenant claims
 *   it. That is the contrast that makes the 400 meaningful: 404 is this
 *   application saying "not a customer of mine", 400 is the framework saying
 *   "not a hostname of mine", and a test that only asserted the refusal could
 *   not tell a working pattern from one that refuses everything.
 * - The control-plane host gets past the pattern **without being listed in
 *   `XIVI_TRUSTED_DOMAINS` at all**, because `app.system_hosts` is composed into
 *   it. §8.9 asks for a control-plane hostname that is not guessable from the
 *   customer-facing domain, which in practice means one that is often not
 *   *under* it either — so this is the case a deployment would otherwise take
 *   itself offline with.
 *
 * ## The environment is written to directly, and restored
 *
 * `%env(…)%` resolves out of the process environment at boot, and the trusted
 * hosts live in a static on `Request` from that moment until something clears
 * them. Both are global, both outlive a test, and a leftover pattern would
 * answer every subsequent test in this worker with a 400 — so `tearDown()` puts
 * both back. The shape is `PlaceholderSecretGuardTest`'s, for the same reason it
 * gives.
 *
 * **`$_ENV` as well as `$_SERVER`, and the order is not incidental.**
 * `EnvVarProcessor` reads `$_ENV[$name] ?? $_SERVER[$name]`, and Dotenv has
 * already put this variable in `$_ENV` with the empty value `.env` commits — so
 * writing only `$_SERVER` sets nothing and this whole file passes while proving
 * the opposite of what it says. A real deployment does not have that problem,
 * because a real environment variable is in both before Dotenv runs and Dotenv
 * leaves what is already there alone.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TrustedHostsTest extends WebTestCase
{
    /**
     * A domain no tenant in this suite is on, so that "admitted" and "resolves a
     * tenant" cannot be confused with each other.
     */
    private const string DOMAIN = 'xivi-trusted-hosts-test.example';

    /** @var array<string, mixed> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previous = [
            'env' => $_ENV[TrustedHosts::VARIABLE] ?? null,
            'server' => $_SERVER[TrustedHosts::VARIABLE] ?? null,
        ];

        $_ENV[TrustedHosts::VARIABLE] = self::DOMAIN;
        $_SERVER[TrustedHosts::VARIABLE] = self::DOMAIN;
    }

    protected function tearDown(): void
    {
        foreach (['env' => &$_ENV, 'server' => &$_SERVER] as $key => &$bag) {
            if ($this->previous[$key] === null) {
                unset($bag[TrustedHosts::VARIABLE]);
            } else {
                $bag[TrustedHosts::VARIABLE] = $this->previous[$key];
            }
        }
        unset($bag);

        // The important half. Every other test in this worker runs with no
        // trusted hosts, and one left behind here would answer all of them with
        // a 400 for reasons that have nothing to do with what they assert.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    /**
     * The wiring itself: what a deployment sets ends up in the framework's own
     * static, through the env-var processor and the parameter.
     */
    public function testTheDeploymentsDomainsReachTheFramework(): void
    {
        self::createClient();

        $patterns = Request::getTrustedHosts();

        self::assertNotSame([], $patterns, 'framework.trusted_hosts was never set');
        self::assertContains(sprintf('{^(?:[a-z0-9_-]+\.)*%s\.?$}i', preg_quote(self::DOMAIN)), $patterns);
    }

    /** The acceptance criterion: a hostname outside the pattern is refused. */
    public function testAHostnameOutsideThePatternIsRefused(): void
    {
        $client = self::createClient();
        $client->request('GET', 'http://somebody-elses-name.example/');

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    /**
     * And the other half, without which the test above would also pass for a
     * pattern that refused everything: a name under the deployment's own domain
     * reaches the application, and is turned away by tenancy rather than by the
     * framework.
     */
    public function testANameUnderTheDeploymentsDomainReachesTenancy(): void
    {
        $client = self::createClient();
        $client->request('GET', sprintf('http://nobody.%s/', self::DOMAIN));

        // 404, not 400: §4's tenancy listener answering "no tenant claims this
        // host", which is a different sentence from "this installation does not
        // answer to that name".
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    /**
     * The control plane is admitted by construction, not by being listed.
     *
     * `CONTROL_PLANE_HOST` is `control.localhost` here and `XIVI_TRUSTED_DOMAINS`
     * names an unrelated domain, so a pattern built only from what the deployment
     * wrote would answer the control plane's own sign-in page with a 400 — an
     * operator locking themselves out with one environment variable.
     */
    public function testTheControlPlaneHostIsAdmittedWithoutBeingListed(): void
    {
        $client = self::createClient();
        $client->request('GET', 'http://control.localhost/control/login');

        self::assertNotSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    /**
     * The container's own name too, which is what the health check and the
     * document converter reach this application on. Refusing it would be the
     * too-narrow failure arriving on the first deploy rather than on the first
     * customer.
     */
    public function testTheContainerInternalNameIsAdmitted(): void
    {
        $client = self::createClient();
        $client->request('GET', 'http://php/');

        self::assertNotSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
    }

    /**
     * A customer is never created on a hostname this installation would refuse.
     *
     * The earliest of the three places a too-narrow pattern is caught, and the
     * only one that *prevents* the failure rather than reporting it:
     * `deploy:check-hosts` and the log line on a refused request both arrive
     * after somebody has been given an address. This arrives while they are
     * typing it.
     *
     * Nothing is created here — `provision()` refuses before it writes the
     * registry row, let alone the role and the database — so this test leaves no
     * tenant behind and needs no cleanup.
     */
    public function testATenantIsNotProvisionedOntoAHostnameThisInstallationRefuses(): void
    {
        self::bootKernel();

        $this->expectException(ProvisioningFailed::class);
        $this->expectExceptionMessage('would be refused with an empty HTTP 400');

        self::getContainer()->get(TenantProvisioner::class)->provision(
            'xivi_trusted_hosts_test',
            'Trusted Hosts Test',
            ['acme.somebody-elses-name.example'],
        );
    }
}

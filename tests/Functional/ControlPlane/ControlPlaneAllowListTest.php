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

use App\Deployment\ControlPlaneAllowList;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * That the control-plane allow-list is wired, and that a forged header cannot
 * get past it (XIV-124, docs/architecture.md §8.9).
 *
 * ## What this proves that the unit tests cannot
 *
 * {@see \App\Tests\Unit\Deployment\ControlPlaneAllowListTest} proves the policy
 * decides correctly and
 * {@see \App\Tests\Unit\ControlPlane\ControlPlaneAddressListenerTest} proves the
 * listener refuses and logs. Neither says anything about whether a real request
 * ever reaches either of them, and the wiring is where this could fail
 * silently — an environment variable, an autowired string, a listener priority
 * and a request matcher, every one of which is quiet when it breaks. A broken
 * link here leaves the control plane exactly as reachable as it was, with the
 * variable set and an operator believing in it.
 *
 * ## And the acceptance criterion that only a real request can carry
 *
 * **The address must come from Symfony's own client-IP resolution, never from a
 * header this code reads**, so that it inherits [XIV-93]'s `TRUSTED_PROXIES`
 * configuration. Two tests hold that from both sides and they only mean anything
 * together:
 *
 * - With nothing trusted in front — the shipped topology, §4 — an
 *   `X-Forwarded-For` naming an admitted address is **ignored**, and the caller
 *   is refused on the address their connection actually came from.
 * - With `TRUSTED_PROXIES` naming that connection's address, the very same
 *   header **is** believed, and the same caller is admitted.
 *
 * The second is what makes the first a proof rather than a coincidence. A
 * listener that simply never looked at forwarded headers would pass the first
 * test and fail the second, and it would also be wrong: a deployment behind a
 * load balancer would then have to put the balancer on its allow-list, which
 * admits everybody behind the balancer.
 *
 * ## The environment is written to directly, and restored
 *
 * `%env(…)%` resolves out of the process environment at boot, and trusted
 * proxies live in a static on `Request` from that moment. Both are global and
 * both outlive a test, so `tearDown()` puts them back — the shape
 * {@see \App\Tests\Functional\Deployment\TrustedHostsTest} uses, for the reason
 * it gives. `$_ENV` as well as `$_SERVER`, and in that order, because
 * `EnvVarProcessor` reads `$_ENV[$name] ?? $_SERVER[$name]` and Dotenv has
 * already put both of these variables in `$_ENV` with the empty value `.env`
 * commits.
 *
 * Nothing here provisions a tenant or creates an operator. The refusal happens
 * before routing and long before any credential, which is the point of it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ControlPlaneAllowListTest extends WebTestCase
{
    /** Documentation addresses (RFC 5737), so nothing here can be a real network. */
    private const string ADMITTED = '203.0.113.9';
    private const string REFUSED = '198.51.100.9';
    private const string ALLOW = '203.0.113.0/24';

    private const string PROXIES = 'TRUSTED_PROXIES';

    /** @var array<string, string|null> the two variables as they were, per bag */
    private array $previousEnv = [];

    /** @var array<string, string|null> */
    private array $previousServer = [];

    /**
     * The forwarded headers `config/packages/framework.yaml` settled on (§4.3),
     * as the bitmask `Request` wants them in.
     *
     * Written out rather than captured from `Request::getTrustedHeaderSet()`,
     * because this is the value the framework sets at every boot and therefore
     * the one to restore to — and because naming the three headers here says
     * which decision this test is restoring, where an opaque integer would say
     * nothing.
     */
    private const int TRUSTED_HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT;

    /** @var array<string> */
    private array $previousProxies = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([ControlPlaneAllowList::VARIABLE, self::PROXIES] as $name) {
            $this->previousEnv[$name] = \is_string($_ENV[$name] ?? null) ? $_ENV[$name] : null;
            $this->previousServer[$name] = \is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : null;
        }

        $this->previousProxies = Request::getTrustedProxies();

        // **An empty TRUSTED_PROXIES does not clear the static.** `Kernel::preBoot()`
        // only calls `setTrustedProxies()` when the parameter is truthy, so a
        // proxy trusted by some earlier test in this worker would still be
        // trusted here — and the forged-header test would then be asserting the
        // opposite of what it claims. Cleared explicitly rather than hoped for.
        Request::setTrustedProxies([], self::TRUSTED_HEADERS);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        foreach ($this->previousServer as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }

        // The important half. A trusted proxy left behind here would make every
        // subsequent test in this worker believe an `X-Forwarded-For` it has no
        // reason to, which is a failure that would show up somewhere else
        // entirely.
        Request::setTrustedProxies($this->previousProxies, self::TRUSTED_HEADERS);

        parent::tearDown();
    }

    /**
     * The shipped default, and the criterion that development and the suite are
     * unaffected.
     *
     * Every other test in this repository runs in exactly this state, so a
     * regression that made an empty variable restrict anything would take the
     * whole suite with it — but that is a diagnosis after the fact, and this is
     * the assertion that names the property.
     */
    public function testAnUnconfiguredInstallationRestrictsNothing(): void
    {
        $client = $this->clientWith('');

        $this->get($client, '/control/login', self::REFUSED);

        self::assertResponseIsSuccessful();
    }

    /** An address inside the configured range reaches the sign-in page as before. */
    public function testAnAdmittedAddressReachesTheSignInPage(): void
    {
        $client = $this->clientWith(self::ALLOW);

        $this->get($client, '/control/login', self::ADMITTED);

        self::assertResponseIsSuccessful();
    }

    /**
     * The acceptance criterion: an address outside the list gets a 403 with
     * nothing in it.
     */
    public function testAnAddressOutsideTheListGetsAnEmpty403(): void
    {
        $client = $this->clientWith(self::ALLOW);

        $this->get($client, '/control/login', self::REFUSED);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('', $client->getResponse()->getContent());
    }

    /**
     * And the same for everything else on that host, including the paths
     * `ControlPlaneRequestListener` stands aside for.
     *
     * An off-list caller gets one answer for every path rather than a map of
     * which ones exist.
     */
    public function testEverythingOnTheControlPlaneHostIsRefused(): void
    {
        $client = $this->clientWith(self::ALLOW);

        foreach (['/control/', '/control/login', '/assets/app.css', '/nothing-here'] as $path) {
            $this->get($client, $path, self::REFUSED);

            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $path . ' should be refused.');
        }
    }

    /**
     * **The forged header, with nothing trusted in front.**.
     *
     * The caller writes an admitted address into `X-Forwarded-For` and is
     * refused on the address their connection came from. This is the failure
     * mode the whole design turns on: an allow-list that read the header itself
     * would admit anybody who has read this repository, while looking to
     * whoever configured it exactly like a restriction.
     */
    public function testAForgedForwardedHeaderIsIgnoredWhenNoProxyIsTrusted(): void
    {
        $client = $this->clientWith(self::ALLOW);

        // The state a fresh installation is in, asserted rather than assumed —
        // if something else had trusted a proxy, this test would be proving the
        // opposite of what it says.
        self::assertSame([], Request::getTrustedProxies());

        $this->get($client, '/control/login', self::REFUSED, [
            'HTTP_X_FORWARDED_FOR' => self::ADMITTED,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * **And the same header, believed, once the connection comes from a trusted
     * proxy.**.
     *
     * Which is what says the address is resolved through [XIV-93]'s
     * configuration rather than merely being the socket peer: this list inherits
     * whatever a deployment has already decided about who may speak for a
     * client, and acquires no second opinion of its own.
     */
    public function testTheSameHeaderIsBelievedFromATrustedProxy(): void
    {
        $client = $this->clientWith(self::ALLOW, proxies: self::REFUSED);

        self::assertContains(self::REFUSED, Request::getTrustedProxies(), 'TRUSTED_PROXIES did not reach the framework');

        $this->get($client, '/control/login', self::REFUSED, [
            'HTTP_X_FORWARDED_FOR' => self::ADMITTED,
        ]);

        self::assertResponseIsSuccessful();
    }

    /**
     * IPv6, end to end, because `IpUtils` is only half the answer if the address
     * that reaches it has been mangled on the way.
     */
    public function testAnIpv6ClientIsMatchedAgainstAnIpv6Range(): void
    {
        $client = $this->clientWith('2001:db8:1::/48');

        $this->get($client, '/control/login', '2001:db8:1::9');
        self::assertResponseIsSuccessful();

        $this->get($client, '/control/login', '2001:db8:2::9');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * **Every customer is untouched by this**, which is the criterion that keeps
     * a control-plane setting from being an installation-wide outage.
     *
     * A 404 rather than a 403: tenancy answering "no tenant claims this host",
     * which is the response an unknown hostname got before this feature existed
     * and still gets.
     */
    public function testATenantHostnameIsNotRestricted(): void
    {
        $client = $this->clientWith(self::ALLOW);

        $client->request('GET', 'https://nobody.localhost/', server: ['REMOTE_ADDR' => self::REFUSED]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * [XIV-57]'s three layers are untouched: this one sits in front of them
     * rather than in place of any.
     *
     * `ControlPlaneFirewallTest` asserts the ordering against the compiled
     * firewall map and is deliberately not modified by this ticket. What is
     * worth asserting *here* is that a caller who is admitted by the allow-list
     * still meets all of it — an allow-list that had somehow satisfied the
     * firewall would be the worst possible outcome of adding one.
     */
    public function testAnAdmittedAddressStillHasToSignIn(): void
    {
        $client = $this->clientWith(self::ALLOW);

        $this->get($client, '/control/', self::ADMITTED);

        self::assertResponseRedirects();
        self::assertStringEndsWith('/control/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * Boots a client with the allow-list — and optionally the trusted proxies —
     * this deployment would have.
     */
    private function clientWith(string $allowed, string $proxies = ''): KernelBrowser
    {
        $this->putEnv(ControlPlaneAllowList::VARIABLE, $allowed);
        $this->putEnv(self::PROXIES, $proxies);

        return self::createClient();
    }

    private function putEnv(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * @param array<string, string> $server extra server parameters, which is where a
     *                                      forged header goes
     */
    private function get(KernelBrowser $client, string $path, string $remoteAddress, array $server = []): void
    {
        $client->request(
            'GET',
            sprintf('https://%s%s', $this->controlPlaneHost(), $path),
            server: ['REMOTE_ADDR' => $remoteAddress] + $server,
        );
    }

    /**
     * Read from the container rather than written out, so that a deployment
     * default changing cannot leave this test asserting about a host nothing
     * serves.
     *
     * The **parameter** rather than `ControlPlaneHost`, because it is the
     * application's own (§4.4) and asking for it keeps this file free of any
     * reason to know how the administration surface answers the same question.
     */
    private function controlPlaneHost(): string
    {
        return (string) self::getContainer()->getParameter('app.control_plane_host');
    }
}

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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Signup\SignupHost;

/**
 * Where the public signup endpoint sits among the firewalls, and why it sits
 * there (XIV-64).
 *
 * The sibling of {@see ControlPlaneFirewallTest} and it exists for the same
 * reason: Symfony takes the **first** firewall whose matcher accepts a request,
 * so the order of the blocks in that file is a security property rather than a
 * presentation choice, and a property that holds because of an order is one a
 * merge can undo without anybody reading a comment.
 *
 * Two orderings are asserted here and they guard against opposite mistakes.
 *
 *   * **Signup above `main`.** `main` has no host restriction, so it accepts
 *     everything. Below it, a signup request would sit inside the firewall whose
 *     provider is `tenant_users` — looking people up in whichever customer's
 *     database the hostname resolved to, on a host where none resolves. Nothing
 *     would come of it today, because the endpoint carries no session and asks
 *     for no user; "nothing would come of it today" is the wrong standard for a
 *     boundary.
 *   * **Signup below `control_plane`.** The two hostnames must differ and
 *     `SignupRouteLoader` refuses to build a routing table when they do not — but
 *     if that refusal is ever removed or worked around, this order decides which
 *     way the mistake falls. Control plane first means a misconfigured deployment
 *     gets an operator console that still demands a password; the other order
 *     means an operator console with `security: false` in front of it.
 *
 * Nothing here provisions or writes anything: none of these questions is about a
 * customer, or in fact about a signup.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SignupFirewallTest extends KernelTestCase
{
    private const string TENANT_HOST = 'signup-firewall-tenant.localhost';

    public function testASignupRequestIsTakenByTheSignupFirewall(): void
    {
        self::assertSame('signup', $this->firewallFor($this->signupRequest())->getName());
    }

    /**
     * And what that buys: **no user provider runs there at all**.
     *
     * Asserted in both directions for the reason its sibling gives — naming what
     * must not be there catches the case this class exists for, where `main` took
     * the request and its provider is perfectly correct *for main*.
     */
    public function testNoUserProviderIsConsultedOnTheSignupHost(): void
    {
        $config = $this->firewallFor($this->signupRequest());

        self::assertFalse($config->isSecurityEnabled(), 'the signup firewall runs no authentication machinery');
        self::assertNull($config->getProvider(), 'and therefore has nobody to look anybody up in');
    }

    /**
     * The endpoint is not unauthenticated as a result: the shared secret is
     * checked in the controller, in constant time, and refuses when unset. This
     * is the assertion that the *firewall* is not what is doing it, so that
     * nobody removes the check on the strength of a `security.yaml` entry.
     */
    public function testATenantRequestIsStillTakenByMain(): void
    {
        self::assertSame(
            'main',
            $this->firewallFor(Request::create(sprintf('https://%s/', self::TENANT_HOST)))->getName(),
        );
    }

    public function testTheSignupFirewallIsBetweenTheControlPlaneAndMain(): void
    {
        $names = $this->declaredFirewalls();

        foreach (['control_plane', 'signup', 'main'] as $expected) {
            self::assertContains($expected, $names);
        }

        self::assertLessThan(
            array_search('signup', $names, true),
            array_search('control_plane', $names, true),
            'The control plane must claim its host first, so that two hosts set equal fail safe.',
        );
        self::assertLessThan(
            array_search('main', $names, true),
            array_search('signup', $names, true),
            'Signup must be declared before "main", which matches every host.',
        );
    }

    /**
     * Scoped by a request matcher rather than by `host:`, which is a *regular
     * expression* — `signup.example.com` written into one also accepts
     * `signupXexample.com`, a hostname somebody else can own. Asserted because
     * the failure would be silent: `host:` works perfectly for every name anybody
     * would think to try, and only ever goes wrong for one somebody registered
     * on purpose.
     *
     * **Asked of the compiled firewall map rather than of a configuration file**
     * (XIV-96), which is where this question moved to when the signup firewall
     * moved into the control-plane package. The dotted hostname is spelled with
     * its dots replaced by an ordinary letter — the substitution an unescaped
     * pattern cannot distinguish — and the assertion is that such a request
     * falls through to `main`.
     */
    public function testTheSignupFirewallIsScopedByAMatcherAndNotByARegularExpression(): void
    {
        $host = $this->signupHost()->normalisedHost();
        $lookalike = str_replace('.', 'x', $host);

        self::assertNotSame($host, $lookalike, 'The signup hostname has no dots to mistake.');

        $request = Request::create(sprintf('https://%s/api/signup/v1/requests', $lookalike));

        self::assertSame(
            'main',
            $this->firewallFor($request)->getName(),
            'A hostname that only a regular expression would confuse with the signup host reached its firewall.',
        );
    }

    /**
     * And that the firewall it does claim runs no authentication machinery at
     * all (§8.12).
     *
     * `security: false` compiles to a firewall with no authenticators and no
     * provider, so this asks the compiled configuration for both rather than
     * reading the word out of a file. A provider appearing here would mean a
     * request to an anonymous endpoint had something to hand a stray cookie to.
     */
    public function testTheSignupFirewallAuthenticatesNobody(): void
    {
        $firewall = $this->firewallFor($this->signupRequest());

        self::assertSame('signup', $firewall->getName());
        self::assertFalse($firewall->isSecurityEnabled());
        self::assertNull($firewall->getProvider());
    }

    /**
     * A signup request resolves no tenant, by §4's existing mechanism rather than
     * a second one — which it has to, because a signup is about a customer who
     * does not exist yet.
     */
    public function testTheSignupHostIsServedWithoutATenant(): void
    {
        $systemHosts = static::getContainer()->getParameter('app.system_hosts');
        \assert(\is_array($systemHosts));

        self::assertContains($this->signupHost()->normalisedHost(), $systemHosts);
    }

    /**
     * And it is not the operator console's hostname. `SignupRouteLoader` refuses
     * to start when they are equal; this is the same statement made against the
     * configuration a deployment actually ships with.
     */
    public function testTheSignupHostIsNotTheControlPlaneHost(): void
    {
        $controlPlane = static::getContainer()->get(ControlPlaneHost::class);
        \assert($controlPlane instanceof ControlPlaneHost);

        self::assertNotSame($controlPlane->normalisedHost(), $this->signupHost()->normalisedHost());
    }

    private function signupHost(): SignupHost
    {
        $host = static::getContainer()->get(SignupHost::class);
        \assert($host instanceof SignupHost);

        self::assertTrue($host->isEnabled(), 'the suite runs with signup switched on');

        return $host;
    }

    private function signupRequest(): Request
    {
        return Request::create(sprintf('https://%s/api/signup/v1/requests', $this->signupHost()->normalisedHost()));
    }

    private function firewallFor(Request $request): FirewallConfig
    {
        $map = static::getContainer()->get('security.firewall.map');
        \assert($map instanceof FirewallMap);

        $config = $map->getFirewallConfig($request);
        self::assertInstanceOf(FirewallConfig::class, $config, 'No firewall matched the request at all.');

        return $config;
    }

    /**
     * The firewalls in the order the configuration declares them, off the
     * container's own `security.firewalls` parameter — for the reason
     * {@see ControlPlaneFirewallTest} gives at length, and since XIV-96 for a
     * second one: no single file holds the whole list any more, because the
     * administration surface declares its own two in
     * `packages/control-plane/config/firewalls.php` so that a build without it
     * has neither.
     *
     * Not by walking `FirewallMap`, which holds its matchers in a single-use
     * generator: consuming it here would leave every later
     * `getFirewallConfig()` in this kernel answering null.
     *
     * @return list<string>
     */
    private function declaredFirewalls(): array
    {
        $firewalls = static::getContainer()->getParameter('security.firewalls');
        \assert(\is_array($firewalls));

        /** @var list<string> $names */
        $names = array_values(array_map(strval(...), $firewalls));

        return $names;
    }
}

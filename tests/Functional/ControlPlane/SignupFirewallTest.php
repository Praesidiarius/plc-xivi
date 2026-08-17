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
use Symfony\Component\Yaml\Yaml;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Signup\SignupHost;

/**
 * Where the public signup endpoint sits in `security.yaml`, and why it sits
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
     * would think to try.
     */
    public function testTheSignupFirewallIsScopedByAMatcherAndNotByARegularExpression(): void
    {
        $firewall = $this->securityConfig()['firewalls']['signup'];
        \assert(\is_array($firewall));

        self::assertSame(SignupHost::class, $firewall['request_matcher'] ?? null);
        self::assertArrayNotHasKey('host', $firewall);
        self::assertFalse($firewall['security'] ?? true);
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
     * The firewalls in the order the configuration declares them, read out of
     * `security.yaml` rather than off the compiled map — for the reason
     * {@see ControlPlaneFirewallTest} gives at length: `FirewallMap` holds its
     * matchers in a single-use generator, so walking it here would leave every
     * later `getFirewallConfig()` in this kernel answering null.
     *
     * @return list<string>
     */
    private function declaredFirewalls(): array
    {
        $firewalls = $this->securityConfig()['firewalls'];
        \assert(\is_array($firewalls));

        /** @var list<string> $names */
        $names = array_values(array_map(strval(...), array_keys($firewalls)));

        return $names;
    }

    /** @return array{firewalls: array<string, mixed>} */
    private function securityConfig(): array
    {
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        \assert(\is_string($projectDir));

        $parsed = Yaml::parseFile($projectDir . '/config/packages/security.yaml');
        \assert(\is_array($parsed) && \is_array($parsed['security']) && \is_array($parsed['security']['firewalls']));

        /** @var array{firewalls: array<string, mixed>} $security */
        $security = $parsed['security'];

        return $security;
    }
}

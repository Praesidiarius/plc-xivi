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

/**
 * **The ordering invariant, with a test behind it instead of a warning** (XIV-57).
 *
 * `security.yaml` declares three firewalls and Symfony takes the *first* one
 * whose matcher accepts a request. `main` has no host restriction, so it accepts
 * everything; the control plane's is host-scoped and is declared above it. Swap
 * the two blocks — a merge resolved carelessly, a tidy-up that sorts them
 * alphabetically — and a control-plane sign-in is answered by a firewall whose
 * provider looks people up in `tenant_users`, which is to say in whichever
 * customer's database the hostname happened to resolve to. That is exactly the
 * cross-tenant leak §8.1 and §8.2 are built to make impossible, arriving through
 * a line moved in a configuration file.
 *
 * A comment saying "do not reorder these" would be read by whoever moved them
 * and by nobody else. This asks the **compiled firewall map** what actually
 * happens to a request, so the ordering is checked by the build rather than by
 * attention.
 *
 * Nothing here provisions a tenant, and that is the point: none of these
 * questions is about a customer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ControlPlaneFirewallTest extends KernelTestCase
{
    private const string TENANT_HOST = 'firewall-tenant.localhost';

    /**
     * The one the whole ticket turns on: a request to the control plane is taken
     * by the control-plane firewall, and never falls through to `main`.
     */
    public function testAControlPlaneRequestIsTakenByTheControlPlaneFirewall(): void
    {
        self::assertSame('control_plane', $this->firewallFor($this->controlPlaneRequest())->getName());
    }

    /**
     * And what that buys: the credential presented there is answered by the
     * control plane's own provider.
     *
     * The assertion is written in both directions on purpose. Naming the
     * provider we expect catches a firewall pointed at the wrong one; naming the
     * provider we must never see catches the case this test exists for, where
     * `main` took the request and the provider is correct *for main*.
     */
    public function testAControlPlaneRequestIsNeverAuthenticatedAgainstTenantUsers(): void
    {
        $provider = (string) $this->firewallFor($this->controlPlaneRequest())->getProvider();

        self::assertStringContainsString('operators', $provider);
        self::assertStringNotContainsString('tenant_users', $provider);
    }

    /**
     * The same invariant asserted at its root rather than through its effect: the
     * control plane is above `main` in the declared order.
     *
     * Both are worth having. The two tests above would still pass if somebody
     * gave `main` a `host:` of its own and reordered the pair, which would work
     * today and break the first time a hostname did not match that pattern.
     */
    public function testTheControlPlaneFirewallIsDeclaredBeforeMain(): void
    {
        $names = $this->declaredFirewalls();

        self::assertContains('control_plane', $names);
        self::assertContains('main', $names);
        self::assertLessThan(
            array_search('main', $names, true),
            array_search('control_plane', $names, true),
            'The control-plane firewall must be declared before "main", which matches every host.',
        );
    }

    /**
     * And that it is host-scoped by a request matcher rather than by `host:`.
     *
     * That key is a regular expression, so a hostname written into it is a
     * pattern whose every dot matches any character — `control.example.com` also
     * accepts `controlXexample.com`, which is a hostname somebody else can own.
     * {@see ControlPlaneHost} compares normalised strings instead. Asserted here
     * because the failure would be silent: `host:` works perfectly for every
     * hostname anybody would think to try.
     */
    public function testTheControlPlaneFirewallIsScopedByAMatcherAndNotByARegularExpression(): void
    {
        $firewall = $this->securityConfig()['firewalls']['control_plane'];
        \assert(\is_array($firewall));

        self::assertSame(ControlPlaneHost::class, $firewall['request_matcher'] ?? null);
        self::assertArrayNotHasKey('host', $firewall);
    }

    /** A customer's hostname is still `main`'s, which is the other half of the same question. */
    public function testATenantRequestIsStillTakenByMain(): void
    {
        $request = Request::create(sprintf('https://%s/', self::TENANT_HOST));

        self::assertSame('main', $this->firewallFor($request)->getName());
    }

    /**
     * A control-plane session is not a tenant session, said by the configuration
     * rather than inherited from a default (§8.9).
     *
     * The context is what names the key a firewall stores its token under, so two
     * different contexts is precisely "a token minted here cannot be restored
     * there". Symfony defaults the context to the firewall's own name and would
     * therefore give the right answer with both lines deleted — which is why this
     * asserts the values are *different* as well as what they are: the property
     * that matters is the separation, not the two strings.
     */
    public function testAnOperatorSessionAndATenantSessionAreDifferentContexts(): void
    {
        $control = $this->firewallFor($this->controlPlaneRequest())->getContext();
        $tenant = $this->firewallFor(Request::create(sprintf('https://%s/', self::TENANT_HOST)))->getContext();

        // The separation first, and the two names after it. Written in this
        // order because PHPStan narrows the values on the way through and would
        // otherwise report the interesting assertion as one that cannot fail —
        // which is true of the code as analysed and is exactly the property
        // worth stating.
        self::assertNotSame($control, $tenant);
        self::assertSame('control_plane', $control);
        self::assertSame('main', $tenant);
    }

    /**
     * A control-plane request resolves no tenant, because the host it arrives on
     * is one this installation serves without one (§4).
     *
     * Asserted on the parameter rather than on a request, because this is the
     * *reason* the behaviour holds, and the behaviour itself is checked from the
     * outside in {@see ControlPlaneSignInTest}. Removing the host from
     * `app.system_hosts` would leave the firewall intact and quietly hand every
     * control-plane page a tenant connection belonging to whoever owns that
     * hostname — a change that breaks nothing visible.
     */
    public function testTheControlPlaneHostIsServedWithoutATenant(): void
    {
        $container = static::getContainer();
        $host = $container->get(ControlPlaneHost::class);
        \assert($host instanceof ControlPlaneHost);

        $systemHosts = $container->getParameter('app.system_hosts');
        \assert(\is_array($systemHosts));

        self::assertContains($host->normalisedHost(), $systemHosts);
    }

    private function controlPlaneRequest(): Request
    {
        $container = static::getContainer();
        $host = $container->get(ControlPlaneHost::class);
        \assert($host instanceof ControlPlaneHost);

        return Request::create(sprintf('https://%s%s/', $host->normalisedHost(), ControlPlaneHost::PATH_PREFIX));
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
     * The firewalls in the order the configuration declares them.
     *
     * **Read out of `security.yaml` rather than off the compiled map**, which
     * looks like the weaker choice and is not. `FirewallMap` holds its matchers
     * in a lazy generator — it is a single-use iterator, and walking it here to
     * inspect the order would consume it, so every later call to
     * `getFirewallConfig()` in this kernel would find an empty map and answer
     * null. A test that quietly disarms the thing it is testing is worse than no
     * test, and the two assertions above already ask the compiled map what it
     * actually does. This one states the invariant where a person edits it.
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

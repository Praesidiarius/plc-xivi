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

namespace App\Tests\Unit\ControlPlane;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Route;
use Xivi\ControlPlane\Provisioning\SelfServiceTenantHostname;
use Xivi\ControlPlane\Routing\SignupRouteLoader;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Signup\SignupApiKey;
use Xivi\ControlPlane\Signup\SignupHost;
use Xivi\ControlPlane\Signup\SignupPage;

/**
 * **"Switched off" means no route is registered** (XIV-64, XIV-65), which is the
 * acceptance criterion this class exists to prove — now for two switches rather
 * than one.
 *
 * There are three states a deployment asks for and all three are asserted here:
 * page and endpoint, endpoint only, and neither. The fourth combination — a page
 * with no intake behind it — is not tested because it cannot be constructed:
 * {@see SignupPage} composes the two with an `and`, so an empty `SIGNUP_HOST` is
 * off whatever the page switch says. That is asserted from both sides in
 * {@see testSwitchingBothOffLeavesNoRouteRegisteredAtAll()}, which is the nearest
 * a test can get to proving a state is unreachable.
 *
 * ### Why the loader rather than a booted kernel
 *
 * The claim is about the *routing table*: that when `SIGNUP_HOST` is empty there
 * is no signup route anywhere in it — not a route that answers 404, not a route
 * a listener refuses, nothing. The loader is what produces that table, so asking
 * it directly is asking the thing being claimed about rather than a consequence
 * of it.
 *
 * The alternative was booting a second kernel with the variable cleared, and it
 * is worse in a way worth recording rather than leaving to be rediscovered: the
 * router's matcher is compiled into the kernel's cache directory, and two
 * kernels in the same environment share one. A test that changed the variable and
 * rebooted would be asserting against whichever collection was compiled first, and
 * it would pass or fail depending on test order rather than on the code. The
 * *enabled* half is covered from a real request in `SignupEndpointTest` — the
 * suite runs with signup on — so between the two, both states are asserted where
 * each can actually be seen.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[CoversClass(SignupRouteLoader::class)]
#[CoversClass(SignupPage::class)]
final class SignupRouteLoaderTest extends TestCase
{
    private const string SECRET = 'a-secret-that-is-configured';

    public function testAnEmptyHostRegistersNoRouteAtAll(): void
    {
        $routes = $this->loader(host: '')->load('.', SignupRouteLoader::TYPE);

        self::assertCount(0, $routes, 'switched off is an empty routing table, not a route that refuses');
    }

    /**
     * And with no secret either, because a deployment that has switched signup
     * off has usually not configured anything about it.
     */
    public function testAnEmptyHostIsOffEvenWithNothingElseConfigured(): void
    {
        $routes = $this->loader(host: '', secret: '')->load('.', SignupRouteLoader::TYPE);

        self::assertCount(0, $routes);
    }

    /**
     * **Page and endpoint**, which is the default state and the one the company
     * selling this runs (XIV-65).
     */
    public function testAConfiguredHostRegistersTheEndpointAndThePageOnThatHostOnly(): void
    {
        $routes = $this->loader(host: 'signup.xivi.example')->load('.', SignupRouteLoader::TYPE);

        self::assertSame(
            [
                'signup_api_v1_request',
                'signup_api_v1_slug',
                'signup_confirm',
                'signup_page',
                'signup_page_name',
                'signup_page_submit',
            ],
            self::sortedNames($routes->all()),
        );

        foreach ($routes as $name => $route) {
            self::assertSame('signup.xivi.example', $route->getHost(), $name . ' is bound to the signup host');
            self::assertSame(['https'], $route->getSchemes(), $name . ' carries a secret and stays on TLS');
        }
    }

    /**
     * **Endpoint only** (XIV-65): somebody has built their own site and posts to
     * the published contract, so the built-in page would be a second and worse
     * front door onto the same intake.
     *
     * The assertion that matters is the second one. Switching the page off has to
     * mean its routes are **not in the table** — not a controller that renders
     * nothing, not a template behind a flag. A live component would have failed
     * this test, because a component answers at a route the bundle registers for
     * every host and this feature cannot unregister it; that is why the page is a
     * plain controller, and this is where the difference is visible.
     */
    public function testThePageCanBeSwitchedOffWhileTheEndpointStays(): void
    {
        $routes = $this->loader(host: 'signup.xivi.example', page: false)->load('.', SignupRouteLoader::TYPE);

        self::assertSame(
            ['signup_api_v1_request', 'signup_api_v1_slug', 'signup_confirm'],
            self::sortedNames($routes->all()),
            'the contract is still served',
        );

        foreach (['signup_page', 'signup_page_submit', 'signup_page_name'] as $gone) {
            self::assertNull($routes->get($gone), $gone . ' must not be in the routing table at all');
        }
    }

    /**
     * **Neither** (XIV-65): a single company self-hosting, for whom an open
     * endpoint that records signups is a liability rather than a feature. It is
     * also the shipped default — `.env` leaves `SIGNUP_HOST` empty.
     *
     * Asserted from both sides of the page switch, because the claim is that the
     * two switches *compose*: an empty host is off whatever `SIGNUP_PAGE` says,
     * and a page with no intake behind it is not a state this can be put into.
     */
    public function testSwitchingBothOffLeavesNoRouteRegisteredAtAll(): void
    {
        foreach ([true, false] as $page) {
            $routes = $this->loader(host: '', page: $page)->load('.', SignupRouteLoader::TYPE);

            self::assertCount(0, $routes, sprintf('SIGNUP_PAGE=%s cannot resurrect a page', var_export($page, true)));
        }
    }

    /**
     * The host is normalised through the same function tenancy uses, so a
     * deployment that writes it with a capital or a trailing dot gets the host
     * the tenancy listener will compare against rather than a near-miss.
     */
    public function testTheHostIsNormalised(): void
    {
        $routes = $this->loader(host: 'Signup.Xivi.Example.')->load('.', SignupRouteLoader::TYPE);

        self::assertSame('signup.xivi.example', $routes->get('signup_confirm')?->getHost());
    }

    /**
     * **An endpoint with no credential in front of it must not start**, which
     * {@see SignupApiKey} already refuses at request time. This is the half that
     * says so at deploy time instead of at the first support ticket about signups
     * not working.
     */
    public function testAHostWithNoSecretRefusesToBuildARoutingTable(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/XIVI_SIGNUP_SECRET/');

        $this->loader(host: 'signup.xivi.example', secret: '')->load('.', SignupRouteLoader::TYPE);
    }

    /**
     * Serving an anonymous endpoint on the operator console's hostname is a
     * configuration mistake with no safe reading, so it is refused rather than
     * left to the order of two blocks in `security.yaml`.
     */
    public function testTheSignupHostMayNotBeTheControlPlaneHost(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/CONTROL_PLANE_HOST/');

        $this->loader(host: 'control.xivi.example')->load('.', SignupRouteLoader::TYPE);
    }

    public function testItOnlyClaimsItsOwnResourceType(): void
    {
        $loader = $this->loader(host: 'signup.xivi.example');

        self::assertTrue($loader->supports('.', SignupRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'attribute'));
        self::assertFalse($loader->supports('.', null));
    }

    /**
     * @param array<string, Route> $routes
     *
     * @return list<string>
     */
    private static function sortedNames(array $routes): array
    {
        $names = array_keys($routes);
        sort($names);

        return $names;
    }

    /**
     * The loader with a real attribute loader behind it, because the routes it
     * returns are the controllers' own `#[Route]` attributes and a stub would be
     * asserting against a fixture rather than against the endpoint.
     */
    private function loader(string $host, string $secret = self::SECRET, bool $page = true): SignupRouteLoader
    {
        $signupHost = new SignupHost($host);

        $loader = new SignupRouteLoader(
            $signupHost,
            new ControlPlaneHost('control.xivi.example'),
            new SignupApiKey($secret),
            // The real SignupPage rather than a stub, because the thing under test
            // is partly the *composition* of the two switches: a stub returning a
            // boolean would assert that the loader reads a flag, which is not the
            // claim. Handing it the same host object the loader has means the
            // "page cannot outlive the endpoint" rule is exercised rather than
            // assumed.
            // The hostname decider takes the same host object for the same
            // reason: [XIV-98] made `tenantDomain()` delegate to it, so handing
            // in the real one keeps the page's answer the answer a tenant will
            // actually be routed at.
            new SignupPage($signupHost, new SelfServiceTenantHostname($signupHost), $page),
        );

        // The framework's own subclass, which is what the application uses: it
        // fills in the `_controller` default. Using it rather than a stub means
        // the routes asserted on are the ones a real request would match.
        $loader->setResolver(new LoaderResolver([new AttributeRouteControllerLoader()]));

        return $loader;
    }
}

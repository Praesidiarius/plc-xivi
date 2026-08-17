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
use Xivi\ControlPlane\Routing\SignupRouteLoader;
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Signup\SignupApiKey;
use Xivi\ControlPlane\Signup\SignupHost;

/**
 * **"Switched off" means no route is registered** (XIV-64), which is the
 * acceptance criterion this class exists to prove.
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

    public function testAConfiguredHostRegistersTheThreeRoutesOnThatHostOnly(): void
    {
        $routes = $this->loader(host: 'signup.xivi.example')->load('.', SignupRouteLoader::TYPE);

        self::assertSame(
            ['signup_api_v1_request', 'signup_api_v1_slug', 'signup_confirm'],
            self::sortedNames($routes->all()),
        );

        foreach ($routes as $name => $route) {
            self::assertSame('signup.xivi.example', $route->getHost(), $name . ' is bound to the signup host');
            self::assertSame(['https'], $route->getSchemes(), $name . ' carries a secret and stays on TLS');
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
    private function loader(string $host, string $secret = self::SECRET): SignupRouteLoader
    {
        $loader = new SignupRouteLoader(
            new SignupHost($host),
            new ControlPlaneHost('control.xivi.example'),
            new SignupApiKey($secret),
        );

        // The framework's own subclass, which is what the application uses: it
        // fills in the `_controller` default. Using it rather than a stub means
        // the routes asserted on are the ones a real request would match.
        $loader->setResolver(new LoaderResolver([new AttributeRouteControllerLoader()]));

        return $loader;
    }
}

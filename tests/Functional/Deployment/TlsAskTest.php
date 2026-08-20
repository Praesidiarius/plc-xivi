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

use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What Caddy is told when it asks whether to get a certificate for a hostname
 * (XIV-61, docs/architecture/deployment.md §4.8).
 *
 * ## Why the refusals need a matching admission to mean anything
 *
 * Every interesting assertion here is a refusal, and a refusal is the one result
 * a broken endpoint produces for free. A controller that threw on every request,
 * a route that was never registered, a typo in the path: all of them give 404 to
 * an unknown hostname and would pass a file that only checked the refusals,
 * while telling Caddy to issue no certificates at all and taking every customer
 * of a real deployment offline.
 *
 * So each refusal here is paired with an admission that differs by exactly one
 * thing. Unknown hostname against a real tenant's, off-loopback against
 * on-loopback, forwarded header against the address underneath it. The pair is
 * the test; either half alone proves nothing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TlsAskTest extends WebTestCase
{
    use SharesATenant;

    private const SLUG = 'tlsask';
    private const HOST = 'tlsask.example.test';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->sharedTenant(self::SLUG, [self::HOST]);
    }

    /**
     * The trusted proxies live in a static on `Request` and outlast the test that
     * set them, so a worker that ran the forwarded-header case would go on to
     * answer every later test as though it sat behind a load balancer. Cleared
     * here for the same reason {@see TrustedHostsTest} clears its own globals.
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);

        parent::tearDown();
    }

    public function testACustomersHostnameIsWorthACertificate(): void
    {
        self::assertSame(Response::HTTP_NO_CONTENT, $this->ask(self::HOST));
    }

    public function testAHostnameNobodyIsOnIsNot(): void
    {
        // Differs from the case above only in which name is asked about, so a
        // 404 here is the registry answering rather than the endpoint failing.
        self::assertSame(Response::HTTP_NOT_FOUND, $this->ask('stranger.example.test'));
    }

    public function testTheControlPlaneHostIsServedWithoutBeingATenant(): void
    {
        // §4.3 puts the platform's own names in app.system_hosts, and no row in
        // the registry claims them. An endpoint that only asked the registry
        // would take the control plane offline the first time its certificate
        // came up for renewal.
        $host = self::getContainer()->getParameter('app.control_plane_host');
        self::assertIsString($host);
        self::assertNotSame('', $host);

        self::assertSame(Response::HTTP_NO_CONTENT, $this->ask($host));
    }

    public function testTheSignupHostIsServedToo(): void
    {
        $host = self::getContainer()->getParameter('app.signup_host');
        self::assertIsString($host);
        self::assertNotSame('', $host, 'The suite sets SIGNUP_HOST, so an empty value here means the case is not being covered.');

        self::assertSame(Response::HTTP_NO_CONTENT, $this->ask($host));
    }

    public function testAnEmptyDomainIsRefusedBeforeTheRegistryIsAsked(): void
    {
        self::assertSame(Response::HTTP_NOT_FOUND, $this->ask(''));
    }

    public function testTheNameIsNormalisedTheWayTenancyNormalisesIt(): void
    {
        // A fully qualified name with the trailing dot, in the wrong case, is
        // the same customer. Caddy passes through what the client asked for.
        self::assertSame(Response::HTTP_NO_CONTENT, $this->ask(strtoupper(self::HOST).'.'));
    }

    public function testARequestFromOffTheBoxCannotAskAboutAnybody(): void
    {
        // The enumeration guard. Same hostname as the first test, which passed
        // with 204, so the only difference is where the request came from.
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->ask(self::HOST, remoteAddr: '203.0.113.9'),
        );
    }

    public function testAForwardedHeaderCannotClaimToBeTheLoopback(): void
    {
        // **The trusted proxy is the whole test, and without it this file was
        // decoration.** `Request::getClientIp()` returns REMOTE_ADDR unchanged
        // unless the address it came from is a trusted proxy, so with the suite's
        // default of trusting nothing, a controller written the naive way refuses
        // this request for the same reason the correct one does and the
        // assertion below passes against both. I checked that by weakening the
        // controller to `getClientIp()` and watching this test stay green.
        //
        // Trusting the sender is what a deployment behind a load balancer does,
        // and it is the configuration under which the two implementations
        // disagree: `getClientIp()` now believes the header and admits the
        // request, REMOTE_ADDR still does not.
        Request::setTrustedProxies(['203.0.113.9'], Request::HEADER_X_FORWARDED_FOR);

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->ask(self::HOST, remoteAddr: '203.0.113.9', headers: [
                'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
            ]),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function ask(string $domain, string $remoteAddr = '127.0.0.1', array $headers = []): int
    {
        $this->client->request(
            'GET',
            '/_tls/ask?domain='.urlencode($domain),
            server: ['REMOTE_ADDR' => $remoteAddr] + $headers,
        );

        return $this->client->getResponse()->getStatusCode();
    }
}

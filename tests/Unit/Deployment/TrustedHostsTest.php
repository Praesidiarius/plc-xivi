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

namespace App\Tests\Unit\Deployment;

use App\Deployment\TrustedHosts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the pattern admits, and — the half that takes an installation off the air
 * — what it refuses (XIV-93, docs/architecture.md §4.3).
 *
 * ## The two directions are not equally dangerous, so they are not tested equally
 *
 * A pattern that admits too much is no worse than having none, which is where
 * this application started. A pattern that admits too little answers a paying
 * customer with an empty 400. So most of what follows is about the second: every
 * shape of hostname that must keep working — the wildcard tenant name, the apex,
 * the fully qualified trailing dot, the loopback the health check uses, the
 * control plane on a domain of its own — has a case here, and each of them is a
 * customer's installation if it regresses.
 *
 * The admits-too-much direction is covered by the cases that must be refused:
 * the suffix attack `xivi.app.evil.example`, the label-boundary attack
 * `notxivi.app`, and `xiviXapp` — which is what an unescaped dot in a
 * hand-written `trusted_hosts` entry would let through, and is the mistake §8.9
 * already declined to make once.
 *
 * ## Why {@see testTheFrameworkAgreesWithAdmits} exists
 *
 * `TrustedHosts::admits()` is consulted by `tenant:provision`, by
 * `deploy:check-hosts` and by the listener that explains a 400. All three are
 * worth nothing if they answer differently from the framework that does the
 * actual refusing. That test hands the patterns to `Request::setTrustedHosts()`
 * and asks a real `Request` for its host, which is the same code path a served
 * request takes — so the two cannot drift without something going red.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TrustedHostsTest extends TestCase
{
    /**
     * A deployment's `app.system_hosts`, with a control plane that is
     * deliberately **not** under the customer domain — which is what §8.9 asks
     * for and is therefore the case worth carrying through every test here.
     */
    private const array SYSTEM_HOSTS = [
        'localhost',
        '127.0.0.1',
        '[::1]',
        'php',
        'control.xivi-internal.example',
        '',
    ];

    protected function tearDown(): void
    {
        // `Request` keeps its trusted hosts in a static, so a test that sets
        // them owns the process until it puts them back. Empty is what every
        // other test in this suite runs with.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }

    public function testAnUnconfiguredDeploymentRestrictsNothing(): void
    {
        $hosts = new TrustedHosts('', self::SYSTEM_HOSTS);

        self::assertFalse($hosts->isConfigured());

        // The whole compatibility promise in one assertion: no patterns means
        // `Kernel::preBoot()` never calls `setTrustedHosts()`, so a fresh
        // checkout and the suite behave as they did before this class existed.
        self::assertSame([], $hosts->patterns());
        self::assertSame('', $hosts->pattern());

        // Including the system hosts here would switch checking *on* for
        // everybody and refuse every tenant. It is the one way this class could
        // take an installation dark by being installed.
        self::assertTrue($hosts->admits('acme.xivi.app'));
        self::assertTrue($hosts->admits('anything.at.all'));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('domainLists')]
    public function testWhatADeploymentWritesIsNormalised(string $written, array $expected): void
    {
        self::assertSame($expected, (new TrustedHosts($written, []))->domains());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function domainLists(): iterable
    {
        yield 'one domain' => ['xivi.app', ['xivi.app']];
        yield 'several, spaced' => ['xivi.app, 1plc.ch', ['xivi.app', '1plc.ch']];
        yield 'a trailing comma' => ['xivi.app,', ['xivi.app']];
        yield 'upper case' => ['XIVI.App', ['xivi.app']];

        // Three things somebody writes when they are thinking about DNS. Each
        // means what this variable already means, so each is accepted rather
        // than refused — an installation that starts and serves nobody is a
        // worse outcome than a lenient parser.
        yield 'a wildcard' => ['*.xivi.app', ['xivi.app']];
        yield 'a leading dot' => ['.xivi.app', ['xivi.app']];
        yield 'fully qualified' => ['xivi.app.', ['xivi.app']];

        yield 'a repeat' => ['xivi.app,xivi.app', ['xivi.app']];
        yield 'a single label' => ['localhost', ['localhost']];
    }

    #[DataProvider('rubbish')]
    public function testAnEntryThatIsNotAHostnameIsRefusedRatherThanCompiled(string $written): void
    {
        // Refused loudly, because the alternative is a regular expression that
        // matches nothing, silently, for the domain the deployment believes it
        // just configured.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(TrustedHosts::VARIABLE);

        new TrustedHosts($written, []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rubbish(): iterable
    {
        yield 'a URL' => ['https://xivi.app'];
        yield 'a port' => ['xivi.app:443'];
        yield 'a path' => ['xivi.app/control'];
        yield 'a regular expression' => ['^xivi\.app$'];
        yield 'an interior wildcard' => ['acme.*.xivi.app'];
        yield 'an underscore' => ['xivi_app'];
        yield 'a leading hyphen' => ['-xivi.app'];
    }

    #[DataProvider('hostnames')]
    public function testWhichHostnamesAreAnswered(string $host, bool $admitted): void
    {
        $hosts = new TrustedHosts('xivi.app, 1plc.ch', self::SYSTEM_HOSTS);

        self::assertSame($admitted, $hosts->admits($host));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function hostnames(): iterable
    {
        // Every customer has their own hostname (§4), and that is the entire
        // reason the pattern is a wildcard rather than a list.
        yield 'a tenant' => ['acme.xivi.app', true];
        yield 'a tenant on the second domain' => ['acme.1plc.ch', true];
        yield 'a deeper name' => ['acme.eu.xivi.app', true];
        yield 'the apex itself' => ['xivi.app', true];
        yield 'a hyphenated label' => ['acme-gmbh.xivi.app', true];
        yield 'upper case, as a browser may send it' => ['ACME.xivi.app', true];
        yield 'with a port' => ['acme.xivi.app:8443', true];

        // `Request::getHost()` strips the port and lowercases and does **not**
        // strip the trailing dot, while `TenantResolver::normalize()` does — so
        // a name that resolves a tenant perfectly well today must not start
        // getting a 400.
        yield 'fully qualified' => ['acme.xivi.app.', true];

        // Added by construction rather than by the deployment remembering.
        yield 'the control plane, on a domain of its own' => ['control.xivi-internal.example', true];
        yield 'the loopback the health check uses' => ['localhost', true];
        yield 'the container name' => ['php', true];
        yield 'IPv6 loopback' => ['[::1]', true];

        // The refusals. Each of these is a name somebody else can own.
        yield 'a name nobody serves' => ['evil.example', false];
        yield 'the domain as a suffix' => ['xivi.app.evil.example', false];
        yield 'the domain as part of a label' => ['notxivi.app', false];
        yield 'an unescaped dot would have let this through' => ['xiviXapp', false];
        yield 'the control plane as a suffix' => ['control.xivi-internal.example.evil.test', false];
        yield 'an empty host' => ['', false];
    }

    /**
     * The pattern and the framework's own matcher agree, which is what makes
     * every other check here worth running.
     */
    #[DataProvider('hostnames')]
    public function testTheFrameworkAgreesWithAdmits(string $host, bool $admitted): void
    {
        if ($host === '') {
            // No request carries an empty Host: `getHost()` falls back to
            // SERVER_NAME and then to SERVER_ADDR, so there is nothing here for
            // the framework to be asked about. The case still belongs in the
            // provider, because `admits()` is called with registry rows as well
            // as with headers.
            $this->expectNotToPerformAssertions();

            return;
        }

        $hosts = new TrustedHosts('xivi.app, 1plc.ch', self::SYSTEM_HOSTS);
        Request::setTrustedHosts($hosts->patterns());

        $request = Request::create('/');
        $request->headers->set('HOST', $host);

        if (!$admitted) {
            $this->expectException(SuspiciousOperationException::class);
            $this->expectExceptionMessageMatches('/Untrusted Host/');
            $request->getHost();

            return;
        }

        // Lowercased and stripped of its port by the framework; the trailing dot
        // survives, which is why the pattern has to allow it.
        self::assertSame(
            strtolower(preg_replace('/:\d+$/', '', $host) ?? $host),
            $request->getHost(),
        );
    }

    /**
     * The patterns are handed to Symfony as one comma-separated string and come
     * back apart the way `Kernel::preBoot()` takes them apart.
     */
    public function testThePatternRoundTripsThroughTheParameter(): void
    {
        $hosts = new TrustedHosts('xivi.app', self::SYSTEM_HOSTS);

        self::assertSame(
            $hosts->patterns(),
            preg_split('/\s*+,\s*+(?![^{]*})/', $hosts->pattern()),
        );
    }
}

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

namespace App\Tests\Unit\Monitoring;

use App\Monitoring\JobMonitor;
use App\Monitoring\PingTargets;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * What a ping is, and what it deliberately is not (XIV-126,
 * docs/architecture.md §4.5).
 *
 * Three claims are made about this feature that only a test can hold to:
 *
 *   * **an installation that configures nothing behaves exactly as today** —
 *     asserted as "no HTTP request is made at all", which is the only form of
 *     that claim that cannot drift;
 *   * **a ping carries no tenant or customer information** — asserted as the
 *     absence of a body and of a query string, since those are the two places
 *     anything could be smuggled into a `GET`;
 *   * **[XIV-61]'s exit code 3 survives the trip**, which is the whole reason
 *     the outcome is reported as a number rather than as `/fail`.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class JobMonitorTest extends TestCase
{
    /**
     * The default installation, and the strongest form of "nothing changes":
     * the HTTP client is never asked for anything, so there is no socket, no
     * DNS lookup and no five-second timeout to be paid on a machine with no
     * outbound access.
     */
    public function testAnUnwatchedCommandMakesNoRequestAtAll(): void
    {
        $requests = [];
        $monitor = $this->monitor([], $requests);

        $monitor->started('tenant:usage:collect');
        $monitor->finished('tenant:usage:collect', 0);

        self::assertSame([], $requests);
    }

    public function testAStartedJobPingsTheStartEndpoint(): void
    {
        $requests = [];
        $monitor = $this->monitor(['signup:provision=https://hc-ping.example/aaaa'], $requests);

        $monitor->started('signup:provision');

        self::assertSame([['GET', 'https://hc-ping.example/aaaa/start']], $requests);
    }

    public function testASuccessfulJobPingsZero(): void
    {
        $requests = [];
        $monitor = $this->monitor(['signup:provision=https://hc-ping.example/aaaa'], $requests);

        $monitor->finished('signup:provision', 0);

        self::assertSame([['GET', 'https://hc-ping.example/aaaa/0']], $requests);
    }

    /**
     * **The acceptance criterion, stated as the comparison it is.**.
     *
     * §4.2 gives `tenant:migrate` three codes on purpose: 0 is every tenant
     * current, 1 is a run that could not happen at all, and 3 is a run that
     * happened in which some tenants failed and the rest are fine. A monitor
     * told only "it failed" would flatten 1 and 3 into each other — a deploy
     * that did nothing, and a deploy that left four customers on last week's
     * schema while the new code serves them. Those get different people out of
     * bed.
     *
     * The fourth state is asserted by the first test in this class: a job that
     * did not run pings nothing at all, and the service alerts on the silence.
     */
    public function testPartialFailureIsDistinguishableFromSuccessAndFromNotRunning(): void
    {
        $requests = [];
        $monitor = $this->monitor(['tenant:migrate=https://hc-ping.example/aaaa'], $requests);

        $monitor->finished('tenant:migrate', 0);
        $monitor->finished('tenant:migrate', 1);
        $monitor->finished('tenant:migrate', 3);

        self::assertSame([
            ['GET', 'https://hc-ping.example/aaaa/0'],
            ['GET', 'https://hc-ping.example/aaaa/1'],
            ['GET', 'https://hc-ping.example/aaaa/3'],
        ], $requests);
    }

    /**
     * A process exit status is a byte, and these services accept 0–255. Symfony
     * clamps the same way before returning to the shell, so an unclamped ping
     * would report a number the shell never saw — and, worse, would be answered
     * with a 400 by the service, which for a *failure* ping is the one response
     * that must not be possible.
     */
    public function testAnExitCodeOutsideAByteIsClamped(): void
    {
        $requests = [];
        $monitor = $this->monitor(['tenant:migrate=https://hc-ping.example/aaaa'], $requests);

        $monitor->finished('tenant:migrate', 300);
        $monitor->finished('tenant:migrate', -1);

        self::assertSame([
            ['GET', 'https://hc-ping.example/aaaa/255'],
            ['GET', 'https://hc-ping.example/aaaa/0'],
        ], $requests);
    }

    /**
     * **A ping carries no tenant and no customer information**, and this is the
     * form of that promise a test can keep: a `GET` has exactly two places
     * something could be hidden — the query string and the body — and neither
     * has anything in it.
     *
     * `tenant:usage:collect` is the worst case and the reason the assertion is
     * worth making: by the time it terminates it holds every customer's slug,
     * user count and record count (§8.11), and a future "it would be handy to
     * see which tenant failed in the monitor" is one `?tenant=` away.
     */
    public function testAPingHasNoQueryStringAndNoBody(): void
    {
        $seenUrl = '';
        /** @var array<string, mixed> $seenOptions */
        $seenOptions = [];

        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$seenUrl, &$seenOptions): MockResponse {
                $seenUrl = $url;
                $seenOptions = $options;

                return new MockResponse('OK');
            },
        );

        $monitor = new JobMonitor(
            new PingTargets(['tenant:usage:collect=https://hc-ping.example/aaaa']),
            $client,
            new RecordingLogger(),
        );

        $monitor->finished('tenant:usage:collect', 0);

        self::assertStringNotContainsString('?', $seenUrl);
        self::assertStringNotContainsString('#', $seenUrl);
        self::assertEmpty($seenOptions['body'] ?? '');

        // No version, either: a User-Agent naming the release would turn every
        // ping into a report of what this installation is running, sent to
        // whoever operates the monitor.
        self::assertIsIterable($seenOptions['headers'] ?? null);
        self::assertContains('User-Agent: Xivi', (array) ($seenOptions['headers'] ?? []));
    }

    /**
     * A ping that could not be sent changes nothing about the job, and this is
     * the one place in the codebase where swallowing is right: the consequence
     * of a lost ping is a monitoring service reporting a missing ping, which is
     * what it is for. Contrast [XIV-37], where a swallowed failure leaves nobody
     * anywhere knowing.
     */
    public function testATransportFailureIsLoggedAndDoesNotEscape(): void
    {
        $logger = new RecordingLogger();
        $monitor = new JobMonitor(
            new PingTargets(['signup:provision=https://hc-ping.example/aaaa']),
            new MockHttpClient(static function (): never {
                throw new class('the monitor did not answer') extends \RuntimeException implements TransportExceptionInterface {
                };
            }),
            $logger,
        );

        $monitor->finished('signup:provision', 0);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('could not be sent', $logger->records[0]);
    }

    /**
     * A 4xx is the interesting failure and gets its own sentence: it almost
     * always means the check was deleted at the service while the URL stayed in
     * the environment, which is an installation that believes it is watched and
     * is not.
     */
    public function testARefusedPingSaysSoWithoutFailingTheJob(): void
    {
        $logger = new RecordingLogger();
        $monitor = new JobMonitor(
            new PingTargets(['signup:provision=https://hc-ping.example/aaaa']),
            new MockHttpClient(new MockResponse('not found', ['http_code' => 404])),
            $logger,
        );

        $monitor->finished('signup:provision', 0);

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('refused with HTTP 404', $logger->records[0]);
    }

    /**
     * @param list<string>                $pings
     * @param list<array{string, string}> $requests filled with the method and URL of each request made
     */
    private function monitor(array $pings, array &$requests): JobMonitor
    {
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = [$method, $url];

            return new MockResponse('OK');
        });

        return new JobMonitor(new PingTargets($pings), $client, new RecordingLogger());
    }
}

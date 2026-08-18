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

use App\Monitoring\PingTargets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What `XIVI_MONITOR_PINGS` accepts, and what it refuses out loud (XIV-126,
 * docs/architecture.md §4.5).
 *
 * The refusals are the point of this class rather than the parsing. A monitoring
 * feature has one characteristic way of being useless: it is configured, it is
 * believed to be configured, and it is not actually watching anything. Every
 * `throw` asserted below is one route to that state closed, and each of them
 * would otherwise present as a monitoring service that has never once
 * complained — which is indistinguishable, from a chair, from everything being
 * fine.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PingTargetsTest extends TestCase
{
    /**
     * The shipped default, and the assertion the whole feature's
     * "off by default" claim rests on.
     */
    public function testNothingConfiguredWatchesNothing(): void
    {
        $targets = new PingTargets([]);

        self::assertTrue($targets->isEmpty());
        self::assertNull($targets->for('tenant:usage:collect'));
        self::assertSame([], $targets->commands());
    }

    public function testACommandIsMappedToItsUrl(): void
    {
        $targets = new PingTargets([
            'signup:provision=https://hc-ping.com/aaaa',
            'tenant:usage:collect=https://hc-ping.com/bbbb',
        ]);

        self::assertFalse($targets->isEmpty());
        self::assertSame('https://hc-ping.com/aaaa', $targets->for('signup:provision'));
        self::assertSame('https://hc-ping.com/bbbb', $targets->for('tenant:usage:collect'));
        self::assertNull($targets->for('tenant:purchase:collect'));
    }

    /**
     * A trailing slash is dropped once, here, rather than being worked around
     * every time a suffix is appended. Both spellings are what a person copying
     * a URL out of a browser produces, and `…/uuid//start` is a 404 at every one
     * of these services.
     */
    public function testATrailingSlashIsNotCarriedIntoTheSuffix(): void
    {
        $targets = new PingTargets(['signup:provision=https://hc-ping.com/aaaa/']);

        self::assertSame('https://hc-ping.com/aaaa', $targets->for('signup:provision'));
    }

    /**
     * Whitespace around an entry survives being written across two lines in a
     * deployment's environment file, which is how anybody writes three of these.
     */
    public function testSurroundingWhitespaceIsIgnored(): void
    {
        $targets = new PingTargets(['  signup:provision = https://hc-ping.com/aaaa  ', '', '   ']);

        self::assertSame('https://hc-ping.com/aaaa', $targets->for('signup:provision'));
        self::assertSame(['signup:provision'], $targets->commands());
    }

    /**
     * Two assertions in one, because they are two halves of the same promise:
     * the entry is refused rather than skipped, **and** the refusal names the
     * variable it is about. A message an operator cannot trace back to a line
     * they can edit is a message that becomes a support conversation — this
     * application has a dozen environment variables and "entry 2 is malformed"
     * points at none of them.
     */
    #[DataProvider('malformedEntries')]
    public function testAMalformedEntryIsRefusedRatherThanSkipped(string $entry, string $expected): void
    {
        try {
            new PingTargets([$entry]);
            self::fail('Expected the malformed entry to be refused rather than skipped.');
        } catch (\InvalidArgumentException $e) {
            self::assertMatchesRegularExpression($expected, $e->getMessage());
            self::assertStringContainsString(PingTargets::VARIABLE, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedEntries(): iterable
    {
        yield 'no separator' => ['signup:provision https://hc-ping.com/aaaa', '/no "=" in it/'];
        yield 'no url' => ['signup:provision=', '/missing its URL/'];
        yield 'no command' => ['=https://hc-ping.com/aaaa', '/missing its command name/'];
        yield 'relative url' => ['signup:provision=/ping/aaaa', '/not an absolute URL/'];

        // Not an exotic typo: it is what happens when somebody pastes a `curl`
        // line's target from a shell script that used a variable, and it would
        // otherwise be attempted once per run against a scheme the client cannot
        // speak.
        yield 'wrong scheme' => ['signup:provision=ftp://hc-ping.com/aaaa', '/has to be http or https/'];

        // The one that would half-work: the success ping would arrive and the
        // exit-code ping would go somewhere else entirely, so the check would
        // look healthy on every run including the failing ones.
        yield 'query string' => ['signup:provision=https://hc-ping.com/aaaa?k=1', '/no query string/'];
        yield 'fragment' => ['signup:provision=https://hc-ping.com/aaaa#x', '/no query string and no fragment/'];
    }

    /**
     * Two URLs for one command is a state with no correct resolution: one of
     * them is watching and the other is a check that will alert for ever, and
     * nothing about either end says which.
     */
    public function testTheSameCommandTwiceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/names "signup:provision" twice/');

        new PingTargets([
            'signup:provision=https://hc-ping.com/aaaa',
            'signup:provision=https://hc-ping.com/bbbb',
        ]);
    }
}

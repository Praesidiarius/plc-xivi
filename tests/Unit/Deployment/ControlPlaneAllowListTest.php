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

use App\Deployment\ControlPlaneAllowList;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the control plane's address allow-list admits (XIV-124,
 * docs/architecture/identity-and-access.md §8.9).
 *
 * The policy on its own, with no kernel and no request: does a list of addresses
 * and ranges admit the right callers, in both address families, and does the
 * empty default admit everybody. The half this cannot see — that the address it
 * is asked about is the one Symfony resolved rather than one a caller wrote in a
 * header — is {@see \App\Tests\Functional\ControlPlane\ControlPlaneAllowListTest},
 * which needs a real request to prove it and is where the acceptance criterion
 * about a forged header actually lives.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ControlPlaneAllowListTest extends TestCase
{
    /**
     * The shipped default, and the property everything else is measured against:
     * an installation that sets nothing is not restricted at all.
     */
    public function testAnEmptyListRestrictsNothing(): void
    {
        $list = new ControlPlaneAllowList('');

        self::assertFalse($list->isConfigured());
        self::assertTrue($list->admits('198.51.100.7'));
        self::assertTrue($list->admits('2001:db8::1'));

        // Even an address that is not an address, because the question being
        // asked is "does this deployment restrict anything" and the answer is
        // no. A false here would make deploy:check-control-plane report a
        // restriction that does not exist.
        self::assertTrue($list->admits(null));
    }

    /** A comma-separated list is what a deployment writes, spaces and all. */
    public function testEntriesAreParsedAndWhitespaceIsForgiven(): void
    {
        $list = new ControlPlaneAllowList(' 198.51.100.7 , 203.0.113.0/24 ,, ');

        self::assertTrue($list->isConfigured());
        self::assertSame(['198.51.100.7', '203.0.113.0/24'], $list->entries());
        self::assertSame([], $list->rejected());
    }

    /**
     * A single address admits itself and nothing beside it.
     *
     * The neighbour matters: `IpUtils::checkIp4()` treats an entry with no
     * prefix as a `/32`, and a test that only asserted the positive would pass
     * for an implementation that compared the first three octets.
     */
    public function testASingleIpv4AddressAdmitsOnlyItself(): void
    {
        $list = new ControlPlaneAllowList('198.51.100.7');

        self::assertTrue($list->admits('198.51.100.7'));
        self::assertFalse($list->admits('198.51.100.8'));
        self::assertFalse($list->admits('198.51.100.0'));
    }

    /**
     * **CIDR ranges, because an office is a range and a VPN is a range.**.
     *
     * @param bool $admitted whether this address is inside 203.0.113.0/24
     */
    #[DataProvider('addressesAroundAnIpv4Range')]
    public function testAnIpv4RangeAdmitsItsMembersAndNobodyElse(string $address, bool $admitted): void
    {
        $list = new ControlPlaneAllowList('203.0.113.0/24');

        self::assertSame($admitted, $list->admits($address));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function addressesAroundAnIpv4Range(): iterable
    {
        yield 'the network address' => ['203.0.113.0', true];
        yield 'the middle' => ['203.0.113.42', true];
        yield 'the broadcast address' => ['203.0.113.255', true];
        yield 'one below' => ['203.0.112.255', false];
        yield 'one above' => ['203.0.114.0', false];
        yield 'a different network entirely' => ['198.51.100.42', false];
    }

    /**
     * **IPv6, which is why this uses `IpUtils` rather than comparing strings.**.
     *
     * The compressed and expanded forms of the same address are the same
     * address, and a `strcmp` allow-list would admit one and refuse the other —
     * a difference decided by whichever form the operator's network happens to
     * present, which is not a thing an operator can be asked to know.
     */
    public function testIpv6AddressesAndRangesWork(): void
    {
        $list = new ControlPlaneAllowList('2001:db8:1::/48,::1');

        self::assertTrue($list->admits('2001:db8:1::1'));
        self::assertTrue($list->admits('2001:db8:1:ffff::9'));
        self::assertFalse($list->admits('2001:db8:2::1'));

        self::assertTrue($list->admits('::1'));
        self::assertTrue($list->admits('0:0:0:0:0:0:0:1'), 'the same address, written out');
    }

    /**
     * A list may hold both families, and one family never answers for the other.
     *
     * `IpUtils::checkIp()` picks its comparison from the family of the address
     * being asked about, so this is the framework's property rather than ours —
     * asserted anyway, because a mixed list is exactly what a deployment with a
     * dual-stacked office will write and "it presumably works" is not something
     * to find out at two in the morning.
     */
    public function testAMixedListAnswersForBothFamilies(): void
    {
        $list = new ControlPlaneAllowList('203.0.113.0/24,2001:db8:1::/48');

        self::assertTrue($list->admits('203.0.113.9'));
        self::assertTrue($list->admits('2001:db8:1::9'));
        self::assertFalse($list->admits('198.51.100.9'));
        self::assertFalse($list->admits('2001:db8:2::9'));
    }

    /**
     * **An address that could not be resolved is refused, not admitted.**.
     *
     * `Request::getClientIp()` returns null when there is no `REMOTE_ADDR`.
     * "Cannot tell where this came from" is not an answer to resolve in favour
     * of letting somebody into the surface that can see every customer.
     */
    public function testAnUnknownAddressIsRefusedOnceAListExists(): void
    {
        $list = new ControlPlaneAllowList('203.0.113.0/24');

        self::assertFalse($list->admits(null));
        self::assertFalse($list->admits(''));
        self::assertFalse($list->admits('not-an-address'));
    }

    /**
     * **The judgement call: rubbish is dropped and remembered, and the list
     * stays in force.**.
     *
     * The alternative — deciding that a list with nothing usable in it is a list
     * that was never configured — is the failure this whole feature is about: a
     * restriction that silently stops restricting while the person who set it
     * goes on believing in it. Failing closed costs the operator who made the
     * typo and nobody else, and `deploy:check-control-plane` is how they find
     * out before it costs them anything at all.
     *
     * @param string $entry something somebody might write that is not an address
     */
    #[DataProvider('rubbish')]
    public function testAnEntryThatIsNotAnAddressAdmitsNobodyAndSwitchesNothingOff(string $entry): void
    {
        $list = new ControlPlaneAllowList($entry);

        self::assertTrue($list->isConfigured(), 'a typo must not read as "not configured"');
        self::assertSame([], $list->entries());
        self::assertSame([$entry], $list->rejected());
        self::assertFalse($list->admits('198.51.100.7'));
    }

    /** @return iterable<string, array{string}> */
    public static function rubbish(): iterable
    {
        yield 'a hostname' => ['office.example.com'];
        yield 'a URL' => ['https://198.51.100.7'];
        yield 'an address with a port' => ['198.51.100.7:443'];
        yield 'a wildcard' => ['198.51.100.*'];
        yield 'a range in the other notation' => ['198.51.100.1-198.51.100.9'];
        yield 'a prefix that is not a number' => ['198.51.100.0/twentyfour'];

        // The one that would otherwise be silent and wrong rather than merely
        // wrong: IpUtils::checkIp4() caps a prefix above 32 at 32, so this would
        // become the single host 198.51.100.0 — an entry that looks like a range
        // and admits one address.
        yield 'an IPv6 prefix on an IPv4 address' => ['198.51.100.0/64'];
    }

    /**
     * A good entry beside a bad one keeps working, and the bad one is still
     * reported.
     *
     * This is the shape a real mistake takes — a list of four offices, one of
     * which was pasted with a stray character — and the operator needs to be
     * told about the fourth without the other three being thrown away.
     */
    public function testAGoodEntryBesideABadOneStillAdmits(): void
    {
        $list = new ControlPlaneAllowList('203.0.113.0/24,office.example.com');

        self::assertSame(['203.0.113.0/24'], $list->entries());
        self::assertSame(['office.example.com'], $list->rejected());
        self::assertTrue($list->admits('203.0.113.9'));
        self::assertFalse($list->admits('198.51.100.9'));
    }

    /** The same address twice is one entry, because a list is not a bag. */
    public function testDuplicatesAreCollapsed(): void
    {
        $list = new ControlPlaneAllowList('198.51.100.7,198.51.100.7');

        self::assertSame(['198.51.100.7'], $list->entries());
    }
}

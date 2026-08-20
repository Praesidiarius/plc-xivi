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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xivi\ControlPlane\Signup\DisposableEmailDomains;

/**
 * The throwaway-address list, and the mistake it must not make (XIV-125).
 *
 * **The interesting half of this class is
 * {@see testAFreeMailboxIsNotAThrowawayOne} and its data provider.** Everything else here is arithmetic on strings. That
 * test is the ticket's central judgement written as an assertion: blocking a
 * real business is worse than admitting a throwaway, and the way this feature
 * fails at that is by confusing *free* with *disposable*. A great many
 * one-person companies read their post at `gmail.com`, `gmx.ch` or `bluewin.ch`,
 * and every one of them is exactly the customer this product is for. So the
 * providers in that list are asserted to pass, by name, and a change to
 * {@see DisposableEmailDomains::DOMAINS} that quietly picked one up fails here
 * rather than in a support mail nobody sends.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DisposableEmailDomainsTest extends TestCase
{
    /**
     * The providers a real small business plausibly uses, and none of them may
     * ever be refused.
     *
     * Deliberately longer than the throwaway list and deliberately not only the
     * American ones: this product is sold in Switzerland, Germany, France and
     * Italy, so `bluewin.ch`, `web.de`, `orange.fr` and `libero.it` are what a
     * customer here actually writes on a form. A list of five American webmail
     * hosts would have passed a test and refused a company in Fribourg.
     *
     * @return iterable<string, array{string}>
     */
    public static function freeMailProviders(): iterable
    {
        foreach ([
            'gmail.com',
            'googlemail.com',
            'outlook.com',
            'hotmail.com',
            'hotmail.de',
            'live.com',
            'msn.com',
            'yahoo.com',
            'yahoo.co.uk',
            'icloud.com',
            'me.com',
            'aol.com',
            'proton.me',
            'protonmail.com',
            'pm.me',
            'tutanota.com',
            'zoho.com',
            'fastmail.com',
            'gmx.ch',
            'gmx.de',
            'gmx.net',
            'web.de',
            't-online.de',
            'bluewin.ch',
            'sunrise.ch',
            'hispeed.ch',
            'swissonline.ch',
            'orange.fr',
            'free.fr',
            'wanadoo.fr',
            'laposte.net',
            'libero.it',
            'virgilio.it',
            'tiscali.it',
            'alice.it',
        ] as $domain) {
            yield $domain => [$domain];
        }
    }

    /**
     * The services whose entire product is an address nobody keeps.
     *
     * @return iterable<string, array{string}>
     */
    public static function throwawayProviders(): iterable
    {
        foreach ([
            'mailinator.com',
            'guerrillamail.com',
            'sharklasers.com',
            'grr.la',
            '10minutemail.com',
            'yopmail.com',
            'trashmail.com',
            'temp-mail.org',
            'maildrop.cc',
            'throwawaymail.com',
        ] as $domain) {
            yield $domain => [$domain];
        }
    }

    /**
     * **The test this class exists for.** See the class docblock.
     */
    #[DataProvider('freeMailProviders')]
    public function testAFreeMailboxIsNotAThrowawayOne(string $domain): void
    {
        self::assertFalse(
            new DisposableEmailDomains()->covers('owner@' . $domain),
            $domain . ' is where real small businesses read their post; refusing it loses a customer '
            . 'who is never told why',
        );
    }

    /**
     * And the same assertion from inside the list, which is what stops the one
     * above being satisfiable by an empty file.
     *
     * A test that only ever asserts absences passes perfectly on a feature that
     * was deleted. This is the corroboration: the list refuses the services it
     * is for.
     */
    #[DataProvider('throwawayProviders')]
    public function testAThrowawayProviderIsRefused(string $domain): void
    {
        self::assertTrue(new DisposableEmailDomains()->covers('someone@' . $domain), $domain);
    }

    /**
     * Mailinator hands out `anything.mailinator.com`, so a match one dot deep
     * would be no match at all.
     *
     * The widening is asserted in both directions, because the direction that
     * costs a customer is the second: `mailinator.com.evil.example` is not
     * Mailinator, and a `str_contains` would have said it was.
     */
    public function testASubdomainOfAListedServiceIsTheSameService(): void
    {
        $domains = new DisposableEmailDomains();

        self::assertTrue($domains->covers('someone@inbox.mailinator.com'));
        self::assertTrue($domains->covers('someone@a.b.mailinator.com'));

        self::assertFalse(
            $domains->covers('someone@mailinator.com.acme.example'),
            'a listed name appearing inside somebody else\'s domain is somebody else\'s domain',
        );
        self::assertFalse(
            $domains->covers('someone@notmailinator.com'),
            'the boundary is a dot, not a suffix',
        );
    }

    /**
     * Case and a trailing dot are the two ways the same domain can be written
     * differently, and both would otherwise be a way straight past this.
     */
    public function testTheSameDomainWrittenDifferentlyIsTheSameDomain(): void
    {
        $domains = new DisposableEmailDomains();

        self::assertTrue($domains->covers('Someone@MAILINATOR.COM'));
        self::assertTrue($domains->covers('someone@mailinator.com.'), 'a fully qualified name');
    }

    /**
     * The domain is what follows the last `@`, because the local part may
     * legally contain one.
     */
    public function testTheDomainIsWhatFollowsTheLastAt(): void
    {
        self::assertSame('acme.example', DisposableEmailDomains::domainOf('"a@b"@acme.example'));
        self::assertSame('', DisposableEmailDomains::domainOf('not-an-address'));
    }

    /**
     * Hygiene the matcher depends on rather than tidiness.
     *
     * An uppercase entry could never match, since the address is lowercased
     * before it is compared, so it would sit in the list looking like a defence
     * and be none. A duplicate is harmless and is the sign of somebody adding a
     * domain without reading the list, which is the same thing that produces a
     * wrong entry.
     */
    public function testTheListIsLowercaseAndFreeOfDuplicates(): void
    {
        $domains = DisposableEmailDomains::DOMAINS;

        self::assertSame(array_map(mb_strtolower(...), $domains), $domains, 'entries are compared lowercased');
        self::assertSame(array_values(array_unique($domains)), $domains, 'no duplicates');
    }

    /**
     * And the two lists in this file may never meet.
     *
     * Stated as a set operation as well as one address at a time, because the
     * per-address tests answer "is this domain refused" and this one answers the
     * question somebody adding an entry actually has: is what I am adding a
     * mailbox real people keep.
     */
    public function testNoFreeMailProviderIsOnTheList(): void
    {
        $free = array_map(static fn (array $case): string => $case[0], iterator_to_array(self::freeMailProviders()));

        self::assertSame([], array_values(array_intersect(DisposableEmailDomains::DOMAINS, $free)));
    }
}

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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\ControlPlane\Signup\SelfServiceSlug;

/**
 * What a self-service customer may be called (XIV-64).
 *
 * The class this covers exists because **there are two slug rules in this system
 * and they must stay different**, which is the sort of statement that survives
 * exactly as long as nobody tidies it. So the first test here is not about
 * behaviour at all: it asserts the two patterns disagree in both directions, and
 * it fails if somebody unifies them — whichever way round they do it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[CoversClass(SelfServiceSlug::class)]
final class SelfServiceSlugTest extends TestCase
{
    /** A stand-in for a deployment's `app.system_hosts`, control plane included. */
    private const array SYSTEM_HOSTS = ['localhost', '127.0.0.1', '[::1]', 'php', 'control.xivi.example'];

    /**
     * The two rules differ, and **each refuses what the other permits**.
     *
     * `TenantProvisioner::SLUG_PATTERN` permits underscores and forbids hyphens,
     * because a provisioning slug is also a PostgreSQL database and role name.
     * A self-service slug becomes a DNS label, where a hyphen is ordinary and an
     * underscore is not legal at all — `my_company.xivi.app` is not a hostname.
     *
     * Both directions are asserted on purpose. One direction alone would pass if
     * somebody replaced one pattern with the other, which is exactly the tidying
     * this is here to catch. See docs/architecture.md §8.12 and the class docblock
     * of {@see SelfServiceSlug} for the argument.
     */
    public function testTheTwoSlugRulesAreDeliberatelyDifferent(): void
    {
        $provisioning = new \ReflectionClassConstant(TenantProvisioner::class, 'SLUG_PATTERN')->getValue();
        self::assertIsString($provisioning);

        self::assertSame(
            1,
            preg_match($provisioning, 'acme_ag'),
            'provisioning permits underscores, because the slug is also a Postgres identifier',
        );
        self::assertSame(
            0,
            preg_match($provisioning, 'acme-ag'),
            'and forbids hyphens, which is why it must not be the rule for a hostname',
        );

        self::assertSame(
            1,
            preg_match(SelfServiceSlug::PATTERN, 'acme-ag'),
            'self-service permits hyphens, because the slug is a DNS label',
        );
        self::assertSame(
            0,
            preg_match(SelfServiceSlug::PATTERN, 'acme_ag'),
            'and forbids underscores, which are not legal in a hostname',
        );
    }

    #[DataProvider('legalNames')]
    public function testALegalNameIsAccepted(string $slug): void
    {
        self::assertTrue($this->slugs()->isValid($slug), $slug);
    }

    /** @return iterable<string, array{string}> */
    public static function legalNames(): iterable
    {
        yield 'a single letter' => ['a'];
        yield 'a single digit, which RFC 1123 allows a label to start with' => ['7'];
        yield 'the ordinary case' => ['acme'];
        yield 'hyphenated' => ['acme-ag'];
        yield 'digits inside' => ['acme-24'];
        yield 'the longest label there is' => [str_repeat('a', 63)];
    }

    #[DataProvider('illegalNames')]
    public function testAnIllegalNameIsRefused(string $slug): void
    {
        self::assertFalse($this->slugs()->isValid($slug), $slug);
    }

    /** @return iterable<string, array{string}> */
    public static function illegalNames(): iterable
    {
        yield 'empty' => [''];
        yield 'an underscore, which provisioning would have accepted' => ['acme_ag'];
        yield 'a leading hyphen' => ['-acme'];
        yield 'a trailing hyphen' => ['acme-'];
        yield 'upper case' => ['Acme'];
        yield 'a dot, which would be two labels' => ['acme.ag'];
        yield 'one character too long' => [str_repeat('a', 64)];
        yield 'a space' => ['acme ag'];
    }

    /**
     * Derivation is the endpoint's job rather than the form's, so that the name
     * shown before submission is the name that gets recorded.
     */
    #[DataProvider('companyNames')]
    public function testANameIsDerivedFromTheCompany(string $company, string $expected): void
    {
        $derived = $this->slugs()->derive($company);

        self::assertSame($expected, $derived);
        self::assertTrue(
            $derived === '' || $this->slugs()->isValid($derived),
            'whatever is derived has to satisfy the rule it will be checked against',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function companyNames(): iterable
    {
        yield 'the ordinary case' => ['Acme AG', 'acme-ag'];
        yield 'punctuation becomes a separator rather than vanishing' => ['A+B Consulting', 'a-b-consulting'];
        yield 'legal forms with dots' => ['Meier & Co. GmbH', 'meier-co-gmbh'];
        // The one that would differ between a server and a page's JavaScript,
        // which is the whole argument for deriving on one side only: a German
        // reader expects `ae`, and Symfony's default rules give `a`.
        yield 'German transliteration' => ['Bäckerei Müller', 'baeckerei-mueller'];
        yield 'leading and trailing noise is trimmed' => ['  --Acme--  ', 'acme'];
        // 63 characters of `ab-` land exactly on a separator, which is the one
        // thing the pattern forbids at the end — so the cut is trimmed again and
        // the answer is 62 characters rather than an illegal 63.
        yield 'a long name is cut to a legal label' => [str_repeat('ab ', 40), str_repeat('ab-', 20) . 'ab'];
        yield 'nothing usable is an empty answer rather than an invented one' => ['!!! ???', ''];
        // Every other language keeps what it had, which is what makes pinning the
        // transliteration cheap: the locale maps only add expansions on top of the
        // generic ASCII rules, so an accent is still folded the ordinary way.
        yield 'accents outside the pinned locale are still folded' => ['Café Étoile', 'cafe-etoile'];
    }

    /**
     * **One company name, one address, whatever language anybody is reading in**
     * (XIV-100).
     *
     * The bug reported was that a preview said `muller-bau-ag` and the submission
     * created `mueller-bau-ag`, which looked like two derivations and was not:
     * there was one, and it took the request's `locale` — an *optional* field
     * whose documented job is to choose the language of the confirmation mail.
     * Two requests, one optional field, and nothing obliging a caller to send the
     * same value to both.
     *
     * The fix removed the parameter rather than passing it more carefully, and
     * this is the test that stops it coming back: `derive()` takes exactly one
     * argument, so there is no longer anything for two callers to disagree about.
     * A hostname is permanent and belongs to the company; which language somebody
     * had the form open in is not the sort of fact that gets to decide one.
     */
    public function testTheDerivationTakesNothingFromTheRequest(): void
    {
        $derive = new \ReflectionMethod(SelfServiceSlug::class, 'derive');

        self::assertSame(
            1,
            $derive->getNumberOfParameters(),
            'derive() takes the company name and nothing else; see XIV-100',
        );

        // And the rule that was chosen. The reported name, spelled the German way
        // — asserted through its effect rather than by comparing the constant to
        // its own value, which is a tautology PHPStan is right to refuse.
        self::assertSame('mueller-bau-ag', $this->slugs()->derive('Müller Bau AG'));
    }

    /**
     * The control-plane host is reserved, **by its first label**.
     *
     * This is the acceptance criterion that matters most and the one a fixed list
     * of words would not have covered. [XIV-57] made `tenant:provision` refuse to
     * route a tenant to a system host — but that refusal fires when [XIV-98] runs,
     * long after somebody has confirmed an address and been told the name is
     * theirs. Reserving the *label* is what actually protects it: a control plane
     * at `control.xivi.example` is collided with by a signup for `control` under
     * the same domain, not by one for the string `control.xivi.example`.
     */
    public function testEverySystemHostAndTheControlPlaneAreReserved(): void
    {
        $slugs = $this->slugs();

        self::assertTrue($slugs->isReserved('control'), "the control plane's own label");
        self::assertTrue($slugs->isReserved('localhost'), 'a single-label system host');
        self::assertTrue($slugs->isReserved('php'), 'the container-internal name');

        // And a deployment whose control plane is somewhere else reserves that
        // instead, which is what makes this derived rather than a word list.
        // `control` cannot serve as the counter-example, because it is on the
        // conventional list as well and would be reserved either way. `php` is on
        // neither list for this deployment, which is what shows the derivation is
        // per deployment rather than a longer word list.
        $elsewhere = new SelfServiceSlug(['localhost'], 'operations.xivi.example');
        self::assertTrue($elsewhere->isReserved('operations'), 'this deployment names its console differently');
        self::assertFalse($elsewhere->isReserved('php'), 'and does not serve a host by that name');
    }

    #[DataProvider('reservedWords')]
    public function testTheConventionalNamesAreReserved(string $slug): void
    {
        self::assertTrue($this->slugs()->isReserved($slug), $slug);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedWords(): iterable
    {
        foreach (['www', 'admin', 'api', 'mail', 'app', 'control', 'status', 'support'] as $word) {
            yield $word => [$word];
        }
    }

    public function testAnOrdinaryNameIsNotReserved(): void
    {
        self::assertFalse($this->slugs()->isReserved('acme'));
    }

    /**
     * Addresses that are not legal labels are dropped rather than carried as
     * reserved noise nobody could have typed anyway.
     */
    public function testAnAddressIsNotTurnedIntoAReservedName(): void
    {
        self::assertSame(
            [],
            array_filter($this->slugs()->reservedNames(), static fn (string $name): bool => str_contains($name, '.')
                || str_contains($name, '[')),
            'every reserved name is itself a legal label',
        );
    }

    private function slugs(): SelfServiceSlug
    {
        return new SelfServiceSlug(self::SYSTEM_HOSTS, 'control.xivi.example');
    }
}

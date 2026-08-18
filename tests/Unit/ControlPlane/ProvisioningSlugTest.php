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
use Xivi\ControlPlane\Provisioning\ProvisioningSlug;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\ControlPlane\Signup\SelfServiceSlug;

/**
 * The one translation from a self-service name to a provisioning name (XIV-98).
 *
 * `SelfServiceSlugTest` asserts that the two patterns *disagree*, from both
 * sides, so that replacing either with the other fails the build. This is the
 * class that has to exist because they disagree, and what it asserts is the
 * three properties the rest of the feature leans on:
 *
 *   1. **Every name the translation produces is one `provision()` accepts.**
 *      Asserted against `TenantProvisioner::SLUG_PATTERN` itself rather than
 *      against a copy of it, so the day somebody narrows that pattern this
 *      fails here instead of in a cron run.
 *   2. **The translation is injective**, which is what lets the intake keep one
 *      uniqueness rule instead of two. The proof is in the class docblock — the
 *      map is a bijection between two disjoint alphabets — and this exercises
 *      the shapes that would break it if it were not.
 *   3. **The map is partial, and the gaps are exactly where the two rules
 *      disagree about something other than separators.** Those are the names the
 *      intake has to refuse at the door, and a test that did not name them would
 *      leave somebody free to "simplify" the check away.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[CoversClass(ProvisioningSlug::class)]
final class ProvisioningSlugTest extends TestCase
{
    /**
     * The translation itself, on the names it is defined for.
     *
     * `acme` is the overlap where the two rules already agree and the
     * translation is the identity; the rest are the ordinary case, where a
     * hostname's separator becomes an identifier's.
     */
    #[DataProvider('translations')]
    public function testAHyphenBecomesAnUnderscoreAndNothingElseChanges(string $signup, string $expected): void
    {
        self::assertSame($expected, ProvisioningSlug::forSignupSlug($signup));
    }

    /** @return iterable<string, array{string, string}> */
    public static function translations(): iterable
    {
        yield 'already agreed on' => ['acme', 'acme'];
        yield 'one separator' => ['acme-bau', 'acme_bau'];
        yield 'several' => ['a-b-c-d', 'a_b_c_d'];
        yield 'digits are kept' => ['acme24', 'acme24'];
        yield 'consecutive separators survive as themselves' => ['acme--bau', 'acme__bau'];
    }

    /**
     * Everything it produces is a name `provision()` will accept.
     *
     * The pattern is read off the provisioner rather than repeated here, which
     * is the whole value of the assertion: it is a claim about the two classes
     * agreeing, and a local copy of the regular expression would turn it into a
     * claim about this file agreeing with itself.
     */
    #[DataProvider('translations')]
    public function testWhatItProducesIsAcceptedByTheProvisioner(string $signup, string $expected): void
    {
        self::assertSame(1, preg_match(TenantProvisioner::SLUG_PATTERN, $expected));
        self::assertTrue(ProvisioningSlug::isProvisionable($signup));
    }

    /**
     * **Two customers cannot be translated onto one name.**.
     *
     * The property the intake's uniqueness rule rests on. A self-service slug
     * contains no underscore — `SelfServiceSlug::PATTERN` does not permit one —
     * so the map sends a character that never occurs in the input to a character
     * that never occurs in the input, which cannot merge two names. The pairs
     * below are the ones somebody would reach for to break it.
     */
    #[DataProvider('nearMisses')]
    public function testTwoDifferentNamesNeverTranslateToOne(string $first, string $second): void
    {
        self::assertNotSame($first, $second, 'the fixture is not two different names');
        self::assertNotSame(ProvisioningSlug::forSignupSlug($first), ProvisioningSlug::forSignupSlug($second));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nearMisses(): iterable
    {
        yield 'one separator against none' => ['acme-bau', 'acmebau'];
        yield 'one against two' => ['acme-bau', 'acme--bau'];
        yield 'separator in different places' => ['ac-mebau', 'acme-bau'];
    }

    /**
     * The names a hostname allows and an identifier does not.
     *
     * Every one of these is a legal DNS label — asserted here as well, because
     * the point is not that they are malformed but that they are *fine* right up
     * until they have to become a database name. The intake refuses them with
     * `invalid_slug` at the moment somebody asks, and this is the list that says
     * which ones.
     */
    #[DataProvider('untranslatable')]
    public function testALegalHostnameLabelWithNoLegalIdentifierIsRefused(string $signup): void
    {
        self::assertSame(1, preg_match(SelfServiceSlug::PATTERN, $signup), 'the fixture is not a legal DNS label');
        self::assertNull(ProvisioningSlug::forSignupSlug($signup));
        self::assertFalse(ProvisioningSlug::isProvisionable($signup));
    }

    /** @return iterable<string, array{string}> */
    public static function untranslatable(): iterable
    {
        // `a.xivi.app` is a perfectly good hostname; `SLUG_PATTERN` wants at
        // least two characters.
        yield 'a single character' => ['a'];

        // An unquoted PostgreSQL identifier may not begin with a digit, and a
        // DNS label happily may — which is every company named after a number.
        yield 'a leading digit' => ['3m'];
        yield 'a leading digit with more behind it' => ['3m-schweiz'];

        // A label may run to 63 characters and an identifier to 56.
        yield 'one character too long' => [str_repeat('x', ProvisioningSlug::MAX_LENGTH + 1)];
        yield 'the longest legal label' => [str_repeat('x', SelfServiceSlug::MAX_LENGTH)];
    }

    /**
     * The length boundary from both sides, because it is a number copied out of
     * a regular expression.
     *
     * `SLUG_PATTERN` says one letter and then `{1,55}`, which is fifty-six, and
     * {@see ProvisioningSlug::MAX_LENGTH} is that number written where a caller
     * can use it — {@see SelfServiceSlug::derive()} cuts to it. Two ways of
     * saying fifty-six is one way for them to drift, so both are asserted.
     */
    public function testTheLengthLimitIsTheOneTheProvisionerEnforces(): void
    {
        self::assertTrue(ProvisioningSlug::isProvisionable(str_repeat('x', ProvisioningSlug::MAX_LENGTH)));
        self::assertFalse(ProvisioningSlug::isProvisionable(str_repeat('x', ProvisioningSlug::MAX_LENGTH + 1)));
    }
}

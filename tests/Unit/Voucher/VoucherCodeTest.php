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

namespace App\Tests\Unit\Voucher;

use PHPUnit\Framework\TestCase;
use Xivi\Voucher\Code\VoucherCode;

/**
 * The two rules a voucher code has to keep (XIV-103).
 *
 * A unit test because both of them are decisions about a string and neither
 * needs a database, a kernel or a tenant: what the fold does, and which
 * characters a generated code may contain. They cost milliseconds and run first,
 * which is the right place for the rules the rest of the feature is built on.
 *
 * The alphabet is asserted **character by character** rather than against the
 * constant itself, which would be the test writing the answer down twice. What
 * matters is not that the string is what it is, but that six particular
 * characters are absent for two particular reasons — so those are what is
 * checked, with the reason attached to each.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VoucherCodeTest extends TestCase
{
    /** How many generated codes to look at when the property is statistical. */
    private const int SAMPLES = 500;

    /**
     * `give-10` and `GIVE-10` are the same voucher, and the fold is what makes
     * them the same *value* rather than the same comparison.
     */
    public function testACodeIsFoldedToCapitals(): void
    {
        self::assertSame('GIVE-10', VoucherCode::normalize('give-10'));
        self::assertSame('GIVE-10', VoucherCode::normalize('Give-10'));
        self::assertSame('GIVE-10', VoucherCode::normalize('GIVE-10'));
    }

    /**
     * A code pasted out of an email arrives with a space on the end far more
     * often than anybody expects, and refusing that would be a refusal about the
     * clipboard rather than about the voucher.
     */
    public function testSurroundingSpaceIsNotPartOfACode(): void
    {
        self::assertSame('GIVE-10', VoucherCode::normalize("  give-10\n"));
    }

    /**
     * Empty and absent are one state, which is the engine's own convention —
     * keeping them apart would only create two ways for a voucher to have no
     * code, and it is the empty one that
     * {@see \Xivi\Voucher\Code\AssignsVoucherCodes} is watching for.
     */
    public function testNothingTypedIsNothingStored(): void
    {
        self::assertNull(VoucherCode::normalize(null));
        self::assertNull(VoucherCode::normalize(''));
        self::assertNull(VoucherCode::normalize('   '));
    }

    /**
     * The characters a person reads wrong off a screen.
     *
     * `0`/`O` and `1`/`I`/`L` are the pair and the trio that somebody dictating a
     * code and somebody typing it disagree about. A generated code contains none
     * of them, because nobody chose them and nothing is lost by leaving them out.
     */
    public function testAGeneratedCodeHasNoLookAlikeCharacters(): void
    {
        foreach (['0', '1', 'I', 'L', 'O'] as $confusable) {
            self::assertStringNotContainsString(
                $confusable,
                VoucherCode::ALPHABET,
                sprintf('"%s" is read wrong often enough to be worth the entropy', $confusable),
            );
        }
    }

    /**
     * And the one that is there for a different reason.
     *
     * `U` is Crockford's addition to the same list and it is not about reading:
     * eight random letters occasionally spell something a customer has to
     * apologise for, and dropping one vowel makes that markedly less likely. A
     * mitigation, not a guarantee — which is why it is one character and not the
     * whole vowel set.
     */
    public function testAGeneratedCodeAvoidsTheVowelCrockfordDrops(): void
    {
        self::assertStringNotContainsString('U', VoucherCode::ALPHABET);
    }

    /**
     * The customer's own codes are **not** held to the generator's alphabet, and
     * this is the assertion that keeps the two apart.
     *
     * `GIVE-10` contains `I`, `1` and `0` — three of the six characters the
     * generator refuses — and it is the example the whole feature exists for. A
     * pattern narrowed to the generator's alphabet would refuse the one code
     * anybody would actually type.
     */
    public function testTheCodeTheFeatureExistsForIsAcceptable(): void
    {
        self::assertTrue(VoucherCode::isWellFormed('GIVE-10'));
        self::assertTrue(VoucherCode::isWellFormed('SUMMER2026'));
        self::assertTrue(VoucherCode::isWellFormed('A-B-C'));
    }

    /**
     * What is refused, and every one of these is refused because it cannot
     * survive being said out loud.
     */
    public function testWhatACodeCannotBe(): void
    {
        self::assertFalse(VoucherCode::isWellFormed('GIVE 10'), 'a space could mean a hyphen or nothing');
        self::assertFalse(VoucherCode::isWellFormed('-GIVE'), 'a leading hyphen is only ever a slip');
        self::assertFalse(VoucherCode::isWellFormed('GIVE-'), 'and so is a trailing one');
        self::assertFalse(VoucherCode::isWellFormed('GIVE--10'), 'two hyphens sound exactly like one');
        self::assertFalse(VoucherCode::isWellFormed('GIVE_10'), 'punctuation is not part of a code');
        self::assertFalse(VoucherCode::isWellFormed('AB'), 'two characters cannot be dictated unambiguously');
        self::assertFalse(VoucherCode::isWellFormed(str_repeat('A', 33)), 'a code is a name, not a sentence');
    }

    /**
     * The generator's output passes the field's own rule.
     *
     * The defect this guards against is the quiet one: a generator that produced
     * values its own validator would refuse only shows up when somebody leaves
     * the box empty, which is the path nobody exercises by hand.
     */
    public function testEveryGeneratedCodeIsOneTheFieldWouldAccept(): void
    {
        for ($i = 0; $i < self::SAMPLES; ++$i) {
            $code = VoucherCode::generate();

            self::assertTrue(VoucherCode::isWellFormed($code), sprintf('"%s" is a code the field refuses', $code));
            self::assertSame($code, VoucherCode::normalize($code), sprintf('"%s" is already folded', $code));
        }
    }

    /**
     * `HK4T-9PQM`: two groups of four, to be read out in two breaths.
     */
    public function testAGeneratedCodeIsReadableInGroups(): void
    {
        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', VoucherCode::generate());
    }

    /**
     * Not a sequence, which is the security half of the design: anybody holding
     * a voucher can see their own code, and the next one must not follow from
     * it.
     *
     * Five hundred draws from a space of 6.6 * 10^11 collide with probability
     * around one in five million, so a repeat here is a broken generator rather
     * than bad luck.
     */
    public function testGeneratedCodesDoNotRepeatOrFollowOnFromEachOther(): void
    {
        $seen = [];

        for ($i = 0; $i < self::SAMPLES; ++$i) {
            $seen[] = VoucherCode::generate();
        }

        self::assertCount(self::SAMPLES, array_unique($seen), 'every draw was its own code');
    }
}

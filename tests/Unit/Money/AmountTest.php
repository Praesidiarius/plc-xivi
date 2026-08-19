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

namespace App\Tests\Unit\Money;

use PHPUnit\Framework\TestCase;
use Xivi\Core\Money\Amount;

/**
 * The rounding rule, on its own (XIV-16).
 *
 * A unit test because this is where the rappen goes missing. Everything above it
 * — totals, VAT tables, invoices — is arithmetic over this class, and a rule
 * that is only tested through a browser is a rule nobody can read the edges of.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class AmountTest extends TestCase
{
    /** Exact all the way through: 0.1 + 0.2 is 0.30, which a float will not say. */
    public function testArithmeticIsExact(): void
    {
        self::assertSame('0.30', (string) Amount::of('0.1')?->plus(Amount::of('0.2') ?? Amount::zero()));
        self::assertSame('49.75', (string) Amount::of('2.5')?->times(Amount::of('19.90') ?? Amount::zero()));
    }

    /** Two places, and halves go away from zero — ordinary commercial rounding. */
    public function testHalvesRoundAwayFromZero(): void
    {
        self::assertSame('0.01', (string) Amount::of('0.005')?->rounded());
        self::assertSame('-0.01', (string) Amount::of('-0.005')?->rounded());
        self::assertSame('0.00', (string) Amount::of('0.004')?->rounded());
    }

    /** A percentage of an amount, which is how VAT is worked out. */
    public function testAPercentage(): void
    {
        $net = Amount::of('1200.00');
        self::assertNotNull($net);

        self::assertSame('97.20', (string) $net->percent(Amount::of('8.10') ?? Amount::zero())->rounded());
        self::assertSame('31.20', (string) $net->percent(Amount::of('2.60') ?? Amount::zero())->rounded());
    }

    /**
     * Rounding once at the end is not the same as rounding each line, which is
     * the whole reason the rule had to be decided rather than left to happen.
     */
    public function testWhereTheRoundingHappensChangesTheAnswer(): void
    {
        $line = Amount::of('0.55');
        self::assertNotNull($line);

        $rate = Amount::of('8.10') ?? Amount::zero();

        $perLine = $line->percent($rate)->rounded()->plus($line->percent($rate)->rounded());
        $perRate = $line->plus($line)->percent($rate)->rounded();

        self::assertSame('0.08', (string) $perLine);
        self::assertSame('0.09', (string) $perRate, 'and this is the one the engine uses');
    }

    /**
     * The other direction: a price that already has the VAT in it (XIV-116).
     *
     * 19.95 is the number on the shelf and 8.1% is Switzerland's rate, so the net
     * behind it is 19.95 divided by 1.081 — 18.454209… — which rounds to 18.46.
     */
    public function testTakingAPercentageBackOutOfAnAmount(): void
    {
        $rate = Amount::of('8.10') ?? Amount::zero();

        self::assertSame('18.46', (string) Amount::of('19.95')?->withoutPercent($rate));
        self::assertSame('46.16', (string) Amount::of('49.90')?->withoutPercent($rate));
    }

    /**
     * **The case the whole ticket is about**, and the reason the tax is a
     * remainder rather than a second multiplication.
     *
     * Take the net back out of 19.95 and then add 8.1% of *that* on again — the
     * way a shopkeeper doing this by hand in the old engine had to — and the
     * answer is 19.96. A rappen above the price on the shelf, on the customer's
     * own document, with nobody able to explain it. Subtracting instead cannot
     * miss: whatever is left of the gross once the net has come out is exactly
     * what makes the two add back up to what was typed.
     */
    public function testTheTaxIsTheRemainderBecauseMultiplyingBackDoesNotReturnTheGross(): void
    {
        $gross = Amount::of('19.95');
        self::assertNotNull($gross);

        $rate = Amount::of('8.10') ?? Amount::zero();
        $net = $gross->withoutPercent($rate);

        self::assertSame('19.96', (string) $net->plus($net->percent($rate)->rounded()), 'the rappen');
        self::assertSame('19.95', (string) $net->plus($gross->minus($net)), 'and what the engine does');
    }

    /**
     * A rate of nothing divides by one, which is the right reading of "no VAT"
     * rather than a case anybody has to branch on — and a rate nobody could ever
     * have meant changes nothing, because a save must not die on one bad row an
     * import wrote.
     */
    public function testARateOfNothingAndARateNobodyCouldMeanBothLeaveTheAmountAlone(): void
    {
        $gross = Amount::of('19.95');
        self::assertNotNull($gross);

        self::assertSame('19.95', (string) $gross->withoutPercent(Amount::zero()));
        self::assertSame('19.95', (string) $gross->withoutPercent(Amount::of('-100') ?? Amount::zero()));
        self::assertSame('19.95', (string) $gross->withoutPercent(Amount::of('-250') ?? Amount::zero()));
    }

    /**
     * Not a number is null rather than an exception: a comment line has no
     * price, and that is an ordinary line rather than a failure.
     */
    public function testWhatIsNotANumberIsNull(): void
    {
        self::assertNull(Amount::of(null));
        self::assertNull(Amount::of(''));
        self::assertNull(Amount::of('none'));
        self::assertNotNull(Amount::of('0'), 'zero, though, is a number');
    }

    /**
     * A pro-rata share, and the rappen it does not account for (XIV-104).
     *
     * Ten francs over three rates that sold a hundred each is 3.33 three times,
     * which is 9.99. This is where that is *visible*: `shareOf()` rounds and
     * says nothing about the remainder, deliberately, because where the leftover
     * lands is a decision about a document rather than about arithmetic —
     * {@see \Xivi\Core\Money\DerivesTotals} gives it to the last line and
     * `OrderVoucherTest` is where that is asserted.
     */
    public function testAShareIsRoundedAndTheLeftoverIsNotThisClasssBusiness(): void
    {
        $ten = Amount::of('10.00');
        $hundred = Amount::of('100.00');
        $threeHundred = Amount::of('300.00');
        self::assertNotNull($ten);
        self::assertNotNull($hundred);
        self::assertNotNull($threeHundred);

        $share = $ten->shareOf($hundred, $threeHundred);

        self::assertSame('3.33', (string) $share);
        self::assertSame(
            '9.99',
            (string) $share->plus($share)->plus($share),
            'three of them are a rappen short of the ten they came from',
        );
    }

    /** A whole of nothing has no shares in it, rather than dividing by zero. */
    public function testAShareOfNothingIsNothing(): void
    {
        $ten = Amount::of('10.00');
        self::assertNotNull($ten);

        self::assertSame('0.00', (string) $ten->shareOf(Amount::zero(), Amount::zero()));
    }

    /** Whole numbers still read as money, so a stored value has one spelling. */
    public function testAnAmountIsWrittenAtTheCurrencysScale(): void
    {
        self::assertSame('19.90', (string) Amount::of('19.9'));
        self::assertSame('100.00', (string) Amount::of('100'));
        self::assertSame('0.00', (string) Amount::zero());
    }
}

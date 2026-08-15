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

    /** Whole numbers still read as money, so a stored value has one spelling. */
    public function testAnAmountIsWrittenAtTheCurrencysScale(): void
    {
        self::assertSame('19.90', (string) Amount::of('19.9'));
        self::assertSame('100.00', (string) Amount::of('100'));
        self::assertSame('0.00', (string) Amount::zero());
    }
}

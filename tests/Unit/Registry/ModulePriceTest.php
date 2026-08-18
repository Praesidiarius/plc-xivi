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

namespace App\Tests\Unit\Registry;

use App\Registry\Pricing\ModulePrice;
use App\Registry\Pricing\ModulePricing;
use App\Registry\Pricing\PriceCurrency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The combinations a module price is allowed to be, and the ones it is not
 * (XIV-101).
 *
 * A unit test because none of this needs a database: the value object is where
 * the rules live, deliberately, so that a row that could not be true cannot be
 * built by the screen, by the command or by a hydration out of a database
 * somebody edited by hand.
 *
 * The rule that earns the most tests is the smallest one — **a price of zero is
 * refused** — because it is the boundary between two of the three states the
 * ticket asked to keep distinguishable. If `priced 0.00` were storable, "free"
 * would have two spellings, one of which is indistinguishable from a form
 * somebody submitted before finishing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModulePriceTest extends TestCase
{
    public function testNobodyHasDecidedIsNotFree(): void
    {
        $unpriced = ModulePrice::unpriced();

        self::assertSame(ModulePricing::Unpriced, $unpriced->pricing);
        self::assertFalse($unpriced->pricing->isDecided());
        self::assertNull($unpriced->amount);
        self::assertNull($unpriced->amount(), 'not zero — there is no number to give');
        self::assertFalse($unpriced->mayBeOffered());
        self::assertFalse($unpriced->costsMoney());

        self::assertFalse($unpriced->equals(ModulePrice::free()), 'the whole point of the class');
    }

    public function testFreeIsADecisionAndCarriesNoAmount(): void
    {
        $free = ModulePrice::free();

        self::assertSame(ModulePricing::Free, $free->pricing);
        self::assertTrue($free->pricing->isDecided());
        self::assertNull($free->amount);
        self::assertTrue($free->mayBeOffered(), 'a free module is still obtainable');
        self::assertFalse($free->costsMoney());
    }

    public function testNotForSaleIsADecisionToWithhold(): void
    {
        $withheld = ModulePrice::notForSale();

        self::assertSame(ModulePricing::NotForSale, $withheld->pricing);
        self::assertTrue($withheld->pricing->isDecided());
        self::assertFalse($withheld->mayBeOffered());
        self::assertFalse($withheld->costsMoney());
    }

    public function testAPricedModuleCarriesAnAmountAtTwoPlaces(): void
    {
        $priced = ModulePrice::of('49.9');

        self::assertSame(ModulePricing::Priced, $priced->pricing);
        self::assertSame('49.90', $priced->amount, 'normalised, so two typings are one stored value');
        self::assertTrue($priced->costsMoney());
        self::assertTrue($priced->mayBeOffered());
        self::assertSame('49.90', (string) $priced->amount());
    }

    /**
     * §5.9's representation, and the reason it is not a `float`: 0.1 + 0.2 is the
     * classic demonstration, and a price list is where a hundredth of a rappen
     * becomes a figure somebody phones about.
     */
    public function testTheAmountNeverBecomesAFloat(): void
    {
        $priced = ModulePrice::of('1234567.89');

        self::assertIsString($priced->amount);
        self::assertSame('1234567.89', $priced->amount, 'exact, digit for digit');
    }

    /** Half away from zero, which is `Money\Amount`'s rule and not a second one. */
    public function testAnAmountIsRoundedTheWayEveryOtherAmountHereIs(): void
    {
        self::assertSame('19.99', ModulePrice::of('19.985')->amount);
        self::assertSame('19.98', ModulePrice::of('19.984')->amount);
    }

    #[DataProvider('amountsThatAreNotPrices')]
    public function testAnAmountThatIsNotAPriceIsRefused(string $amount): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ModulePrice::of($amount);
    }

    /** @return iterable<string, array{string}> */
    public static function amountsThatAreNotPrices(): iterable
    {
        // The boundary the three states depend on. "Free" has its own case and
        // its own word, so this one is refused rather than quietly accepted as a
        // second way of saying it.
        yield 'zero' => ['0'];
        yield 'zero at scale' => ['0.00'];

        // Rounded before it is judged, so a number that is about to become 0.00
        // is refused as the 0.00 it will be stored as rather than accepted as the
        // positive number it briefly was.
        yield 'below half a rappen' => ['0.004'];

        // A module that pays somebody to install it is not a thing anybody meant,
        // and a minus sign in a price box is a typo far more often than a policy.
        yield 'negative' => ['-49.00'];

        yield 'empty' => [''];
        yield 'words' => ['forty-nine'];
        // `is_numeric` says yes to this and `BigDecimal` does not, which is
        // exactly the gap `Amount::of()` closes and this is the assertion that
        // the closing is still there.
        yield 'hexadecimal' => ['0x1A'];
    }

    // -- the storage round trip, which is where a bad row would arrive --------

    public function testAStoredPairIsRebuiltAsItWasWritten(): void
    {
        foreach ([ModulePrice::unpriced(), ModulePrice::free(), ModulePrice::notForSale(), ModulePrice::of('12.34')] as $price) {
            $rebuilt = ModulePrice::fromStorage($price->pricing, $price->amount);

            self::assertTrue($rebuilt->equals($price), $price->pricing->value . ' survives the round trip');
        }
    }

    /**
     * A row saying "priced" with nothing in the amount column throws where the
     * message can name both halves, rather than being read as free by whatever
     * looks at it next. Reading it as free is the guess that costs money.
     */
    public function testAPricedRowWithNoAmountIsRefusedOnRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('carries no amount');

        ModulePrice::fromStorage(ModulePricing::Priced, null);
    }

    /**
     * And the other direction: an amount left behind on a decision that has none
     * is a number something will read one day, so it is a fault rather than
     * something to drop quietly.
     */
    public function testAnAmountLeftBehindOnAFreeRowIsRefusedOnRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A price left behind');

        ModulePrice::fromStorage(ModulePricing::Free, '49.00');
    }

    // -- the currency, which is the deployment's and not a tenant's -----------

    public function testTheCurrencyIsWhateverTheDeploymentSaidAndNullWhenItSaidNothing(): void
    {
        self::assertSame('CHF', (new PriceCurrency('CHF'))->code());
        self::assertTrue((new PriceCurrency('CHF'))->isChosen());

        // Tidied rather than rejected: `chf ` in an environment file is somebody's
        // answer, and refusing it over whitespace is a worse response than taking
        // it.
        self::assertSame('EUR', (new PriceCurrency(' eur '))->code());

        // §8.6's rule one level up — a guessed currency is wrong quietly, so an
        // unset one stays unset and every reader has to have an answer for null.
        self::assertNull((new PriceCurrency(''))->code());
        self::assertNull((new PriceCurrency('   '))->code());
        self::assertFalse((new PriceCurrency(''))->isChosen());
    }
}

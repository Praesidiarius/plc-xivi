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
use Xivi\Core\Money\VatMode;

/**
 * How a stored value becomes a mode (XIV-116).
 *
 * A unit test because this one mapping is the whole of what stands between a
 * money model that grew a feature and one that restated everybody's invoices.
 * Every order and every invoice in every tenant carries nothing here, and every
 * one of them is priced net — so **"no answer" has to mean "prices exclude VAT",
 * and it has to mean that for every shape "no answer" can arrive in**: a field
 * that was never filled in, a field a customer has deleted, a module whose
 * blueprint has no such field, a record written before the field existed.
 *
 * They reach `of()` as null, as the empty string, or as a key that is simply not
 * in the values array. There is no combination that should produce `Included` by
 * accident, and there is no input at all that should throw: this runs inside a
 * save's transaction, where an exception is a bug rather than a decision (§5.9).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VatModeTest extends TestCase
{
    /** The two things a document can actually say. */
    public function testTheTwoModesReadBackFromWhatIsStored(): void
    {
        self::assertSame(VatMode::Excluded, VatMode::of('excluded'));
        self::assertSame(VatMode::Included, VatMode::of('included'));
    }

    /**
     * **Every way of saying nothing** is a net-priced document.
     *
     * Null is the field nobody filled in and the key a pre-XIV-116 record does not
     * have; the empty string is what a `choice` field's placeholder submits. Both
     * are the ordinary state rather than a fault, and both have to land on the
     * reading every stored record already has.
     */
    public function testAnythingMeaningNothingIsPricesExcludingVat(): void
    {
        self::assertSame(VatMode::Excluded, VatMode::of(null));
        self::assertSame(VatMode::Excluded, VatMode::of(''));
    }

    /**
     * And so is anything nobody could have meant, rather than an exception.
     *
     * A hand-edited request, an import row, a field a customer re-purposed after
     * §5.4 let them rename its options — none of these is a reason to take a
     * save's transaction down, and none of them is a reason to guess *included*,
     * which is the only guess that could restate a total.
     */
    public function testAnythingUnrecognisedFallsToTheSafeReading(): void
    {
        self::assertSame(VatMode::Excluded, VatMode::of('inclusive'));
        self::assertSame(VatMode::Excluded, VatMode::of('INCLUDED'));
        self::assertSame(VatMode::Excluded, VatMode::of(1));
        self::assertSame(VatMode::Excluded, VatMode::of(true));
        self::assertSame(VatMode::Excluded, VatMode::of(['included']));
    }

    /**
     * The shipped options are the enum's, which is what keeps an order's field and
     * an invoice's agreeing on the values a seed copies between them (§3, §5.12).
     *
     * The labels are keys rather than sentences because a module resolves them
     * against its own catalogue as it writes a customer's definitions — so this
     * asserts the shape rather than the wording, which is the customer's from the
     * moment they have the module.
     */
    public function testTheShippedOptionsCoverEveryModeAndAreKeys(): void
    {
        $choices = VatMode::shipped()['choices'];

        self::assertSame(['excluded', 'included'], array_keys($choices));

        foreach ($choices as $value => $label) {
            self::assertSame('vat_mode.' . $value, $label);
            self::assertNotNull(VatMode::tryFrom($value), 'every option is a mode the arithmetic knows');
        }
    }

    /**
     * A generated tenant is priced one way throughout, because a shop is a shop
     * (§5.17).
     *
     * Excluded, so that a demo tenant's figures are identical to the ones the same
     * generator produced before this field existed — which is worth more, while the
     * arithmetic is new, than demonstrating a setting that costs one dropdown to
     * see.
     */
    public function testDemoDataIsPricedOneWayThroughout(): void
    {
        self::assertSame([VatMode::Excluded->value], VatMode::samples());
    }
}

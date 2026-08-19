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

namespace Xivi\Core\Money;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Xivi\Core\Field\Type\CurrencyFieldType;

/**
 * Arithmetic on money, and the one place the rounding rule is written (XIV-16).
 *
 * **Where the rounding happens is a decision, not a detail.** Two and a half
 * hours at 19.90 is 49.75 exactly; three at 19.99 with 8.1% VAT is not, and
 * whether the fraction is dropped on the line or on the document changes the
 * total by a rappen. A rappen is what somebody phones about. So the rule is:
 *
 * - **A line total is rounded to two places** as it is computed, and the
 *   document sums lines that are already round. Rounding at the end instead
 *   would print lines that visibly do not add up to the total under them, which
 *   is the worse of the two errors — the reader can check the arithmetic.
 * - **VAT is computed per rate over the summed net of that rate**, then rounded
 *   once. Not per line: a hundred lines each losing half a rappen is fifty
 *   rappen of tax nobody owes, and the tax authority's own worked examples group
 *   before they round.
 * - **Half away from zero.** Ordinary commercial rounding, and symmetric, so a
 *   credit of 0.005 and a charge of 0.005 round to the same size.
 * - **A price with the VAT already in it rounds the *derived* half and lets it
 *   absorb the remainder** (XIV-116). The gross is the figure somebody typed and
 *   the figure the recipient will check against a shelf, so it is never adjusted;
 *   the net is the rounded quotient and the tax is what is left over. See
 *   {@see self::withoutPercent()} for the arithmetic and §5.9 for the argument.
 *
 * Deliberately *not* rounding to five rappen: that is a rule about paying cash
 * in Switzerland, not about what an invoice says, and applying it here would
 * misstate the VAT base.
 *
 * **Exact throughout**, on `brick/math` rather than floats — the same reason
 * {@see CurrencyFieldType} stores a string. Nothing here ever sees a float, so
 * there is no point on the path where 19.90 becomes 19.899999999999999.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Amount implements \Stringable
{
    /** Cents, like the field type stores. */
    public const int SCALE = CurrencyFieldType::SCALE;

    private function __construct(private BigDecimal $value)
    {
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    /**
     * An amount from whatever a record's data held, or null when that was not a
     * number.
     *
     * Null is the useful answer rather than an exception: a comment line has no
     * price, an article nobody has priced has no price, and neither is an error
     * to be caught somewhere up the stack. Callers that sum treat null as "not
     * part of this sum", which is what makes a line without a price stop being a
     * special case.
     */
    public static function of(mixed $value): ?self
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        try {
            return new self(BigDecimal::of((string) $value));
        } catch (MathException) {
            // is_numeric accepts things BigDecimal does not — hexadecimal, and
            // exponent forms it declines. Not a number for this purpose.
            return null;
        }
    }

    /** Exact, and unrounded: rounding is the caller's decision to spell out. */
    public function times(self $factor): self
    {
        return new self($this->value->multipliedBy($factor->value));
    }

    public function plus(self $addend): self
    {
        return new self($this->value->plus($addend->value));
    }

    /** What is left of this after that — how much of a line is still to invoice (XIV-19). */
    public function minus(self $subtrahend): self
    {
        return new self($this->value->minus($subtrahend->value));
    }

    /**
     * This amount at a percentage of itself — 1200.00 at 8.1 is 97.20.
     *
     * Multiplied by a hundredth rather than divided by a hundred, which is exact
     * and needs no rounding scale of its own.
     */
    public function percent(self $rate): self
    {
        return new self($this->value->multipliedBy($rate->value)->multipliedBy('0.01'));
    }

    /**
     * The other direction: what this amount was before that percentage was added
     * to it — 19.95 at 8.1 is 18.46 (XIV-116).
     *
     * Read it as the inverse of {@see self::percent()} and not as a second way of
     * computing tax: `$gross->withoutPercent($rate)` answers "which net, plus
     * that rate of itself, comes to this gross". Which is why the *tax* is
     * deliberately not returned from here — see below.
     *
     * **Rounded inside, and that is the difference from every other operation on
     * this class.** `times()`, `plus()` and `percent()` are exact and leave
     * rounding to the caller to spell out, because they can be: multiplying two
     * decimals has an exact decimal answer. Division does not. 19.95 ÷ 1.081 is
     * 18.454209… and goes on forever, so there is no unrounded value to hand back
     * and no honest way to defer the decision. brick/math says the same thing in
     * its signature — `dividedBy()` demands a scale and a rounding mode and
     * throws without them — and rather than invent a division helper of our own
     * (there was none here before this ticket) the rule §5.9 already wrote down
     * is applied to the framework's own operation: two places, halves away from
     * zero.
     *
     * **The remainder is not this method's business, and that is the decision.**
     * The obvious next line is `$gross->minus($gross->withoutPercent($rate))`,
     * and that is exactly what {@see DerivesTotals} does: the tax is whatever is
     * left of the gross once the net has been taken out of it, never
     * `$net->percent($rate)` computed afresh. The two differ by a rappen on
     * amounts like 19.95, and computing the tax here would have offered a caller
     * the wrong one of them next to the right one. A rate of exactly nothing
     * divides by one and returns the amount unchanged, which is the correct
     * reading of "no VAT" rather than a special case.
     *
     * A rate of −100% or below would divide by zero or flip the sign, which is
     * not a VAT rate anybody has ever set; the field type's own `min` keeps it
     * from being typed, and this returns the amount untouched for whatever an
     * import wrote, because a save must not die on one bad row.
     */
    public function withoutPercent(self $rate): self
    {
        $divisor = BigDecimal::one()->plus($rate->value->multipliedBy('0.01'));

        if (!$divisor->isPositive()) {
            return $this;
        }

        return new self($this->value->dividedBy($divisor, self::SCALE, RoundingMode::HalfUp));
    }

    /** @see self the class comment, which is where the rule lives */
    public function rounded(): self
    {
        return new self($this->value->toScale(self::SCALE, RoundingMode::HalfUp));
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    /**
     * More than nothing. Not "not zero": a line invoiced past its quantity has a
     * negative remainder, and there is no more of it left than there is of one
     * invoiced exactly.
     */
    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    /**
     * The value as a record holds it: a decimal string at the currency's scale,
     * so "19.9" and "19.90" are one stored value rather than two.
     */
    public function __toString(): string
    {
        return (string) $this->rounded()->value;
    }
}

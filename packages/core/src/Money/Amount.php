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
     * The value as a record holds it: a decimal string at the currency's scale,
     * so "19.9" and "19.90" are one stored value rather than two.
     */
    public function __toString(): string
    {
        return (string) $this->rounded()->value;
    }
}

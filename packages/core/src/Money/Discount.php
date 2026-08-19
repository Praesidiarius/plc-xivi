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

/**
 * What something outside this document takes off it (XIV-104).
 *
 * The answer a {@see DocumentDiscounts} gives, and deliberately not a number of
 * lines: **how much comes off is a policy and where it lands is arithmetic.** A
 * voucher knows it is worth ten francs or a tenth of the order; it does not know
 * that this order sells at two VAT rates, that a discount outside the VAT
 * grouping would leave the tax computed on undiscounted nets, or which of the
 * two rates the leftover rappen belongs to. Those are questions about money and
 * they are answered once, in {@see DerivesTotals}, beside the rounding rule §5.9
 * put there.
 *
 * So this carries two things and no rows of its own:
 *
 * - **`off`** — an amount to take off the priced lines, which the deriver splits
 *   across the rates on the document pro rata and emits as one discount line per
 *   rate. Null when there is nothing to take off, which is a free-article voucher.
 * - **`lines`** — lines to put on the document as they stand, at the price they
 *   were given ({@see DiscountLine}). Nothing is apportioned and nothing is
 *   split; a free article is one line at a quantity and a price of nothing.
 *
 * **{@see self::none()} is not the same as no discount at all**, and the
 * difference is the whole reason this is a nullable return rather than an empty
 * one. A source that returns `null` is saying *this document is not mine* — an
 * invoice with no voucher field, a module that has never heard of any of this —
 * and the deriver then leaves every row on it alone, including a discount line
 * copied down from the order it was made from (§5.12). A source that returns
 * `none()` is saying *it is mine and it is worth nothing today*, which is what a
 * voucher removed from a draft looks like, and the deriver takes the generated
 * lines away. Collapsing the two would mean either that removing a voucher left
 * its discount on the order for ever, or that seeding an invoice quietly dropped
 * the discount the customer was given.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Discount
{
    /** @param list<DiscountLine> $lines */
    public function __construct(
        public ?Amount $off = null,
        /**
         * What a generated discount line says it is.
         *
         * A string rather than a translation key, because it is **written into
         * the customer's own record** as the line's description and read from
         * there for ever after — by the document that prints it, by the invoice
         * seeded from it, and by whoever reads the order two years later. A key
         * resolved at save time would store one language's sentence and call it
         * data; the voucher's own code is the same word in every language and is
         * what the customer holding it recognises.
         */
        public string $label = '',
        public array $lines = [],
    ) {
    }

    /** Mine, and worth nothing here — take the generated lines away. */
    public static function none(): self
    {
        return new self();
    }
}

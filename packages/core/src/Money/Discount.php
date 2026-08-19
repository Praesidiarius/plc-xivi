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
 * What something outside this document takes off it (XIV-104, XIV-122).
 *
 * The answer a {@see DocumentDiscounts} gives, and deliberately not a set of
 * finished rows: **how much comes off is a policy and where it lands is
 * arithmetic.** A voucher knows it is worth ten francs, or a tenth, or a tenth of
 * one line; it does not know that this order sells at two VAT rates, that a
 * discount outside the VAT grouping would leave the tax computed on undiscounted
 * nets, or which of the two rates the leftover rappen belongs to. Those are
 * questions about money and they are answered once, in {@see DerivesTotals},
 * beside the rounding rule §5.9 put there.
 *
 * ### Two ways money comes off, because there are two ways a voucher is applied
 *
 * [XIV-122] settled that a voucher has a **mode**, and the mode decides both
 * where it may be applied and what it does. This carries one field per mode and
 * they are answers to different questions rather than two spellings of one:
 *
 * - **`off`** — an amount off the document as a whole, which the deriver splits
 *   across the rates present pro rata and emits as **one discount line per rate**
 *   (§5.24). There is no line for it to belong to, so it gets one of its own.
 *   Null when nothing comes off the document.
 * - **`perLine`** — how much comes off each individual line, keyed by that line's
 *   index in {@see DiscountableLine}. **No line is added**: the line that is
 *   discounted is a line that already exists, so reducing it is the natural
 *   reading, and the reduction is written into the line's own discount column
 *   where the recipient can subtract it by hand. Empty when nothing comes off any
 *   line.
 *
 * The two are not in tension, which is worth writing down because [XIV-104]'s
 * *"a discount is its own line"* was read as being at odds with a line-level
 * discount. It is not: that rule governs the mode this class calls `off`, where
 * there is no line for the money to belong to. Both may be present on one
 * document — a negotiated tenth off one line, and a `GIVE-10` off the rest — and
 * the deriver applies the per-line reductions first, so an order voucher is worth
 * a share of what the document *actually* charges.
 *
 * **{@see self::none()} is not the same as no discount at all**, and the
 * difference is the whole reason this is a nullable return rather than an empty
 * one. A source that returns `null` is saying *this document is not mine* — an
 * invoice with no voucher field, a module that has never heard of any of this —
 * and the deriver then leaves every row on it alone, including a discount line
 * and a reduced line copied down from the order it was made from (§5.12). A
 * source that returns `none()` is saying *it is mine and it is worth nothing
 * today*, which is what a voucher removed from a draft looks like, and the
 * deriver then takes the generated lines away and clears the reductions.
 * Collapsing the two would mean either that removing a voucher left its discount
 * on the order for ever, or that seeding an invoice quietly dropped the discount
 * the customer was given.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Discount
{
    /** @param array<int, Amount> $perLine */
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
         *
         * Only the `off` half needs one. A per-line reduction says which voucher
         * did it by *being on the line that names the voucher*, so there is
         * nothing left for a label to add.
         */
        public string $label = '',
        public array $perLine = [],
    ) {
    }

    /** Mine, and worth nothing here — take the generated lines and reductions away. */
    public static function none(): self
    {
        return new self();
    }
}

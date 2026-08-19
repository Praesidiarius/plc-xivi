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

namespace Xivi\Voucher\Discount;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Money\Amount;
use Xivi\Core\Money\Discount;
use Xivi\Core\Money\DiscountLine;
use Xivi\Core\Money\DocumentDiscounts;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordTitle;
use Xivi\Voucher\VoucherModule;

/**
 * What a voucher is worth on the document that names it (XIV-104).
 *
 * The seam [XIV-103] said was one method call, and this is that method. It is
 * asked while a document's totals are being worked out
 * ({@see \Xivi\Core\Money\DerivesTotals}) and it answers in the only currency
 * the engine will take: **how much comes off, and which lines to add**. Where
 * the money lands — which VAT rate carries which share of it, which line absorbs
 * the leftover rappen, whether a discount is capped by the order it is used on —
 * is not decided here, because it is arithmetic about a document and there is one
 * place for that.
 *
 * ### The three kinds, and why they are three lines of code
 *
 * A voucher is money off, a percentage off, or a free article (§5.19). The
 * product decision this ticket was given is that **every one of them is a line**,
 * and once that is settled the three collapse:
 *
 * - **Absolute** is the amount, and there is nothing else to work out. A `-10.00`
 *   line is the whole feature.
 * - **Relative** is the same thing with the amount computed first: a tenth of
 *   what the lines came to. It is *not* a percentage carried down to the line, so
 *   nothing downstream has to know it was ever a percentage.
 * - **Free article** is a line at a quantity and a price of nothing, which needs
 *   no subtraction at all — the customer receives something and pays zero for it.
 *
 * ### What it deliberately does not do
 *
 * **It does not check whether the voucher is valid**, and that is the decision
 * rather than an omission. Validity is checked at the moment a use is *taken*
 * ({@see \Xivi\Voucher\Redemption\RedeemsVouchers}), which is once, when the
 * document first names the voucher. Asking again on every save would mean an
 * order edited the day after the promotion ended silently losing the discount it
 * was agreed with — which is the failure §5.9 spends its length on, arriving by
 * the back door.
 *
 * **It does not redeem.** This runs on every keystroke of a form somebody is
 * still typing into (XIV-32), so a use taken here would be a use per keystroke.
 *
 * **It reads the voucher on every save rather than copying its terms onto the
 * document.** A voucher edited afterwards therefore changes what an order that is
 * still open says the next time somebody saves it, exactly as editing a line's
 * quantity changes what that line comes to. That is what a derived value *is*
 * (§5.9), and what stops it reaching a document somebody has been given is §5.8
 * rather than this class: an order that has been delivered or cancelled is
 * **locked**, cannot be saved at all, and is therefore never derived again.
 *
 * Which is worth stating exactly rather than approximately, because the window is
 * wider than it sounds: this module locks `delivered` and `cancelled`, not
 * `confirmed`, so a confirmed order re-saved after somebody edited the voucher
 * does restate its discount. That is the same window every other derived figure
 * on it already has — the totals, the subtotals, the VAT table — and narrowing it
 * for one of them alone would be a rule about vouchers pretending to be a rule
 * about documents.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class VoucherDiscounts implements DocumentDiscounts
{
    public function __construct(private VoucherReference $vouchers)
    {
    }

    public function on(ModuleDefinition $module, array $fields, Amount $lineSum): ?Discount
    {
        $field = $this->vouchers->fieldOn($module);

        if ($field === null) {
            // Not ours. An invoice, a contact, an order in a tenant that never
            // bought vouchers — and, importantly, an invoice carrying discount
            // lines copied down from the order it was made from (§5.12), which
            // stay exactly as they were copied. See `Discount` for why this is a
            // different answer from a discount worth nothing.
            return null;
        }

        $id = $this->vouchers->idIn($module, $fields);

        if ($id === null) {
            // Ours, and empty: whoever was holding a voucher has taken it off
            // again. The generated lines go with it.
            return Discount::none();
        }

        $voucher = $this->vouchers->record($id);

        if ($voucher === null) {
            // Named, and unreadable — the record was deleted or the module was
            // uninstalled. Nothing here can tell which, and neither may be read
            // as "no discount": the lines that are on the document stay on it,
            // and a save that is trying to *take* a use of it is refused by the
            // subscriber with a sentence saying so.
            return null;
        }

        return new Discount(
            off: $this->offOf($voucher, $lineSum),
            // **The voucher's own code**, which is its title (§5.19), written
            // into the line's description and stored there like any other line's
            // text. It is the same word in every language, it is what the person
            // holding the voucher recognises, and it is what makes the line
            // legible on a document two years later when the voucher itself has
            // been deleted.
            label: $this->titleOf($voucher),
            lines: $this->linesOf($voucher),
        );
    }

    /**
     * How much comes off the priced lines, or nothing for a voucher that takes
     * nothing off.
     *
     * A percentage is resolved **against what the lines came to** and rounded
     * once, here, so that everything downstream is dealing in money. Which is
     * also what makes a percentage and an amount the same feature by the time
     * they reach the document: nothing below this line knows the difference.
     */
    private function offOf(Record $voucher, Amount $lineSum): ?Amount
    {
        return match ($voucher->get(VoucherModule::KIND)) {
            VoucherModule::ABSOLUTE => Amount::of($voucher->get(VoucherModule::AMOUNT)),
            VoucherModule::RELATIVE => self::percentOf($lineSum, Amount::of($voucher->get(VoucherModule::PERCENTAGE))),
            default => null,
        };
    }

    /** Null in, null out: a relative voucher with no percentage on it is worth nothing. */
    private static function percentOf(Amount $lineSum, ?Amount $percentage): ?Amount
    {
        return $percentage === null ? null : $lineSum->percent($percentage)->rounded();
    }

    /**
     * The lines a voucher hands over as they stand, which today is the free
     * article and nothing else.
     *
     * **At a price of nothing rather than at the article's price with a matching
     * subtraction**, which was the alternative and is worse in the one place it
     * matters: two lines that have to be read together to see that they cancel,
     * where one line says the whole thing. The quantity is the voucher's, because
     * "two of them free" is a promotion somebody runs.
     *
     * The article is named rather than linked. A generated line pointing into the
     * article module would need this package to know what an order calls its
     * article column, which is precisely the dependency §3 forbids — and the
     * *name* is the thing a reader of the document needs. It comes from
     * {@see RecordTitle}, so it is whatever that customer calls their articles by,
     * and it falls back to the voucher's own code when the article cannot be read
     * at all.
     *
     * @return list<DiscountLine>
     */
    private function linesOf(Record $voucher): array
    {
        if ($voucher->get(VoucherModule::KIND) !== VoucherModule::FREE_ARTICLE) {
            return [];
        }

        $quantity = Amount::of($voucher->get(VoucherModule::QUANTITY));

        if ($quantity === null || !$quantity->isPositive()) {
            return [];
        }

        return [new DiscountLine($this->articleIn($voucher), $quantity, Amount::zero())];
    }

    /** What the free article is called, or the voucher's own name if it cannot be read. */
    private function articleIn(Record $voucher): string
    {
        $id = $voucher->get(VoucherModule::ARTICLE);
        $article = is_numeric($id) ? $this->vouchers->articleNamed((int) $id) : null;

        return $article ?? $this->titleOf($voucher);
    }

    private function titleOf(Record $voucher): string
    {
        $module = $this->vouchers->module();

        return $module === null ? '' : RecordTitle::of($module, $voucher);
    }
}

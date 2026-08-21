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

use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Money\Amount;
use Xivi\Core\Money\Discount;
use Xivi\Core\Money\DiscountableLine;
use Xivi\Core\Money\DocumentDiscounts;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordTitle;
use Xivi\Voucher\VoucherModule;

/**
 * What a voucher is worth on the document that names it (XIV-104, XIV-122).
 *
 * The seam [XIV-103] said was one method call, and this is that method. It is
 * asked while a document's totals are being worked out
 * ({@see \Xivi\Core\Money\DerivesTotals}) and it answers in the only currency
 * the engine will take: **how much comes off the document, and how much comes off
 * each line**. Where the money lands — which VAT rate carries which share of it,
 * which line absorbs the leftover rappen, whether a discount is capped by what it
 * is used on — is not decided here, because it is arithmetic about a document and
 * there is one place for that.
 *
 * ### Two modes, and the mode decides where a voucher may be applied
 *
 * [XIV-122] settled it, and the whole of the shape follows from one table:
 *
 * | mode  | named on   | what it does          |
 * | ----- | ---------- | --------------------- |
 * | order | the header | adds its own line(s)  |
 * | line  | one line   | reduces that line     |
 *
 * So this class reads two places instead of one. The document's own voucher field
 * gives the order-mode answer, which is [XIV-104]'s `off` and is unchanged. Each
 * line's voucher field gives that line's reduction. Both are the same lookup —
 * "which field on this shape points at vouchers" — asked of two shapes, which is
 * the whole reason {@see VoucherReference} takes a `ShapeDefinition`.
 *
 * **A voucher in the wrong place is worth nothing here and refused at the write.**
 * A line voucher named on the header, or an order voucher named on a line, gets no
 * money off — and the save that tried it is refused by
 * {@see \Xivi\Voucher\Redemption\RedeemsVouchers} with a sentence saying which way
 * round it goes. Both halves are needed and neither would do alone: a refusal
 * cannot happen in a deriver ([XIV-104] is explicit about that, and this method
 * runs on every keystroke), and silently discounting nothing would be a page
 * showing a figure nobody can explain. What this method must not do is *guess* —
 * an order voucher dropped on a line is not "probably meant for the order".
 *
 * ### The restriction is a restriction, not a target
 *
 * A line voucher may name an article, and then it may only go on a line carrying
 * that article. Naming none lets it go on **any** line, custom lines included —
 * which is the case the whole redesign exists for, because a custom line has no
 * article and a negotiated discount is exactly what lands on one.
 *
 * A voucher applied to a line that breaks its restriction is worth nothing here
 * and refused at the write, for the same reason and by the same class.
 *
 * ### The order voucher comes off what is left
 *
 * When both are on one document, the per-line reductions happen first and a
 * percentage off the whole order is a percentage of what the lines come to
 * **after** them. That is the only reading that is not arbitrary: "a tenth off
 * this order" is a tenth of what the order costs, and what it costs already
 * reflects the tenth somebody negotiated off one line. It also keeps the two from
 * being able to add up to more than the document charges, which is a property the
 * cap in the deriver then never has to rescue.
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

    /**
     * `$document` is not read here, and the absence is worth a sentence rather
     * than a shrug ([XIV-147]). A voucher's worth is decided entirely from the
     * record in front of it — the code it names, the lines it holds — and never
     * from the module's other records, which is exactly why redemption had to be
     * a subscriber rather than something this method could do. The parameter is on
     * the seam for {@see \Xivi\Core\Seed\SeededDiscounts}, which answers about a
     * document made from another one and therefore has to leave itself out of the
     * documents it counts.
     */
    public function on(ModuleDefinition $module, ?int $document, array $fields, Amount $lineSum, array $lines): ?Discount
    {
        $header = $this->vouchers->fieldOn($module);
        $rows = $this->linesOf($module);

        if ($header === null && $rows === null) {
            // Not ours. An invoice, a contact, an order in a tenant that never
            // bought vouchers — and, importantly, an invoice carrying discount
            // lines and reduced lines copied down from the order it was made from
            // (§5.12), which stay exactly as they were copied. See `Discount` for
            // why this is a different answer from a discount worth nothing.
            return null;
        }

        // **The lines first**, because the order voucher below is worth a share of
        // what is left after them.
        $perLine = $rows === null ? [] : $this->perLine($rows, $lines);

        foreach ($perLine as $off) {
            $lineSum = $lineSum->minus($off);
        }

        $voucher = $header === null ? null : $this->voucherIn($fields[$header] ?? null);

        if ($voucher === false) {
            // Named, and unreadable — the record was deleted or the module was
            // uninstalled. Nothing here can tell which, and neither may be read
            // as "no discount": the lines that are on the document stay on it,
            // and a save that is trying to *take* a use of it is refused by the
            // subscriber with a sentence saying so.
            return null;
        }

        if ($voucher === null) {
            // Ours, and no order voucher on it: either nobody put one there, or
            // whoever was holding one has taken it off again. Any line vouchers
            // still stand — they are named somewhere else entirely.
            return new Discount(perLine: $perLine);
        }

        return new Discount(
            off: self::offDocument($voucher, $lineSum),
            // **The voucher's own code**, which is its title (§5.19), written
            // into the line's description and stored there like any other line's
            // text. It is the same word in every language, it is what the person
            // holding the voucher recognises, and it is what makes the line
            // legible on a document two years later when the voucher itself has
            // been deleted.
            //
            // Only the order mode needs one. A line voucher says which voucher it
            // was by being named on the line it reduced, in a column somebody can
            // read, so a label would be the same fact printed twice.
            label: $this->titleOf($voucher),
            perLine: $perLine,
        );
    }

    /**
     * What comes off each line, keyed the way the deriver asked for it.
     *
     * A line contributes an entry only when it names a voucher that is readable,
     * is in the line mode, and passes its own restriction. Everything else — an
     * empty field, a deleted voucher, an order voucher dropped on a line, a
     * restriction the line does not meet — contributes nothing, and the save is
     * refused where a refusal is possible.
     *
     * @param list<DiscountableLine> $lines
     *
     * @return array<int, Amount>
     */
    private function perLine(CollectionDefinition $rows, array $lines): array
    {
        $off = [];

        foreach ($lines as $line) {
            $voucher = $this->voucherIn($this->vouchers->idIn($rows, $line->data));

            if (!$voucher instanceof Record || !VoucherModule::isLineKind($voucher->get(VoucherModule::KIND))) {
                continue;
            }

            $restriction = $this->vouchers->restrictionOf($voucher);

            if ($restriction !== null && $restriction !== $this->vouchers->articleIn($rows, $line->data)) {
                continue;
            }

            $amount = self::worthOf($voucher, $line->amount);

            if ($amount !== null && $amount->isPositive()) {
                $off[$line->index] = $amount;
            }
        }

        return $off;
    }

    /**
     * How much an order voucher takes off the priced lines, or nothing for one
     * that takes nothing off.
     *
     * A percentage is resolved **against what the lines came to** and rounded
     * once, here, so that everything downstream is dealing in money. Which is
     * also what makes a percentage and an amount the same feature by the time
     * they reach the document: nothing below this line knows the difference.
     *
     * A voucher that is not in the order mode is worth nothing here — it is in the
     * wrong place, and the save naming it there is refused rather than quietly
     * reinterpreted.
     */
    private static function offDocument(Record $voucher, Amount $lineSum): ?Amount
    {
        return VoucherModule::isOrderKind($voucher->get(VoucherModule::KIND))
            ? self::worthOf($voucher, $lineSum)
            : null;
    }

    /**
     * What a voucher is worth against a given figure, whatever kind it is.
     *
     * **One method for both modes and both kinds**, which is worth pausing on:
     * once a mode has decided *what* the figure is — the whole document in one
     * case, one line in the other — the arithmetic is identical, and an amount and
     * a percentage differ only in whether the figure is used. That is the same
     * collapse [XIV-104] found between its three kinds, holding one axis further
     * out.
     */
    private static function worthOf(Record $voucher, Amount $against): ?Amount
    {
        $kind = $voucher->get(VoucherModule::KIND);

        if ($kind === VoucherModule::ORDER_AMOUNT || $kind === VoucherModule::LINE_AMOUNT) {
            return Amount::of($voucher->get(VoucherModule::AMOUNT));
        }

        $percentage = Amount::of($voucher->get(VoucherModule::PERCENTAGE));

        // Null in, null out: a relative voucher with no percentage on it is worth
        // nothing.
        return $percentage === null ? null : $against->percent($percentage)->rounded();
    }

    /**
     * The voucher an id names: the record, `null` for no id at all, and `false`
     * for an id nothing can be read behind.
     *
     * Three states rather than two because the callers do three different things
     * about them, and collapsing the last two would be the mistake §5.9 keeps
     * warning about: "there is no voucher here" and "the voucher cannot be read"
     * must not both mean *take the discount off*.
     */
    private function voucherIn(mixed $value): Record|false|null
    {
        $id = VoucherReference::idOf($value);

        if ($id === null) {
            return null;
        }

        return $this->vouchers->record($id) ?? false;
    }

    /**
     * Which of this module's collections has lines a voucher can go on.
     *
     * **Found rather than declared**, exactly as the header field is and for the
     * same reason: a voucher package cannot know that an order calls its lines
     * `lines`, and does not have to. A module that points a line at vouchers has
     * said so in the customer's own definitions, and a list here would be a second
     * answer that could disagree with them (§3, [XIV-13]).
     *
     * The first such collection wins, which is the same tie-break
     * {@see VoucherReference::fieldOn()} makes one level down and is stable for
     * the same reason: collections come back in the order the definitions hold
     * them.
     */
    private function linesOf(ModuleDefinition $module): ?CollectionDefinition
    {
        foreach ($module->getCollections() as $collection) {
            if ($this->vouchers->fieldOn($collection) !== null) {
                return $collection;
            }
        }

        return null;
    }

    private function titleOf(Record $voucher): string
    {
        $module = $this->vouchers->module();

        return $module === null ? '' : RecordTitle::of($module, $voucher);
    }
}

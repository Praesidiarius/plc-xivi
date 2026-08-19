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

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Derivation;
use Xivi\Core\Record\SafeToPreview;
use Xivi\Core\Record\ValueDeriver;

/**
 * What a document with lines comes to, worked out while it is being saved
 * (XIV-16, generalised by XIV-19).
 *
 * This was the order module's own class until the invoice module wanted the same
 * arithmetic. Two modules needing identical sums is the engine's problem rather
 * than theirs — §3 forbids one package importing the other, and the alternative
 * was the same hundred lines twice, drifting apart the first time somebody fixed
 * a rounding bug in one of them.
 *
 * **A line contributes if it has a price, not if it is the right kind.** Every
 * line goes through the same loop; a comment line falls out of it because it has
 * no quantity and no unit price, which is a fact about the line rather than a
 * branch about its kind. So a fifth kind of line needs nothing here.
 *
 * The one thing kind *is* asked about is where a subtotal sits, because a
 * subtotal is defined by being one: it restates the priced lines since the
 * previous subtotal and then starts the count again. It adds **nothing** to the
 * document's own totals — it is a restatement of lines already counted, and
 * double-counting it is the single arithmetic mistake this file can make.
 *
 * **Rounding lives in {@see Amount}**, which is where the rule is written down
 * once for every module that will ever declare one of these.
 *
 * ## A price may already have the VAT in it (XIV-116)
 *
 * The document says which (`LineTotals::$vatMode`, {@see VatMode}), and this is
 * still the only class that computes any of it. **Inclusive is not a second
 * deriver**: it is the same loop with the same rounding rule, run in the other
 * direction. Two derivers would be two places to fix a rounding bug, and §5.9
 * spent a paragraph on why there is exactly one.
 *
 * What is identical in both modes, and worth listing because it is most of the
 * file: a line total is quantity times price rounded to two places; a comment
 * line contributes nothing; a subtotal restates the block above it; the VAT table
 * has one row per rate; a rate of nothing gets no row. The `unit_price` column
 * and the `line_total` column carry gross figures in inclusive mode and net ones
 * in exclusive mode, and everything above is true of both.
 *
 * What differs is three lines at the end:
 *
 * - **Exclusive.** The lines sum to the **net** total. Tax per rate is
 *   `net × rate`, rounded once per rate. Gross is net plus tax.
 * - **Inclusive.** The lines sum to the **gross** total. Net per rate is
 *   `gross ÷ (1 + rate)`, rounded once per rate; tax per rate is **the
 *   remainder**, `gross − net`. Net is gross minus the tax.
 *
 * **The gross the customer typed is the gross that prints**, and the second
 * bullet is entirely in service of that. Deriving a net and then re-deriving a
 * gross from it is the mistake this ticket exists to remove: 19.95 at 8.1% has a
 * net of 18.46, and 18.46 plus 8.1% of itself is 19.96 — a rappen above the price
 * on the shelf, on the customer's own document, with nobody able to explain why.
 * Taking the tax as what is left of the gross makes that arithmetically
 * impossible rather than usually fine.
 *
 * **There is no remainder to place across rates.** VAT is grouped per rate before
 * it is rounded (§5.9), so each rate's gross is split into a net and a tax that
 * add back to exactly that rate's gross, and the document's totals are sums of
 * exact splits. A document at 8.1% and 2.6% therefore needs no rule about which
 * rate absorbs a leftover rappen, because neither of them ever produces one. The
 * remainder that does exist is *within* a rate, and it lands on the tax — the
 * derived figure — never on the gross, which is the figure somebody typed.
 * [XIV-104] is deciding the same question for discounts and this is the answer to
 * agree with: **the typed figure is exact and the derived figure absorbs what is
 * left over.**
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DerivesTotals implements ValueDeriver, SafeToPreview
{
    public function __construct(private ModuleRegistry $modules)
    {
    }

    public function supports(ModuleDefinition $module): bool
    {
        return $this->totalsOf($module) !== null;
    }

    public function derive(ModuleDefinition $module, Derivation $derivation): void
    {
        $totals = $this->totalsOf($module);

        // A save that says nothing about the lines is not a save that emptied
        // them — a lifecycle transition writes the header alone. Recomputing
        // from rows nobody sent would zero the totals of every order anybody
        // confirms.
        if ($totals === null || !\array_key_exists($totals->collection, $derivation->rows)) {
            return;
        }

        // Which field on a row says what kind it is (§5.5). Looked up once: a
        // subtotal is the only thing here that is decided by kind rather than by
        // whether there is a price.
        $kindField = $module->getCollection($totals->collection)?->getVariantField();

        // Whether the prices on this document already have the VAT in them
        // (XIV-116). Read off the record's own field rather than out of a
        // setting, because it is a fact about *this* document: §5.16 makes the
        // same argument about a due date, and §5.9 made it first about the
        // totals themselves. An installation that changes what it prices in must
        // not restate a document somebody has already been sent.
        //
        // A module with no such field, a customer who has not taken it from the
        // upgrade offer, and a record written before this existed all arrive here
        // as `Excluded` — see VatMode::of(), which is the one place that mapping
        // is written.
        $mode = VatMode::of($totals->vatMode === null ? null : ($derivation->fields[$totals->vatMode] ?? null));

        $lines = [];
        // What the lines add up to. In exclusive mode that is the net total; in
        // inclusive mode it is the gross one. Deliberately not named for either:
        // it is the sum of the line-total column as printed, which is the one
        // sentence true in both modes.
        $lineSum = Amount::zero();
        /** @var array<string, Amount> $byRate keyed by the rate as it is stored */
        $byRate = [];
        // The priced lines since the last subtotal, which is what the next one
        // will say.
        $block = Amount::zero();

        // `['data' => …] + $line` below rather than a fresh pair, so whatever
        // else the caller put on a row survives being derived. The live form
        // (XIV-32) needs that: it hands rows in carrying the form index they
        // came from, and matching the figures back by *position* instead would
        // be an assumption that this loop emits one row per row forever —
        // silent when it broke, and wrong on a page showing somebody money.
        foreach ($derivation->rowsOf($totals->collection) as $line) {
            $data = $line['data'];
            $amount = self::amountOf($data, $totals);

            if ($amount === null) {
                // Nothing to charge for. A comment line, and also a subtotal —
                // which then says what the block above it came to and starts the
                // next one.
                $isSubtotal = $kindField !== null
                    && $totals->subtotalKind !== null
                    && ($data[$kindField] ?? null) === $totals->subtotalKind;

                $data[$totals->lineTotal] = $isSubtotal ? (string) $block : null;

                if ($isSubtotal) {
                    $block = Amount::zero();
                }

                $lines[] = ['data' => $data] + $line;

                continue;
            }

            $data[$totals->lineTotal] = (string) $amount;
            $lines[] = ['data' => $data] + $line;

            $lineSum = $lineSum->plus($amount);
            $block = $block->plus($amount);

            $rate = ($totals->taxRate === null ? null : Amount::of($data[$totals->taxRate] ?? null)) ?? Amount::zero();
            $key = (string) $rate;
            $byRate[$key] = ($byRate[$key] ?? Amount::zero())->plus($amount);
        }

        $derivation->setRows($totals->collection, $lines);

        $taxes = self::taxesOf($byRate, $totals, $mode);

        if ($totals->taxes !== null) {
            $derivation->setRows($totals->taxes, $taxes);
        }

        $tax = Amount::zero();

        foreach ($taxes as $row) {
            $tax = $tax->plus(Amount::of($row['data'][$totals->taxAmount]) ?? Amount::zero());
        }

        // The one place the two modes part company, and it is deliberately this
        // small. Whichever of the two totals the lines already gave us is written
        // through untouched; the other is the one that moves by the tax.
        //
        // Inclusive mode subtracts rather than dividing again, and that is the
        // guarantee rather than an optimisation: the gross is exactly the sum of
        // the line-total column, so the figure the recipient can add up by hand
        // is the figure printed under it, and the net is whatever is left once
        // the per-rate tax has come out. Re-deriving the net from the rates a
        // second time here would reintroduce the rappen the per-rate split has
        // just spent its rounding budget avoiding.
        if ($mode === VatMode::Included) {
            $derivation->fields[$totals->grossTotal] = (string) $lineSum;
            $derivation->fields[$totals->netTotal] = (string) $lineSum->minus($tax);
        } else {
            $derivation->fields[$totals->netTotal] = (string) $lineSum;
            $derivation->fields[$totals->grossTotal] = (string) $lineSum->plus($tax);
        }

        if ($totals->taxTotal !== null) {
            $derivation->fields[$totals->taxTotal] = (string) $tax;
        }
    }

    /**
     * What a line charges, or null when it charges nothing.
     *
     * Rounded here, so that the lines printed on a document add up to the total
     * printed under them. Rounding once at the end instead would be a rappen
     * more accurate and visibly wrong to anybody checking the column.
     *
     * @param array<string, mixed> $data
     */
    private static function amountOf(array $data, LineTotals $totals): ?Amount
    {
        $quantity = Amount::of($data[$totals->quantity] ?? null);
        $price = Amount::of($data[$totals->unitPrice] ?? null);

        return $quantity === null || $price === null ? null : $quantity->times($price)->rounded();
    }

    /**
     * The VAT table: one row per rate, over the net that was sold at it.
     *
     * **Per rate, not per line.** A hundred lines each losing half a rappen to
     * rounding is fifty rappen of tax nobody owes; grouping first is both more
     * accurate and what the tax authority's own examples do.
     *
     * A rate of nothing gets no row. A customer who is not registered for VAT
     * has typed no rates anywhere and should see no VAT table at all, rather
     * than one line reading "0% of 1200.00 = 0.00". Zero-rated exports are a
     * real thing and want a *named* rate with a reason on the document, which is
     * a field this engine has not got yet — when it does, it will be a rate.
     *
     * Sorted by rate, so the table reads the same way on every document and a
     * row keeps its place between saves.
     *
     * **The table itself reads identically in both modes** (XIV-116), which is
     * worth saying out loud because it is not obvious and it is the property that
     * makes a gross-priced document intelligible to a tax inspector. A row is
     * always "this much net was sold at this rate and this much tax is owed on
     * it"; what the mode changes is which of the two the *lines* gave us and
     * which one this has to work out. Exclusive mode is handed the net and
     * multiplies; inclusive mode is handed the gross, divides for the net, and
     * takes the tax as what is left. Either way `net + amount` comes to exactly
     * what that rate's lines added up to, which is what makes the table sum to
     * the totals beside it in both directions.
     *
     * `$byRate` therefore carries **whatever the line-total column carries** —
     * net in one mode, gross in the other — which is why the parameter is not
     * named for either.
     *
     * @param array<string, Amount> $byRate
     *
     * @return list<array{id: int|null, data: array<string, mixed>}>
     */
    private static function taxesOf(array $byRate, LineTotals $totals, VatMode $mode): array
    {
        uksort($byRate, static fn (string $a, string $b): int => (float) $a <=> (float) $b);

        $rows = [];

        foreach ($byRate as $rate => $sold) {
            $amount = Amount::of($rate) ?? Amount::zero();

            if ($amount->isZero()) {
                continue;
            }

            if ($mode === VatMode::Included) {
                // The gross was typed, so it is fixed; the net is the rounded
                // quotient and the tax is the remainder. Subtracting rather than
                // computing the tax on the rounded net is the whole ticket: at
                // 19.95 and 8.1% the two answers differ, and only this one adds
                // back to 19.95.
                $net = $sold->withoutPercent($amount);
                $tax = $sold->minus($net);
            } else {
                $net = $sold;
                $tax = $net->percent($amount)->rounded();
            }

            $rows[] = ['id' => null, 'data' => [
                $totals->rate => (string) $rate,
                $totals->taxableNet => (string) $net,
                $totals->taxAmount => (string) $tax,
            ]];
        }

        return $rows;
    }

    private function totalsOf(ModuleDefinition $module): ?LineTotals
    {
        // By key, which is the customer's installed copy of this module. The
        // label may be anything they renamed it to (XIV-8) and the fields may
        // have grown (§6.1); the key is what stays put.
        $key = $module->getKey();

        return $this->modules->has($key) ? $this->modules->get($key)->lineTotals : null;
    }
}

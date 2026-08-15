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

namespace Xivi\Order;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Money\Amount;
use Xivi\Core\Record\Derivation;
use Xivi\Core\Record\ValueDeriver;

/**
 * What an order comes to, worked out while it is being saved (XIV-16).
 *
 * The first {@see ValueDeriver}, and the reason that seam exists: totals have to
 * be *stored* to be filterable and to stay true after the article's price
 * changes, and something has to compute them before the row is written.
 *
 * **A line contributes if it has a price, not if it is the right kind.** Every
 * line goes through the same loop; a comment line falls out of it because it has
 * no quantity and no unit price, which is a fact about the line rather than a
 * branch about its kind. That is what "not a special case in the summing code"
 * means, and it is why adding a fifth kind of line later needs nothing here.
 *
 * The one thing kind *is* asked about is where a subtotal sits, because a
 * subtotal is defined by being one: it restates the priced lines since the
 * previous subtotal and then starts the count again. It adds **nothing** to the
 * order's own totals — it is a restatement of lines already counted, and
 * double-counting it is the single arithmetic mistake this file can make.
 *
 * **Rounding lives in {@see Amount}**, which is where the rule is written down
 * once for this module and for the invoices that will follow.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class OrderTotals implements ValueDeriver
{
    public function supports(ModuleDefinition $module): bool
    {
        // By key, which is the customer's installed copy of this module. The
        // label may be anything they renamed it to (XIV-8) and the fields may
        // have grown (§6.1); the key is what stays put.
        return $module->getKey() === OrderModule::KEY;
    }

    public function derive(ModuleDefinition $module, Derivation $derivation): void
    {
        // A save that says nothing about the lines is not a save that emptied
        // them — a lifecycle transition writes the header alone. Recomputing
        // from rows nobody sent would zero the totals of every order anybody
        // confirms.
        if (!\array_key_exists(OrderModule::LINES, $derivation->rows)) {
            return;
        }

        $lines = [];
        $net = Amount::zero();
        /** @var array<string, Amount> $byRate keyed by the rate as it is stored */
        $byRate = [];
        // The priced lines since the last subtotal, which is what the next one
        // will say.
        $block = Amount::zero();

        foreach ($derivation->rowsOf(OrderModule::LINES) as $line) {
            $data = $line['data'];
            $total = self::totalOf($data);

            if ($total === null) {
                // Nothing to charge for. A comment line, and also a subtotal —
                // which then says what the block above it came to and starts the
                // next one.
                $isSubtotal = ($data[OrderModule::KIND] ?? null) === OrderModule::SUBTOTAL_LINE;
                $data[OrderModule::LINE_TOTAL] = $isSubtotal ? (string) $block : null;

                if ($isSubtotal) {
                    $block = Amount::zero();
                }

                $lines[] = ['id' => $line['id'], 'data' => $data];

                continue;
            }

            $data[OrderModule::LINE_TOTAL] = (string) $total;
            $lines[] = ['id' => $line['id'], 'data' => $data];

            $net = $net->plus($total);
            $block = $block->plus($total);

            $rate = Amount::of($data[OrderModule::TAX_RATE] ?? null) ?? Amount::zero();
            $key = (string) $rate;
            $byRate[$key] = ($byRate[$key] ?? Amount::zero())->plus($total);
        }

        $derivation->setRows(OrderModule::LINES, $lines);

        $taxes = self::taxesOf($byRate);
        $derivation->setRows(OrderModule::TAXES, $taxes);

        $tax = Amount::zero();
        foreach ($taxes as $row) {
            $tax = $tax->plus(Amount::of($row['data'][OrderModule::TAX_AMOUNT]) ?? Amount::zero());
        }

        $derivation->fields[OrderModule::NET_TOTAL] = (string) $net;
        $derivation->fields[OrderModule::TAX_TOTAL] = (string) $tax;
        $derivation->fields[OrderModule::GROSS_TOTAL] = (string) $net->plus($tax);
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
    private static function totalOf(array $data): ?Amount
    {
        $quantity = Amount::of($data[OrderModule::QUANTITY] ?? null);
        $price = Amount::of($data[OrderModule::UNIT_PRICE] ?? null);

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
     * @param array<string, Amount> $byRate
     *
     * @return list<array{id: int|null, data: array<string, mixed>}>
     */
    private static function taxesOf(array $byRate): array
    {
        uksort($byRate, static fn (string $a, string $b): int => (float) $a <=> (float) $b);

        $rows = [];

        foreach ($byRate as $rate => $net) {
            $amount = Amount::of($rate) ?? Amount::zero();

            if ($amount->isZero()) {
                continue;
            }

            $rows[] = ['id' => null, 'data' => [
                OrderModule::RATE => $rate,
                OrderModule::TAXABLE_NET => (string) $net,
                OrderModule::TAX_AMOUNT => (string) $net->percent($amount)->rounded(),
            ]];
        }

        return $rows;
    }
}

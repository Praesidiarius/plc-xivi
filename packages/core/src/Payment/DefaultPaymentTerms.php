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

namespace Xivi\Core\Payment;

/**
 * How long this installation gives a customer to pay, asked for rather than
 * known (XIV-67).
 *
 * The answer lives in the tenant profile (§8.6), which is the application's and
 * not the engine's — core is handed a connection and never learns whose it is.
 * So core declares the question and the application answers it, the same seam
 * {@see \Xivi\Core\Money\InstanceCurrency} keeps for the currency and
 * `PdfConverter` for documents.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface DefaultPaymentTerms
{
    /**
     * Whole days from the issue date, or null when nobody has said.
     *
     * **Null is a real answer and not a missing one**, and it is the same call
     * `InstanceCurrency` makes about a currency: a term guessed for a customer is
     * wrong quietly, and it would surface as a deadline printed on a bill that
     * nobody in the company ever agreed to. A document with no due date is not
     * overdue (XIV-67), so an installation that has never been asked this
     * question chases nobody — which is the safe direction to be wrong in.
     */
    public function days(): ?int;
}

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
 * Whether this installation's prices already include VAT, asked for rather than
 * known (XIV-116).
 *
 * The answer lives in the tenant profile (§8.6), which is the application's and
 * not the engine's — core is handed a connection and never learns whose it is. So
 * core declares the question and the application answers it, the same seam
 * {@see InstanceCurrency} keeps for the currency and
 * {@see \Xivi\Core\Payment\DefaultPaymentTerms} for the payment terms. **Not a
 * fourth shape**: the same two-method-and-a-null shape, deliberately, so that
 * whoever reads one of these has read all of them.
 *
 * ### This is asked when a document is created, and never afterwards
 *
 * Which is the whole reason it is not simply read by {@see DerivesTotals}. A
 * shop's catalogue is priced one way, so a new order should start out reading it
 * that way and a person should not have to say so on every document. But a
 * *stored* document is a fact (§5.9): the day somebody changes this setting,
 * every order already in the system must go on meaning what it meant, and a
 * deriver consulting a live setting would silently reprice every draft in the
 * building the next time one was touched.
 *
 * So the setting seeds the field on a new record and the field is what the
 * arithmetic reads — exactly the relationship §5.16 gives a payment term and a
 * due date, and for exactly the same reason: what was agreed is a fact about that
 * document, and the rule it came from is not a second fact about it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface DefaultVatMode
{
    /**
     * What a document created now starts out as, or null when nobody has said.
     *
     * **Null is a real answer and not a missing one**, the same call
     * `InstanceCurrency` makes about a currency and `DefaultPaymentTerms` about a
     * term. It is not quite the same as answering `Excluded`, even though a
     * document that never gets a value reads as excluded either way: null means
     * the question has not been put to this customer, so nothing is written onto
     * the record and every installation that predates this feature carries on
     * producing documents shaped exactly as before.
     */
    public function mode(): ?VatMode;
}

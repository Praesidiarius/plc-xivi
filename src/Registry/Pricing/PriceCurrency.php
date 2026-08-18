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

namespace App\Registry\Pricing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Xivi\Core\Money\InstanceCurrency;

/**
 * The currency **this deployment's price list is in** (XIV-101).
 *
 * One fact for the whole installation: the company running Xivi sells in one
 * currency, and every figure on {@see ModulePrice} is a number in it.
 *
 * ## Why this is not {@see InstanceCurrency}, which is what it looks like
 *
 * That interface is *named* for the instance and is answered by
 * {@see \App\Tenant\Settings\ProfileCurrency}, which reads the **tenant
 * profile** (§8.6) — the currency a customer writes their own invoices in.
 * Reusing it here was the first thing tried and it fails twice, in opposite
 * directions, and neither failure is about naming:
 *
 * * **On a customer's request it would be the wrong answer.** A deployment that
 *   charges 49.00 CHF for the invoice module charges that to a customer whose
 *   profile says EUR as well. Rendering this deployment's price list against the
 *   *reader's* currency relabels francs as euros — the same digits, a different
 *   claim, and a claim nobody at either end agreed to.
 * * **On the operator's screen there is no answer at all.** A control-plane
 *   request resolves no tenant by construction (§8.9), so `ProfileCurrency`
 *   correctly returns null there, for ever. The one page on which somebody
 *   decides what a module costs would be the one page that could never say what
 *   it costs it *in*.
 *
 * So the two answer different questions and this is not a second currency
 * *model*: it is the same ISO 4217 code, the same {@see \Xivi\Core\Money\Amount},
 * the same two decimal places. What differs is whose fact it is. Deliberately
 * **not** implementing `InstanceCurrency`, so that this cannot be autowired into
 * a field type or a document by somebody who reads the interface name and stops
 * there — the failure above is silent, and a type is the only thing that makes
 * it loud.
 *
 * ## Why a deployment parameter, when the ticket forbade one for the price
 *
 * [XIV-101] is emphatic that a **price** must not live in `.env`: a price
 * somebody edits in an environment file is a price nobody can change without a
 * deploy, and being able to change it without one is the reason the ticket
 * exists. That argument is about the number, and it does not transfer to the
 * currency, because the two change at completely different rates and for
 * completely different reasons.
 *
 * A deployment picks its selling currency once, when it is installed. Changing
 * it later does not adjust a price — it invalidates **every** price on the list
 * at once, since 49.00 CHF and 49.00 EUR are not the same offer, so a currency
 * change is a re-pricing exercise with a human being in it rather than a field
 * somebody edits between two customers. Making that need a deploy is the correct
 * amount of friction, and it is the same shape §4.4 settled for
 * `app.control_plane_host`: a fact about *where and how this software is
 * installed* belongs to the deployment, is set in the environment, and is
 * therefore identical in both images without anything having to keep them in
 * step.
 *
 * The alternative weighed against it was a single editable row in the control
 * plane — one table, one row, one column, plus a screen to edit it and a
 * migration to create it, to hold a value that changes roughly never. It was
 * rejected on cost against benefit, and the note worth leaving is what would
 * change the answer: the day a deployment sells to two markets in two currencies,
 * this class is wrong and a per-price-list currency is right. That is a feature
 * with exchange-rate questions behind it, exactly as `CurrencyFieldType` says
 * about per-record currencies, and it is not this ticket.
 *
 * ## Null is a real answer
 *
 * §8.6 refuses to guess a currency for a customer, because a guessed one is
 * wrong quietly and surfaces on the first priced thing they print. The same holds
 * here and the default is therefore empty: a deployment that has not set
 * `PRICE_CURRENCY` gets bare numbers and a screen that says so, rather than
 * francs it never chose.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PriceCurrency
{
    public function __construct(
        #[Autowire('%app.price_currency%')]
        private string $configured,
    ) {
    }

    /**
     * The ISO 4217 code this deployment prices in, or null when nobody has said.
     *
     * Trimmed and upper-cased rather than taken as typed: `chf ` in an
     * environment file is somebody's answer to this question, and rejecting it
     * over whitespace would be a worse response than accepting it. Nothing
     * validates the code against a list — symfony/intl owns what exists and a
     * second copy of that list here would be one to keep in step.
     */
    public function code(): ?string
    {
        $code = strtoupper(trim($this->configured));

        return $code === '' ? null : $code;
    }

    /** Whether this deployment has said what it sells in. */
    public function isChosen(): bool
    {
        return $this->code() !== null;
    }
}

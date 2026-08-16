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
 * "This document is owed by a date, and here is where that date comes from"
 * (XIV-67).
 *
 * A declaration in the module's blueprint, exactly like {@see
 * \Xivi\Core\Money\LineTotals} and {@see \Xivi\Core\Mail\MailRecipient}: the
 * module says which of *its* fields hold what, and everything that knows how to
 * act on it — {@see DerivesDueDate} for writing the date, {@see Overdue} for
 * reading whether it has passed — lives in core, once, for every module that will
 * ever declare one of these.
 *
 *     new PaymentTerms(
 *         dueDate: 'due_date',
 *         from: 'issued_on',
 *         outstanding: 'sent',
 *         terms: 'payment_terms',
 *         through: 'contact',
 *     )
 *
 * ### A payment term is a number of days, and that is a decision
 *
 * `$terms` names a field holding **whole days from the issue date**, and nothing
 * else. The alternatives were considered and rejected on purpose:
 *
 * - **"2/10 net 30"** — a discount for paying early. That is two deadlines with
 *   two different amounts behind them, which the money model has no room for:
 *   `status` is binary (§5.9's totals are what was agreed, not what was paid),
 *   and a document that can be settled for less than its gross total is a change
 *   to that model rather than a change to a date.
 * - **"net 30, end of month"** — real, common, and a *second* arithmetic on top
 *   of the first rather than a different value. It is a rounding rule applied to
 *   the answer this already produces, so it can be added later as an option on
 *   the same field without restating anybody's terms.
 * - **A free-text term** — "on receipt", "before delivery". Unfilterable and
 *   uncomputable, which defeats the entire point: the question being answered is
 *   "which of these is late", and text cannot be compared to a calendar.
 *
 * Days also survive being read by a human on the screen where they are typed. A
 * customer who genuinely pays on receipt has a term of zero, which this handles
 * without a special case.
 *
 * ### One state, not two
 *
 * `$outstanding` is the lifecycle state in which the money is owed — `sent` for
 * an invoice. It says two things at once and deliberately so: the due date is
 * materialised **on the way into** that state, and the record is overdue while it
 * is **still in** it with the date behind us. Those are the same fact — the money
 * is outstanding — and writing them as two properties would be two things that
 * can disagree, which on a page telling somebody their customer is late is the
 * disagreement that matters.
 *
 * The *field* holding that state is not named here: the module already declares
 * it on its {@see \Xivi\Core\Lifecycle\Lifecycle}, and a second copy of it here
 * would be a second answer to a question that already has one.
 *
 * ### The terms may be one hop away
 *
 * `$through` is this module's `reference` field whose target carries the
 * customer's own terms (§7.6). **One hop, and a second is deliberately
 * impossible**, which is the same rule `MailRecipient` holds and for the same
 * reason: an invoice's `order.contact.payment_terms` is two joins whose cost
 * cannot be read off the declaration. The invoice already copies its contact down
 * from the order it was seeded from (XIV-19), which is what keeps one hop enough.
 *
 * Null — a module whose records carry their own terms — reads `$terms` off the
 * record itself. Nothing uses that yet; it costs one branch and its absence would
 * be an assumption that a term is always somebody else's.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PaymentTerms
{
    public function __construct(
        /** This module's field holding the materialised date. Derived; nobody types it. */
        public string $dueDate,
        /** The date the term counts from — an invoice's issue date. */
        public string $from,
        /** The lifecycle state in which the money is owed; see the class docblock. */
        public string $outstanding,
        /**
         * The field holding a whole number of days, on the record `$through`
         * names or — with no hop — on this one.
         */
        public string $terms,
        /** This module's `reference` field whose target carries the terms. */
        public ?string $through = null,
    ) {
    }
}

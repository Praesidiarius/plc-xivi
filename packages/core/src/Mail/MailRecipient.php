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

namespace Xivi\Core\Mail;

/**
 * "This is where a mail from one of my records goes" (XIV-39).
 *
 * The engine cannot work this out and should not try. Which field holds an email
 * address is a fact about *a module's shape*, and a module is a declaration — so
 * it declares this, exactly the way {@see \Xivi\Core\Seed\Seed} declares where a
 * record is made from and {@see \Xivi\Core\Money\LineTotals} declares which
 * fields are money. Guessing instead — the first field of type `email`, a field
 * literally called `email` — would be a rule that works on the contact module and
 * silently picks the wrong address on the first customer who adds an
 * `invoice_email` beside the one they actually use.
 *
 * **Two cases, because both are ordinary.**
 *
 *     new MailRecipient('email')                        // a contact's own address
 *     new MailRecipient('email', through: 'contact')    // an invoice has none; its contact does
 *
 * The second is the interesting one and the reason this is not simply a field
 * key: an invoice has no email address anywhere on it, and never will — the
 * address belongs to the customer being invoiced. So the declaration may take
 * **one hop through a `reference`** (§7.6), which is the same rule the query
 * layer already holds for filtering through a link, arrived at from a different
 * direction and for the same reason.
 *
 * **One hop, and a second is deliberately impossible.** `invoice.order.contact.email`
 * is a path nobody can reason about: it is two joins whose cost cannot be
 * estimated from the declaration, and it makes "where did this address come
 * from" a question with a three-part answer on a screen where somebody is about
 * to send a customer a bill. The invoice already copies its contact from the
 * order it was seeded from (XIV-19), which is what makes one hop enough in the
 * case that would have wanted two.
 *
 * `$field` names a field on whichever record the hop lands on — this one, or the
 * one `$through` points at. It is a *key*, which is what a module owns; the label
 * beside it on the screen is the customer's and may say anything (§6.1).
 *
 * What this deliberately does not say is *who* the mail is from. That is the
 * tenant's, answered once in `TenantMailer` (§8.7), and a module that could name
 * a sender would be a module that could send on somebody else's behalf.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class MailRecipient
{
    public function __construct(
        /** The field holding the address, on this record or on the one `$through` names. */
        public string $field,
        /**
         * This module's `reference` field whose target holds the address. Null —
         * the common case — for a module whose records carry their own.
         */
        public ?string $through = null,
    ) {
    }
}

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

namespace Xivi\Invoice;

use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Field\Units;
use Xivi\Core\Lifecycle\Lifecycle;
use Xivi\Core\Lifecycle\LifecycleTransition;
use Xivi\Core\Mail\MailRecipient;
use Xivi\Core\Module\CollectionBlueprint;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleProvider;
use Xivi\Core\Money\LineTotals;
use Xivi\Core\Money\VatMode;
use Xivi\Core\Numbering\NumberFormat;
use Xivi\Core\Payment\PaymentTerms;
use Xivi\Core\Seed\Seed;
use Xivi\Core\Seed\SeedRows;

/**
 * What a customer is asked to pay (XIV-19).
 *
 * **An invoice carries its own lines**, copied from the order when it is made.
 * That is what makes several invoices per order work at all — a deposit and a
 * final invoice, or one per delivery — and it is the only way an invoice can stay
 * correct after the order is edited. Once issued, an invoice is a document and
 * stops following anything.
 *
 * So it references the order and copies from it; it never reads through it. The
 * copying is declared ({@see Seed}) rather than written, which is the interesting
 * part of this module: order → invoice is the same shape as quotation → order and
 * order → delivery note, and an engine that made each of them a class would be an
 * engine with a class per pair.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class InvoiceModule implements ModuleProvider
{
    public const string KEY = 'invoice';

    public const string NUMBER = 'number';
    public const string ORDER = 'order';
    public const string CONTACT = 'contact';
    public const string ISSUED_ON = 'issued_on';
    public const string DUE_DATE = 'due_date';
    public const string STATUS = 'status';
    public const string NET_TOTAL = 'net_total';
    public const string TAX_TOTAL = 'tax_total';
    public const string GROSS_TOTAL = 'gross_total';

    /** Whether the prices on this invoice already have the VAT in them (XIV-116). */
    public const string VAT_MODE = 'vat_mode';

    public const string LINES = 'lines';
    public const string TAXES = 'taxes';

    /** On a line. `order_line` is which of the order's lines this one is for. */
    public const string KIND = 'kind';
    public const string ORDER_LINE = 'order_line';
    public const string ARTICLE = 'article';
    public const string DESCRIPTION = 'description';
    public const string QUANTITY = 'quantity';
    public const string UNIT = 'unit';
    public const string UNIT_PRICE = 'unit_price';
    public const string TAX_RATE = 'tax_rate';
    public const string LINE_TOTAL = 'line_total';

    public const string RATE = 'rate';
    public const string TAXABLE_NET = 'net';
    public const string TAX_AMOUNT = 'amount';

    /** The same four an order's lines come in (XIV-20): what is invoiced reads like what was ordered. */
    public const string ARTICLE_LINE = 'article';
    public const string CUSTOM_LINE = 'custom';
    public const string COMMENT_LINE = 'comment';
    public const string SUBTOTAL_LINE = 'subtotal';

    public const string DRAFT = 'draft';
    public const string SENT = 'sent';
    public const string PAID = 'paid';
    public const string CANCELLED = 'cancelled';

    /** The modules it points at, by key rather than by class (§3). */
    private const string ORDER_MODULE = 'order';
    private const string CONTACT_MODULE = 'contact';
    private const string ARTICLE_MODULE = 'article';

    public function blueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: self::KEY,
            label: 'module',
            table: 'invoice',
            fields: [
                new FieldBlueprint(
                    key: self::NUMBER,
                    label: 'field.number',
                    type: 'text',
                    // **Unique, and the database says so** (XIV-109). Two
                    // documents carrying one number is one of the two fatal
                    // failures §5.10 names, and until XIV-109 the promise was
                    // kept by a counter alone — good arithmetic, and not a
                    // constraint. The flag builds an expression index over the
                    // column, so the promise is enforced by the thing that is
                    // holding the row rather than by the code that asked first.
                    unique: true,
                    filterable: true,
                    title: true,
                    position: 5,
                    derived: true,
                    options: ['max_length' => 40, ...NumberFormat::from('INV-{year}-{number:4}')],
                ),
                // Which order this bills. The order's page lists its invoices off
                // the back of this field alone (XIV-13).
                new FieldBlueprint(
                    key: self::ORDER,
                    label: 'field.order',
                    type: 'reference',
                    required: true,
                    filterable: true,
                    position: 10,
                    options: [ReferenceFieldType::MODULE => self::ORDER_MODULE],
                ),
                // Copied from the order rather than read through it, so that
                // "invoices for this customer" is one query and an invoice keeps
                // saying who it was sent to after the order is repointed.
                new FieldBlueprint(
                    key: self::CONTACT,
                    label: 'field.contact',
                    type: 'reference',
                    required: true,
                    filterable: true,
                    position: 20,
                    options: [ReferenceFieldType::MODULE => self::CONTACT_MODULE],
                ),
                new FieldBlueprint(
                    key: self::ISSUED_ON,
                    label: 'field.issued_on',
                    type: 'date',
                    required: true,
                    filterable: true,
                    position: 30,
                ),
                // When it has to be paid, written down as it goes out (XIV-67).
                // Derived, because nobody types it: what a customer agreed to is
                // worked out from the terms in force at that moment and then kept,
                // for the same reason §5.9 keeps the totals rather than recomputing
                // them off today's price list. Filterable, because "which of these
                // is late" is the question the field exists to answer.
                new FieldBlueprint(
                    key: self::DUE_DATE,
                    label: 'field.due_date',
                    type: 'date',
                    filterable: true,
                    position: 35,
                    derived: true,
                ),
                new FieldBlueprint(
                    key: self::STATUS,
                    label: 'field.status',
                    type: 'choice',
                    required: true,
                    filterable: true,
                    position: 40,
                    options: [
                        'choices' => [
                            self::DRAFT => 'status.draft',
                            self::SENT => 'status.sent',
                            self::PAID => 'status.paid',
                            self::CANCELLED => 'status.cancelled',
                        ],
                        // A ledger somebody could actually read, for demo data
                        // (§5.17): mostly settled, a working tail of outstanding
                        // ones, a couple being prepared and the odd write-off.
                        // The generator walks a record to the state drawn from
                        // here rather than writing it (XIV-73), which is what
                        // makes the sent and paid ones carry the due date §5.16
                        // materialises on the way out — a demo tenant of nothing
                        // but drafts would have no due dates at all, and so
                        // nothing that could be overdue.
                        'samples' => [
                            self::DRAFT, self::SENT, self::SENT,
                            self::PAID, self::PAID, self::PAID, self::PAID,
                            self::CANCELLED,
                        ],
                    ],
                ),
                new FieldBlueprint(
                    key: 'note',
                    label: 'field.note',
                    type: 'textarea',
                    listed: false,
                    position: 50,
                ),
                // **How to read every price on this document** (XIV-116), and on
                // an invoice it is emphatically not a live setting: it arrives by
                // the seed below, from the order this bill was made from.
                //
                // That is this module following itself rather than disagreeing
                // with itself. *Nothing* on an invoice line is read through
                // anything — an invoice quotes what was agreed on the day (§5.12)
                // — and a document whose price column changed meaning because
                // somebody edited a settings page afterwards would be the one
                // field on a sent bill that kept moving. The order was priced
                // some way; the invoice for it is priced the same way; and the
                // customer holding the paper can check the column against the
                // one they signed.
                //
                // Optional, like the order's, and for the same load-bearing
                // reason: every invoice that existed before this field carries
                // nothing here, and nothing is what "prices exclude VAT" looks
                // like — which is exactly what those invoices are.
                new FieldBlueprint(
                    key: self::VAT_MODE,
                    label: 'field.vat_mode',
                    type: 'choice',
                    filterable: true,
                    listed: false,
                    position: 55,
                    // Core's values, this module's labels — see the order's field
                    // for the argument. The two must agree on the values or the
                    // seed would copy a word this field has never heard of.
                    options: [
                        'samples' => VatMode::samples(),
                        ...VatMode::shipped(),
                    ],
                ),
                new FieldBlueprint(
                    key: self::NET_TOTAL,
                    label: 'field.net_total',
                    type: 'currency',
                    filterable: true,
                    listed: false,
                    position: 60,
                    derived: true,
                ),
                new FieldBlueprint(
                    key: self::TAX_TOTAL,
                    label: 'field.tax_total',
                    type: 'currency',
                    filterable: true,
                    listed: false,
                    position: 70,
                    derived: true,
                ),
                new FieldBlueprint(
                    key: self::GROSS_TOTAL,
                    label: 'field.gross_total',
                    type: 'currency',
                    filterable: true,
                    position: 80,
                    derived: true,
                ),
            ],
            collections: [
                new CollectionBlueprint(
                    key: self::LINES,
                    label: 'collection.lines',
                    table: 'invoice_line',
                    fields: [
                        new FieldBlueprint(
                            key: self::KIND,
                            label: 'field.kind',
                            type: 'choice',
                            required: true,
                            position: 5,
                            options: ['choices' => [
                                self::ARTICLE_LINE => 'line.article',
                                self::CUSTOM_LINE => 'line.custom',
                                self::COMMENT_LINE => 'line.comment',
                                self::SUBTOTAL_LINE => 'line.subtotal',
                            ]],
                        ),
                        // Which order line this one bills, so a second invoice
                        // knows what is left (XIV-19). A number rather than a
                        // reference: a collection row is not a record and has no
                        // page to point at (§5.1). Off the list and derived, so
                        // it is bookkeeping rather than something to fill in.
                        new FieldBlueprint(
                            key: self::ORDER_LINE,
                            label: 'field.order_line',
                            type: 'integer',
                            width: 1,
                            listed: false,
                            position: 8,
                            derived: true,
                        ),
                        new FieldBlueprint(
                            key: self::ARTICLE,
                            width: 2,
                            label: 'field.article',
                            type: 'reference',
                            required: true,
                            variants: [self::ARTICLE_LINE],
                            position: 10,
                            options: [ReferenceFieldType::MODULE => self::ARTICLE_MODULE],
                        ),
                        new FieldBlueprint(
                            key: self::DESCRIPTION,
                            // The narrowest of the three text columns on either
                            // document, and it has been since before XIV-118:
                            // the row's budget is twelve twelfths, an order line
                            // spends none of them on bookkeeping and this one
                            // spends one on `order_line` above. The unit took
                            // the second. Past twelve the grid wraps, which
                            // would drop the unit onto a line below the number
                            // it belongs to — the one arrangement that is worse
                            // than a short box.
                            width: 2,
                            label: 'field.description',
                            type: 'text',
                            required: true,
                            position: 20,
                            options: ['max_length' => 255],
                        ),
                        new FieldBlueprint(
                            key: self::QUANTITY,
                            width: 1,
                            label: 'field.quantity',
                            type: 'decimal',
                            required: true,
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE],
                            position: 30,
                            options: ['min' => 0, 'scale' => 2],
                        ),
                        // What the quantity is counted in (XIV-118). The same
                        // field the order line carries and for the same reason —
                        // `2.5` is a figure somebody has to ask about and
                        // `2.5 hours` is one they can check — and on both kinds
                        // of line that carry a quantity, including the custom
                        // one somebody types by hand.
                        //
                        // **It arrives by the seed, not by inheritance**, and
                        // that is this module following itself rather than
                        // disagreeing with the order. Nothing on an invoice line
                        // is inherited: the description, the price and the rate
                        // are all copied down from the order line being billed
                        // (§5.12), because an invoice quotes what was agreed on
                        // the day rather than what the catalogue says now — and
                        // an article's unit is exactly as agreed as its price.
                        // Reading it through the article instead would be the
                        // one field on the document that followed the catalogue
                        // after the order was confirmed.
                        new FieldBlueprint(
                            key: self::UNIT,
                            width: 1,
                            label: 'field.unit',
                            type: 'choice',
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE],
                            position: 35,
                            options: [
                                // For a *generated* invoice, which is made from
                                // the definitions rather than from an order and
                                // so is seeded by nothing (§5.17, XIV-73).
                                'samples' => Units::samples(),
                                ...Units::shipped(),
                            ],
                        ),
                        // No floor, for the same reason the order's has none: a
                        // discount is a line with a negative price (§5.9).
                        new FieldBlueprint(
                            key: self::UNIT_PRICE,
                            width: 2,
                            label: 'field.unit_price',
                            type: 'currency',
                            required: true,
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE],
                            position: 40,
                        ),
                        new FieldBlueprint(
                            key: self::TAX_RATE,
                            width: 1,
                            label: 'field.tax_rate',
                            type: 'decimal',
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE],
                            position: 45,
                            options: [
                                'min' => 0,
                                'max' => 100,
                                'scale' => 2,
                                // The rates this country has, for demo data
                                // (§5.17, XIV-73). An invoiced line normally
                                // arrives from the order that was seeded into
                                // it and carries the rate that was agreed; a
                                // *generated* invoice is made straight from the
                                // definitions, so without this it would show a
                                // VAT percentage no tax authority has ever set.
                                'samples' => [8.1, 8.1, 8.1, 2.6, 3.8, null],
                            ],
                        ),
                        new FieldBlueprint(
                            key: self::LINE_TOTAL,
                            width: 2,
                            label: 'field.line_total',
                            type: 'currency',
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE, self::SUBTOTAL_LINE],
                            position: 50,
                            derived: true,
                        ),
                    ],
                    position: 10,
                    variantField: self::KIND,
                ),
                new CollectionBlueprint(
                    key: self::TAXES,
                    label: 'collection.taxes',
                    table: 'invoice_tax',
                    fields: [
                        new FieldBlueprint(
                            key: self::RATE,
                            label: 'field.rate',
                            type: 'decimal',
                            position: 10,
                            derived: true,
                            options: ['scale' => 2],
                        ),
                        new FieldBlueprint(
                            key: self::TAXABLE_NET,
                            label: 'field.taxable_net',
                            type: 'currency',
                            position: 20,
                            derived: true,
                        ),
                        new FieldBlueprint(
                            key: self::TAX_AMOUNT,
                            label: 'field.tax_amount',
                            type: 'currency',
                            position: 30,
                            derived: true,
                        ),
                    ],
                    position: 20,
                ),
            ],
            icon: 'receipt-cutoff',
            // There is nothing to invoice without an order to invoice it from.
            requires: [self::ORDER_MODULE, self::CONTACT_MODULE],
            uses: [self::ARTICLE_MODULE],
            lifecycle: new Lifecycle(
                field: self::STATUS,
                initial: self::DRAFT,
                transitions: [
                    new LifecycleTransition('send', [self::DRAFT], self::SENT, label: 'transition.send'),
                    new LifecycleTransition('pay', [self::SENT], self::PAID, label: 'transition.pay'),
                    new LifecycleTransition(
                        'cancel',
                        [self::DRAFT, self::SENT],
                        self::CANCELLED,
                        label: 'transition.cancel',
                    ),
                ],
                // **Sent is the end of editing**, not only of drafting. There is
                // no way back to draft — the acceptance criterion — and there is
                // no way to change the figures either, because the customer has
                // the document now. Correcting one is a credit note, which is a
                // second document rather than an edit of the first.
                locked: [self::SENT, self::PAID, self::CANCELLED],
            ),
            lineTotals: new LineTotals(
                collection: self::LINES,
                quantity: self::QUANTITY,
                unitPrice: self::UNIT_PRICE,
                lineTotal: self::LINE_TOTAL,
                netTotal: self::NET_TOTAL,
                grossTotal: self::GROSS_TOTAL,
                taxRate: self::TAX_RATE,
                taxTotal: self::TAX_TOTAL,
                taxes: self::TAXES,
                rate: self::RATE,
                taxableNet: self::TAXABLE_NET,
                taxAmount: self::TAX_AMOUNT,
                subtotalKind: self::SUBTOTAL_LINE,
                vatMode: self::VAT_MODE,
            ),
            // **Made from an order.** The customer comes along so the invoice can
            // be addressed and filtered without a second hop; the lines come
            // along with their prices and rates, because an invoice quotes what
            // was agreed rather than what the catalogue says today.
            //
            // Note what is *not* copied: the line total and the subtotal figure.
            // Both are derived on save (§5.9), so a partial invoice restates its
            // own subtotals instead of repeating the order's — which on an
            // invoice for half the lines would be the most convincing wrong
            // number in the system.
            seed: new Seed(
                from: self::ORDER_MODULE,
                link: self::ORDER,
                // The customer, and how the order's prices were quoted
                // (XIV-116). The second one is what makes an invoice for a
                // shelf-priced order come out shelf-priced without anybody being
                // asked again — and copying it, rather than reading the
                // installation's setting when the invoice is saved, is what keeps
                // a bill saying what the order it came from said.
                fields: [self::CONTACT => 'contact', self::VAT_MODE => 'vat_mode'],
                rows: new SeedRows(
                    from: 'lines',
                    to: self::LINES,
                    fields: [
                        self::KIND => 'kind',
                        self::ARTICLE => 'article',
                        self::DESCRIPTION => 'description',
                        self::QUANTITY => 'quantity',
                        self::UNIT => 'unit',
                        self::UNIT_PRICE => 'unit_price',
                        self::TAX_RATE => 'tax_rate',
                    ],
                    source: self::ORDER_LINE,
                    outstanding: self::QUANTITY,
                ),
            ),
            // The half of XIV-39 this module exists to prove: an invoice has no
            // email address and never will, because the address belongs to the
            // customer being invoiced. So the declaration takes one hop through
            // the contact this invoice already names — the same reference the
            // seed copies down from the order, which is what keeps the hop to
            // one. Reading `order.contact.email` instead would be two, and a
            // path nobody can reason about while looking at a bill.
            mailRecipient: new MailRecipient(field: 'email', through: self::CONTACT),
            // When this falls due, and where the days come from (XIV-67).
            //
            // The same hop the mail recipient takes, through the same reference
            // and for the same reason: an invoice has no payment terms of its own
            // and never will, because a term is a property of the *relationship*
            // rather than of a document. `payment_terms` is a field key on
            // whatever module that reference names, which keeps this a metadata
            // requirement (XIV-23) rather than the code dependency §3 forbids —
            // this package still imports nothing from the contact package.
            //
            // The date is materialised on the way into `sent` and never touched
            // again. That state is not chosen for tidiness: it is where the
            // lifecycle already locks, because the customer has the document now,
            // and it is the first moment a deadline means anything to anybody.
            paymentTerms: new PaymentTerms(
                dueDate: self::DUE_DATE,
                from: self::ISSUED_ON,
                outstanding: self::SENT,
                terms: 'payment_terms',
                through: self::CONTACT,
            ),
        );
    }
}

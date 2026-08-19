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
use Xivi\Core\Record\InheritedValue;
use Xivi\Order\Lifecycle\OrderNeedsALine;

/**
 * What a customer ordered (XIV-18).
 *
 * Still a declaration and nothing else — no controller, no entity, no form class,
 * no template — which is the claim this module was built to test, because it is
 * the first one that is mostly *relationships*: it names a contact, its lines
 * name articles, and neither of those packages knows this one exists.
 *
 * **An order is a header and its lines.** The header says who ordered and when
 * and where the order has got to; the lines say what was ordered. They are a
 * collection (§5.1), so they belong to the order, are edited inside its form and
 * die with it — an order line has no life of its own and no URL.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OrderModule implements ModuleProvider
{
    public const string KEY = 'order';

    /** What the order is called (XIV-15). */
    public const string NUMBER = 'number';

    /** What it sells, and the VAT that follows from it (XIV-16). */
    public const string LINES = 'lines';
    public const string TAXES = 'taxes';

    /** The fields the totals are made of and written to (XIV-16). */
    public const string KIND = 'kind';
    public const string QUANTITY = 'quantity';
    public const string UNIT = 'unit';
    public const string UNIT_PRICE = 'unit_price';
    public const string TAX_RATE = 'tax_rate';
    public const string LINE_TOTAL = 'line_total';
    public const string NET_TOTAL = 'net_total';
    public const string TAX_TOTAL = 'tax_total';
    public const string GROSS_TOTAL = 'gross_total';

    /** Whether the prices on this order already have the VAT in them (XIV-116). */
    public const string VAT_MODE = 'vat_mode';

    /** Which voucher was used on it, where the customer has vouchers at all (XIV-104). */
    public const string VOUCHER = 'voucher';

    /** And the fields of one row of the VAT table. */
    public const string RATE = 'rate';
    public const string TAXABLE_NET = 'net';
    public const string TAX_AMOUNT = 'amount';

    /** The kinds a line comes in (§5.5 one level down — XIV-20). */
    public const string ARTICLE_LINE = 'article';
    public const string CUSTOM_LINE = 'custom';
    public const string COMMENT_LINE = 'comment';
    public const string SUBTOTAL_LINE = 'subtotal';

    /**
     * And the one nobody types (XIV-104): what the voucher above took off, as a
     * line of its own. Generated on every save, one per VAT rate it comes off,
     * and not offered as a kind anybody can add.
     */
    public const string DISCOUNT_LINE = 'discount';

    public const string DRAFT = 'draft';
    public const string CONFIRMED = 'confirmed';
    public const string DELIVERED = 'delivered';
    public const string CANCELLED = 'cancelled';

    /** Which module a line's article points at. Its key, not its package. */
    private const string ARTICLE_MODULE = 'article';

    /** And which one the voucher above names. A key again, and no import (§3). */
    private const string VOUCHER_MODULE = 'voucher';

    public function blueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: self::KEY,
            label: 'module',
            table: 'sales_order',
            fields: [
                // **What the order is called** (XIV-15). Drawn from a sequence
                // as the record is first saved, and never typed — which is why
                // it is also the title: an order somebody refers to on the phone
                // is "ORD-2026-0001", not "the Acme one from Tuesday".
                //
                // The pattern is the customer's from the moment they have the
                // module, like every other option (§6.1): a customer whose
                // bookkeeper wants six digits and no year edits it in the
                // metadata editor and the next order follows.
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
                    // Padded to four, which is also the quiet reason a width is
                    // part of the pattern at all: sorting the text then sorts
                    // the numbers, and 0010 does not come before 0009.
                    options: ['max_length' => 40, ...NumberFormat::from('ORD-{year}-{number:4}')],
                ),
                // Who ordered. A link into another module (XIV-13), which is what
                // makes a contact's page list the orders naming it without either
                // module having been told about the other.
                new FieldBlueprint(
                    key: 'contact',
                    label: 'field.contact',
                    type: 'reference',
                    required: true,
                    filterable: true,
                    listed: true,
                    position: 10,
                    options: [ReferenceFieldType::MODULE => 'contact'],
                ),
                new FieldBlueprint(
                    key: 'ordered_on',
                    label: 'field.ordered_on',
                    type: 'date',
                    required: true,
                    filterable: true,
                    listed: true,
                    position: 20,
                ),
                // Where the order has got to. An ordinary choice field, and the
                // lifecycle below is a rule over it rather than a second store
                // (§5.8).
                new FieldBlueprint(
                    key: 'status',
                    label: 'field.status',
                    type: 'choice',
                    required: true,
                    filterable: true,
                    listed: true,
                    position: 30,
                    options: [
                        'choices' => [
                            self::DRAFT => 'status.draft',
                            self::CONFIRMED => 'status.confirmed',
                            self::DELIVERED => 'status.delivered',
                            self::CANCELLED => 'status.cancelled',
                        ],
                        // What a book of orders looks like, for demo data
                        // (§5.17). The generator reads this as where each record
                        // is *going* and walks it there through the lifecycle
                        // below (XIV-73), so a repetition here is a weight and
                        // not a state anybody wrote down: most orders ship, a
                        // few are still being typed, and one in eight falls
                        // through. Drawn uniformly from an even list would make
                        // a quarter of every demo tenant cancelled, which is not
                        // a business anybody runs.
                        'samples' => [
                            self::DRAFT, self::CONFIRMED, self::CONFIRMED,
                            self::DELIVERED, self::DELIVERED, self::DELIVERED,
                            self::CANCELLED,
                        ],
                    ],
                ),
                new FieldBlueprint(
                    key: 'note',
                    label: 'field.note',
                    type: 'textarea',
                    listed: false,
                    position: 40,
                ),
                // **How to read every price on this document** (XIV-116).
                //
                // A shop in Zurich prices a lamp at 19.95 *including* 8.1%,
                // because that is the number on the shelf and, for anything sold
                // to a consumer, the number the law says has to be shown. Until
                // this field the engine could only be told a net price, so that
                // shop had to divide by 1.081 themselves, type 18.46, and hope it
                // came back — and at 19.95 it does not, because 18.46 plus 8.1%
                // of itself is 19.96. The rappen lands on the customer's own
                // document and nobody can explain it. The arithmetic that fixes
                // that is in `DerivesTotals` and nowhere else.
                //
                // **On the document, and not on the line.** A document with some
                // lines quoted gross and some quoted net is a document nobody can
                // read; no recipient could check a column whose meaning changed
                // halfway down it. The *rate* genuinely differs line by line and
                // is on the line for that reason — how to read a price does not.
                //
                // **And not only on the tenant**, though the tenant is where the
                // default comes from (§8.6): a value copied onto the document
                // means a shop that switches to inclusive pricing has not
                // silently restated every draft in the building, which is §5.9's
                // rule that a stored total is a fact and §5.16's about a date.
                // It also covers the business that does both, in the one place
                // that can: the document that differs.
                //
                // **Optional, and that is the load-bearing part.** Every order
                // that existed before this field carries nothing here, and an
                // empty value reads as "prices exclude VAT" — which is exactly
                // what those orders are. A required field would have made this a
                // migration of somebody's order book instead of an addition to
                // it, and §7.2.1 still retro-fits nobody: an existing customer
                // takes the field from the upgrade offer, and until they do their
                // orders derive precisely as they always did.
                new FieldBlueprint(
                    key: self::VAT_MODE,
                    label: 'field.vat_mode',
                    type: 'choice',
                    // Worth a filter: "which of our orders are shelf-priced" is a
                    // question a shop that has just switched will ask once and
                    // then never again, and it is the cheapest possible way to
                    // answer it. Not a list column, because a phrase repeated
                    // down every row of a page of orders is noise.
                    filterable: true,
                    listed: false,
                    position: 45,
                    // The values are core's, spread into this module's own
                    // options, exactly as the unit above is (§5.20): an invoice
                    // seeded from an order copies this across, and a value the
                    // invoice's field had never heard of would print as its own
                    // key on somebody's bill. The *labels* stay this module's,
                    // because a module that borrowed another's vocabulary would
                    // be a module that cannot be installed on its own.
                    options: [
                        'samples' => VatMode::samples(),
                        ...VatMode::shipped(),
                    ],
                ),
                // **Which voucher was used on this order** (XIV-104).
                //
                // A link into another module, exactly like the customer above and
                // the article on a line — a key in a declaration, and this package
                // imports nothing from the voucher package (§3, XIV-13).
                //
                // **It is only here where the customer has both modules**, which
                // is a fact about their installation rather than about this
                // declaration: `uses` below says the order module installs
                // perfectly well without vouchers, and
                // {@see \Xivi\Core\Module\AvailableFields} is what then leaves
                // this field out of that customer's definitions rather than
                // giving them a picker with nothing in it. Somebody who buys
                // vouchers later is offered the field by the upgrade screen
                // (§7.2.1) — asked, not retro-fitted.
                //
                // **What it does is not stored here.** The discount is a line
                // (§5.9), worked out on every save by the engine's own deriver
                // and written into the lines below as one row per VAT rate. A
                // percentage on the header would be guessing which rate it came
                // off on any document carrying two, which is the argument the
                // unit price on a line has made since XIV-16.
                //
                // Filterable, because "which orders used GIVE-10" is the one
                // question a shop running a promotion asks about it. Not listed:
                // a column that is empty on nearly every row is a column that
                // costs every row its width.
                new FieldBlueprint(
                    key: self::VOUCHER,
                    label: 'field.voucher',
                    type: 'reference',
                    filterable: true,
                    listed: false,
                    position: 46,
                    options: [
                        ReferenceFieldType::MODULE => self::VOUCHER_MODULE,
                        // **A generated order names no voucher** (§5.17,
                        // XIV-73). A reference otherwise samples a real record,
                        // which here would take real uses off real vouchers
                        // while a demo tenant is being built — and a voucher good
                        // once, drawn twice, would *refuse the second order* and
                        // take the whole `tenant:reset` down with it. A list of
                        // one empty value is how a field says "not this one".
                        'samples' => [null],
                    ],
                ),
                // **Stored, not worked out when read** (XIV-16). Three reasons,
                // and the first two are the ones that matter: "orders over 5000"
                // has to be a WHERE clause rather than twenty-five records
                // summed in PHP, and what a confirmed order came to is a fact
                // about that day rather than the result of running today's code
                // over yesterday's lines. The third is that the figures are
                // printed on documents, and a document that disagrees with the
                // list it was found in is a support call.
                new FieldBlueprint(
                    key: self::NET_TOTAL,
                    label: 'field.net_total',
                    type: 'currency',
                    filterable: true,
                    listed: false,
                    position: 50,
                    derived: true,
                ),
                new FieldBlueprint(
                    key: self::TAX_TOTAL,
                    label: 'field.tax_total',
                    type: 'currency',
                    filterable: true,
                    listed: false,
                    position: 60,
                    derived: true,
                ),
                // The one on the list, because it is the figure anybody scanning
                // a page of orders is looking for.
                new FieldBlueprint(
                    key: self::GROSS_TOTAL,
                    label: 'field.gross_total',
                    type: 'currency',
                    filterable: true,
                    position: 70,
                    derived: true,
                ),
            ],
            collections: [
                new CollectionBlueprint(
                    key: self::LINES,
                    label: 'collection.lines',
                    table: 'sales_order_line',
                    fields: [
                        new FieldBlueprint(
                            key: self::KIND,
                            label: 'field.kind',
                            type: 'choice',
                            required: true,
                            position: 5,
                            // **Five kinds, and one of them has no button**
                            // (XIV-104). A discount line is written by the engine
                            // from the voucher this order names, so it is an
                            // ordinary option here — rows of it exist and have to
                            // render, and §5.5 is explicit that the variants *are*
                            // this field's options with no second list anywhere —
                            // and {@see \Xivi\Core\Metadata\AvailableVariants}
                            // is what keeps it out of the "add a line" buttons.
                            options: [
                                'choices' => [
                                    self::ARTICLE_LINE => 'line.article',
                                    self::CUSTOM_LINE => 'line.custom',
                                    self::COMMENT_LINE => 'line.comment',
                                    self::SUBTOTAL_LINE => 'line.subtotal',
                                    self::DISCOUNT_LINE => 'line.discount',
                                ],
                            // **What a generated order's lines are, which is
                            // now four of five kinds** (§5.17, XIV-73, XIV-104).
                            // Without this the draw is uniform over the whole
                            // list, so a fifth of every demo order would carry a
                            // discount line the engine did not write — a row that
                            // says a voucher took money off, on an order that
                            // names no voucher. The generated kinds are the
                            // engine's and the generator says nothing about them,
                            // which is exactly XIV-73's rule about a derived
                            // field applied one level up.
                            //
                            // The four that are here are listed once each, so the
                            // distribution is the one this module has produced
                            // since XIV-24 rather than a new opinion smuggled in
                            // beside a fix.
                            'samples' => [
                                self::ARTICLE_LINE,
                                self::CUSTOM_LINE,
                                self::COMMENT_LINE,
                                self::SUBTOTAL_LINE,
                            ],
                            ],
                        ),
                        // The article this line sells, on the one kind of line
                        // that sells one.
                        new FieldBlueprint(
                            key: 'article',
                            width: 2,
                            label: 'field.article',
                            type: 'reference',
                            required: true,
                            variants: [self::ARTICLE_LINE],
                            position: 10,
                            options: [ReferenceFieldType::MODULE => self::ARTICLE_MODULE],
                        ),
                        // **One field, four meanings**: what the article is
                        // called, what the custom line sells, what the comment
                        // says, what the subtotal is labelled. A comment line is
                        // then not a special case anywhere — it is a line whose
                        // only field is the one every line has.
                        new FieldBlueprint(
                            key: 'description',
                            // Three twelfths rather than four since XIV-118: the
                            // unit took one, and a row that adds up to more than
                            // twelve wraps — which would put the unit on a line
                            // of its own, below the number it qualifies.
                            width: 3,
                            label: 'field.description',
                            type: 'text',
                            required: true,
                            position: 20,
                            options: [
                                'max_length' => 255,
                                // Copied from the article when the line is added,
                                // and the line's own from then on (XIV-18).
                                ...InheritedValue::from('article', 'title'),
                            ],
                        ),
                        // Decimal, because two and a half hours is an ordinary
                        // thing to sell (XIV-22). **What it is two and a half
                        // of is the field below**, which for four tickets this
                        // comment promised and nothing provided: the unit was
                        // said to belong to the article rather than to the line,
                        // correctly, and the article had no unit either — so a
                        // line read `2.5` of nothing and the sentence pointed at
                        // a place that did not exist (XIV-118).
                        new FieldBlueprint(
                            key: self::QUANTITY,
                            width: 1,
                            label: 'field.quantity',
                            type: 'decimal',
                            required: true,
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE, self::DISCOUNT_LINE],
                            position: 30,
                            options: ['min' => 0, 'scale' => 2],
                        ),
                        // **What the quantity is counted in** (XIV-118). Beside
                        // it rather than inside it: a unit is a word and a
                        // quantity is a number, and one field holding "2.5 h"
                        // would be a number nothing can sum. The half of that
                        // old comment which was right is unchanged — the unit is
                        // **owned** by the article, and this field is a copy
                        // taken when the line is written, exactly like the
                        // description and the price above it (§5.1, XIV-18). So
                        // an order placed in hours still says hours after the
                        // catalogue is re-priced in days, and the page marks the
                        // line as drifted when the two disagree, which is the
                        // whole of what inheritance already does for free.
                        //
                        // **A custom line gets the same field, filled in by
                        // hand.** That is the decision rather than the default:
                        // a custom line is priced by hand with no article to
                        // copy from, and it *also* carries a quantity — so
                        // leaving the unit off it would recreate exactly the
                        // `2.5` of nothing this ticket exists to remove, on the
                        // one kind of line where somebody is typing every other
                        // value anyway. Comment and subtotal lines have no
                        // quantity, so there is nothing here for them to
                        // qualify and they are not offered it.
                        //
                        // The options are restated rather than read from the
                        // article's field, because a module may not depend on
                        // another module (§3) and a definition is per shape.
                        // What must not drift is the *values*, since an
                        // inherited `hour` renders as `hour` if this field has
                        // never heard of it — which is why the list is one
                        // static call and not seven strings written out again.
                        new FieldBlueprint(
                            key: self::UNIT,
                            width: 1,
                            label: 'field.unit',
                            type: 'choice',
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE],
                            position: 35,
                            options: [
                                // The same argument the rate below makes: a
                                // generated line picks no article, so without a
                                // weighted list a demo tenant would sell a
                                // seventh of its office chairs by the square
                                // metre (§5.17, XIV-73).
                                'samples' => Units::samples(),
                                ...Units::shipped(),
                                ...InheritedValue::from('article', 'unit'),
                            ],
                        ),
                        // **A negative price is allowed here, and that is where
                        // a discount lives** (XIV-16). Not a percentage on the
                        // header: a discount reduces the VAT base it was given
                        // against, and a header field cannot say which rate it
                        // came off — on a document mixing 8.1% and 2.6% it would
                        // be guessing. A line can say, because a line carries a
                        // rate like every other line. The article's own price
                        // keeps its floor: a catalogue entry that costs less
                        // than nothing is a typo.
                        new FieldBlueprint(
                            key: self::UNIT_PRICE,
                            width: 2,
                            label: 'field.unit_price',
                            type: 'currency',
                            required: true,
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE, self::DISCOUNT_LINE],
                            position: 40,
                            options: InheritedValue::from('article', 'price'),
                        ),
                        // Copied from the article like the price, and editable
                        // afterwards for the same reason: the rate that applies
                        // is the rate that applied on the day (XIV-16).
                        new FieldBlueprint(
                            key: self::TAX_RATE,
                            width: 1,
                            label: 'field.tax_rate',
                            type: 'decimal',
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE, self::DISCOUNT_LINE],
                            position: 45,
                            options: [
                                'min' => 0,
                                'max' => 100,
                                'scale' => 2,
                                // The rates this country has, for demo data
                                // (§5.17). The article module has said this
                                // about its own field since XIV-24; a line
                                // *copies* the rate from the article when
                                // somebody picks one, but a generated line picks
                                // nothing, so without this the uniform draw over
                                // 0 to 100 was putting 63.9% VAT on demo orders
                                // — arithmetically perfect totals that nobody
                                // can sanity-check by looking (XIV-73).
                                'samples' => [8.1, 8.1, 8.1, 2.6, 3.8, null],
                                ...InheritedValue::from('article', 'tax_rate'),
                            ],
                        ),
                        // Derived, so shown and never typed into (XIV-20), and
                        // filled in by OrderTotals during the save (XIV-16). It
                        // means two things: on a priced line, quantity times
                        // price; on a subtotal, the priced lines since the last
                        // one.
                        new FieldBlueprint(
                            key: self::LINE_TOTAL,
                            width: 2,
                            label: 'field.line_total',
                            type: 'currency',
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE, self::SUBTOTAL_LINE, self::DISCOUNT_LINE],
                            position: 50,
                            derived: true,
                        ),
                    ],
                    position: 10,
                    variantField: self::KIND,
                ),
                // **The VAT table, one row per rate** (XIV-16). A collection
                // because the number of rates is not known in advance — 8.1% and
                // 2.6% on one document is ordinary — so there is no set of
                // fields on the header that could hold it.
                //
                // Every field derived, which is what makes the whole collection
                // derived: it is off the form, out of the import and out of the
                // history, and the engine works it out on every save. Stored
                // rather than grouped at print time so that it cannot disagree
                // with the tax total beside it, and so that XIV-17 can print it
                // with the same repeating block as the lines.
                new CollectionBlueprint(
                    key: self::TAXES,
                    label: 'collection.taxes',
                    table: 'sales_order_tax',
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
            icon: 'receipt',
            // Every order names a customer, so this one is not optional (XIV-23).
            lifecycle: new Lifecycle(
                field: 'status',
                initial: self::DRAFT,
                transitions: [
                    // **And a condition on it** (XIV-110). Until this existed a
                    // lifecycle could only refuse the moves the *graph* forbade,
                    // so an order with nothing on it confirmed cleanly — the
                    // button was offered, the POST went through, and a document
                    // with no lines and a total of zero became a confirmed sale.
                    // The rule cannot be a required field, because it is not true
                    // of a draft; the field beside it is required precisely
                    // because "an order names a customer" *is* (§5.8).
                    new LifecycleTransition(
                        'confirm',
                        [self::DRAFT],
                        self::CONFIRMED,
                        label: 'transition.confirm',
                        guard: new OrderNeedsALine(),
                    ),
                    new LifecycleTransition('deliver', [self::CONFIRMED], self::DELIVERED, label: 'transition.deliver'),
                    // From either: an order is called off before it ships, and
                    // which side of confirmation that happens on is not this
                    // module's business.
                    new LifecycleTransition(
                        'cancel',
                        [self::DRAFT, self::CONFIRMED],
                        self::CANCELLED,
                        label: 'transition.cancel',
                    ),
                ],
                // Where it stops. A delivered order is a record of what happened,
                // and a cancelled one is a record of what did not.
                locked: [self::DELIVERED, self::CANCELLED],
            ),
            // Every order names a customer, so this one is not optional (XIV-23).
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
                // What a discount looks like on this module (XIV-104): the kind
                // of row the engine writes, and the field such a row says itself
                // in. Naming them is the whole of what this module has to do
                // about vouchers — how much comes off is the voucher's business
                // and where it lands is the engine's.
                discountKind: self::DISCOUNT_LINE,
                description: 'description',
                vatMode: self::VAT_MODE,
            ),
            // And an order that sells only custom lines is an ordinary order, so
            // this one is: without it, the article line kind is simply not
            // offered.
            requires: ['contact'],
            // Where the money is (XIV-16, declared rather than coded since
            // XIV-19). The arithmetic belongs to the engine; what this module
            // knows is which of its own fields mean what.
            // A catalogue and a promotion, and an order book works without
            // either (XIV-23, XIV-104). Both are `uses` rather than `requires`
            // for the same reason and with different consequences: no articles
            // means the article line kind is not offered, and no vouchers means
            // the voucher field above is not installed at all.
            uses: [self::ARTICLE_MODULE, self::VOUCHER_MODULE],
            // An order confirmation goes to whoever ordered, and an order holds
            // no address of its own (XIV-39). One hop through the contact it
            // already names — the very link `requires` above is about, doing a
            // second job.
            mailRecipient: new MailRecipient(field: 'email', through: 'contact'),
        );
    }
}

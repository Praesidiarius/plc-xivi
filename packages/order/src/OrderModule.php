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
use Xivi\Core\Lifecycle\Lifecycle;
use Xivi\Core\Lifecycle\LifecycleTransition;
use Xivi\Core\Module\CollectionBlueprint;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleProvider;
use Xivi\Core\Numbering\NumberFormat;
use Xivi\Core\Record\InheritedValue;

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
    public const string UNIT_PRICE = 'unit_price';
    public const string TAX_RATE = 'tax_rate';
    public const string LINE_TOTAL = 'line_total';
    public const string NET_TOTAL = 'net_total';
    public const string TAX_TOTAL = 'tax_total';
    public const string GROSS_TOTAL = 'gross_total';

    /** And the fields of one row of the VAT table. */
    public const string RATE = 'rate';
    public const string TAXABLE_NET = 'net';
    public const string TAX_AMOUNT = 'amount';

    /** The kinds a line comes in (§5.5 one level down — XIV-20). */
    public const string ARTICLE_LINE = 'article';
    public const string CUSTOM_LINE = 'custom';
    public const string COMMENT_LINE = 'comment';
    public const string SUBTOTAL_LINE = 'subtotal';

    public const string DRAFT = 'draft';
    public const string CONFIRMED = 'confirmed';
    public const string DELIVERED = 'delivered';
    public const string CANCELLED = 'cancelled';

    /** Which module a line's article points at. Its key, not its package. */
    private const string ARTICLE_MODULE = 'article';

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
                    options: ['choices' => [
                        self::DRAFT => 'status.draft',
                        self::CONFIRMED => 'status.confirmed',
                        self::DELIVERED => 'status.delivered',
                        self::CANCELLED => 'status.cancelled',
                    ]],
                ),
                new FieldBlueprint(
                    key: 'note',
                    label: 'field.note',
                    type: 'textarea',
                    listed: false,
                    position: 40,
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
                            options: ['choices' => [
                                self::ARTICLE_LINE => 'line.article',
                                self::CUSTOM_LINE => 'line.custom',
                                self::COMMENT_LINE => 'line.comment',
                                self::SUBTOTAL_LINE => 'line.subtotal',
                            ]],
                        ),
                        // The article this line sells, on the one kind of line
                        // that sells one.
                        new FieldBlueprint(
                            key: 'article',
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
                        // thing to sell (XIV-22). A unit — hours, kilos, metres —
                        // is deliberately absent: it belongs to the article
                        // rather than to this line, and a unit that only
                        // decorates the number is worse than none.
                        new FieldBlueprint(
                            key: self::QUANTITY,
                            label: 'field.quantity',
                            type: 'decimal',
                            required: true,
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE],
                            position: 30,
                            options: ['min' => 0, 'scale' => 2],
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
                            label: 'field.unit_price',
                            type: 'currency',
                            required: true,
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE],
                            position: 40,
                            options: InheritedValue::from('article', 'price'),
                        ),
                        // Copied from the article like the price, and editable
                        // afterwards for the same reason: the rate that applies
                        // is the rate that applied on the day (XIV-16).
                        new FieldBlueprint(
                            key: self::TAX_RATE,
                            label: 'field.tax_rate',
                            type: 'decimal',
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE],
                            position: 45,
                            options: [
                                'min' => 0,
                                'max' => 100,
                                'scale' => 2,
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
            requires: ['contact'],
            // And an order that sells only custom lines is an ordinary order, so
            // this one is: without it, the article line kind is simply not
            // offered.
            uses: [self::ARTICLE_MODULE],
            lifecycle: new Lifecycle(
                field: 'status',
                initial: self::DRAFT,
                transitions: [
                    new LifecycleTransition('confirm', [self::DRAFT], self::CONFIRMED, label: 'transition.confirm'),
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
        );
    }
}

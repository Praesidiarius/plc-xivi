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
                    position: 40,
                    listed: false,
                ),
            ],
            collections: [
                new CollectionBlueprint(
                    key: 'lines',
                    label: 'collection.lines',
                    table: 'sales_order_line',
                    fields: [
                        new FieldBlueprint(
                            key: 'kind',
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
                        new FieldBlueprint(
                            key: 'quantity',
                            label: 'field.quantity',
                            type: 'integer',
                            required: true,
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE],
                            position: 30,
                            options: ['min' => 1],
                        ),
                        new FieldBlueprint(
                            key: 'unit_price',
                            label: 'field.unit_price',
                            type: 'currency',
                            required: true,
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE],
                            position: 40,
                            options: [
                                'min' => 0,
                                ...InheritedValue::from('article', 'price'),
                            ],
                        ),
                        // Derived, so shown and never typed into (XIV-20). What
                        // fills it in is XIV-16; until then it is the shape of
                        // the answer rather than the answer.
                        new FieldBlueprint(
                            key: 'line_total',
                            label: 'field.line_total',
                            type: 'currency',
                            variants: [self::ARTICLE_LINE, self::CUSTOM_LINE, self::SUBTOTAL_LINE],
                            position: 50,
                            derived: true,
                        ),
                    ],
                    position: 10,
                    variantField: 'kind',
                ),
            ],
            icon: 'receipt',
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

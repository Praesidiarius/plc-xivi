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

namespace Xivi\Article;

use Xivi\Core\Field\Units;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleProvider;

/**
 * The article module: the things a customer sells (XIV-11).
 *
 * A handful of fields and a declaration, which is the whole module — no entity, no
 * repository, no form class, no controller, and nothing added to the engine to
 * make room for it beyond the two field types it needed. Contact showed the
 * engine could describe a module; this one shows it was not quietly built around
 * that particular module.
 *
 * Deliberately small. "A simple article with a title, a description and a price"
 * is the whole brief, and the fields a customer's catalogue actually needs —
 * a number, a supplier, a stock level — are theirs to add in the editor (§5.4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ArticleModule implements ModuleProvider
{
    public const string KEY = 'article';

    public function blueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: self::KEY,
            label: 'module',
            table: 'article',
            fields: [
                new FieldBlueprint(
                    key: 'title',
                    label: 'field.title',
                    type: 'text',
                    required: true,
                    filterable: true,
                    // What an article is called, and therefore the heading on its
                    // page and the link in every list (§5.4).
                    title: true,
                    position: 10,
                    options: [
                        'max_length' => 180,
                        // Things somebody sells, because nothing else could know
                        // (XIV-24). The engine sees a required text field called
                        // `title` and offers what it offers a name — so a
                        // catalogue used to be full of "Kuhn GmbH", which is a
                        // company and not a product. Goods and services both:
                        // an hour of work is an article too, and a list where
                        // every row is an object hides the case where one is not.
                        'samples' => [
                            'Bürostuhl Ergo',
                            'Schreibtisch Eiche 160 cm',
                            'Rollcontainer 3 Schubladen',
                            'Aktenregal Basis',
                            'LED-Deckenleuchte 40 W',
                            'Kaffeemaschine Kompakt',
                            'Wasserkocher 1.7 l',
                            'Werkzeugkoffer 82-teilig',
                            'Akkuschrauber 18 V',
                            'Kabeltrommel 25 m',
                            'Druckerpapier A4, 500 Blatt',
                            'Toner schwarz',
                            'Montage vor Ort, pro Stunde',
                            'Beratung, pro Stunde',
                            'Wartung Jahrespauschale',
                            'Lieferung innerhalb der Region',
                        ],
                    ],
                ),
                new FieldBlueprint(
                    key: 'description',
                    label: 'field.description',
                    type: 'textarea',
                    filterable: true,
                    // Not a column: a paragraph in a table squeezes every other
                    // column into nothing, and the list is for finding an article
                    // rather than for reading about it.
                    listed: false,
                    position: 20,
                ),
                new FieldBlueprint(
                    key: 'price',
                    label: 'field.price',
                    type: 'currency',
                    filterable: true,
                    position: 30,
                    // A negative price is a refund, which is a different thing
                    // with a different name; the field refuses one rather than
                    // letting a typo become a credit.
                    options: ['min' => 0],
                ),
                // **What the price is a price *of*** (XIV-118). An hour, a
                // kilo, a metre — the thing an order line's `2.5` is two and a
                // half of. It lives here rather than on the line because it is a
                // fact about the article: a desk is sold by the piece on every
                // order it ever appears on, and a line that carried its own unit
                // would let one order sell it by the metre. The line still shows
                // it, by taking a copy the same way it takes the title and the
                // price (§5.1's inherited values) — ownership here, rendering
                // there, and no second mechanism between them.
                //
                // **Optional, and that is load-bearing rather than lenient.**
                // Every article that existed before this field did has no unit,
                // and an order line for one has to read exactly as it read
                // yesterday: a number and nothing after it. A required unit
                // would have made this field a migration of somebody's
                // catalogue instead of an addition to it.
                //
                // The options are the seven {@see Units} ships, seeded into this
                // customer's definitions at install and theirs from then on
                // (§6.1). Why a shipped set rather than a table the customer
                // maintains — and what is still missing before they can add
                // "pallet" — is argued at length on that class.
                new FieldBlueprint(
                    key: 'unit',
                    label: 'field.unit',
                    type: 'choice',
                    // "Everything we sell by the hour" is a question a price
                    // list gets asked, and it is one query rather than a read of
                    // every article.
                    filterable: true,
                    position: 35,
                    options: [
                        ...Units::shipped(),
                        'samples' => Units::samples(),
                    ],
                ),
                // What VAT it is sold at, as a percentage (XIV-16). A number
                // rather than a choice of 8.1, 2.6 and 3.8, because those are
                // this year's Swiss rates and this is not a Swiss engine — a
                // closed list would be wrong in another country and wrong here
                // the next time parliament moves one. Empty means no VAT, which
                // is the right default for a customer who is not registered for
                // it and sees no tax on their documents at all.
                new FieldBlueprint(
                    key: 'tax_rate',
                    label: 'field.tax_rate',
                    type: 'decimal',
                    filterable: true,
                    position: 40,
                    options: [
                        'min' => 0,
                        'max' => 100,
                        'scale' => 2,
                        // What a rate *looks* like, which is a different question
                        // from what the field accepts (XIV-24). It still accepts
                        // anything from nothing to a hundred, for the reason
                        // above; this list only says that demo data drawn
                        // uniformly across that range — 63.90, 40.55 — is data
                        // nobody can read an invoice total off. Swiss rates
                        // because the demo vocabulary is Swiss too, and being
                        // out of date here costs a silly-looking demo record and
                        // nothing else.
                        //
                        // The standard rate three times over because half a
                        // catalogue is not sold at the reduced one, and null
                        // among them because an article with no VAT at all is a
                        // real case and the one whose totals are easiest to get
                        // wrong.
                        'samples' => [8.1, 8.1, 8.1, 2.6, 3.8, null],
                    ],
                ),
            ],
            // No presets. Three fields is already the smallest honest version of
            // this module, so a "basic" one could only leave out the price —
            // which is the thing an article is for (§6.1).
            icon: 'box-seam',
        );
    }
}

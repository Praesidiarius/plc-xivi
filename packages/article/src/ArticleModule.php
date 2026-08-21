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

use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Field\Units;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleProvider;
use Xivi\Core\Record\InheritedValue;

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
 * ### An article sold in more than one variant ([XIV-133], §5.32)
 *
 * A T-shirt in three sizes used to be three articles, which lost the fact that
 * they are one thing and made every edit to the description three edits. So an
 * article now comes in three **kinds**, and the whole of the feature is that
 * list plus one link:
 *
 * - {@see self::PLAIN} is the article this module has always had, unchanged in
 *   every field it carries and in everything the rest of the system does with
 *   it. It is what a catalogue is mostly made of.
 * - {@see self::BASE} is an article that is *not sold as itself*: the T-shirt.
 *   It holds the description, the unit and the VAT rate, and carries no price,
 *   because the price belongs to the thing somebody can actually buy.
 * - {@see self::SKU} is one of those things: the large one, the weekend rate.
 *   It names its base through {@see self::SKU_OF}, brings its own title and its
 *   own price, and **has no description of its own at all**. That is what makes
 *   the description live in one place instead of three.
 *
 * **The word.** §5.5 already calls something a variant: the metadata concept
 * that decides which fields a record has. A product variant is a different idea,
 * same product and several sellable things, and two meanings of one word in one
 * codebase is how a design conversation goes wrong six months later. So the code
 * says **SKU** and never says variant, while the *labels* a customer reads say
 * "Variant", because that is the word a Swiss SMB uses and they never read §5.5.
 * The two are separate concepts; the mechanism underneath is deliberately shared,
 * since §5.5's variants are exactly "one module, several kinds of record" and
 * inventing a second way to say that is what §1 refuses.
 *
 * **The engine gained nothing for this**, which is the test the shape was chosen
 * to pass: an SKU is an ordinary article record, so an order line naming one is
 * an order line doing what it already did, and `packages/core` is untouched. One
 * thing was *wired*: `InheritedValues::fillIn()` has taken any shape since
 * XIV-18 and had only ever been called for collection rows, because until now
 * the only field declaring `inherit` was an order line's. The application makes
 * the same call for a record's own fields, which is what lets the unit and the
 * VAT rate below arrive from the base.
 *
 * **Stock is not here and is not coming with this** (§5.32). Variants are the
 * usual excuse for introducing inventory, and inventory is a larger feature with
 * its own movements, its own reservations and its own arithmetic. An SKU is a
 * price and a name.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ArticleModule implements ModuleProvider
{
    public const string KEY = 'article';

    /** Which of the three kinds an article is; the field the variants hang off (§5.5). */
    public const string KIND = 'kind';

    /**
     * The kinds, which are the values stored in {@see self::KIND} ([XIV-133]).
     *
     * `plain` first and named for what it is rather than for what it is not: a
     * catalogue is mostly articles that are simply sold, and a customer adding
     * one should be reading the ordinary word.
     */
    public const string PLAIN = 'plain';
    public const string BASE = 'base';
    public const string SKU = 'sku';

    /**
     * The kinds an order line, or anything else that *sells* an article, may
     * point at ([XIV-133]).
     *
     * Everything except a base, because a base is not a sellable thing: an order
     * for "T-shirt ×3" with no size on it is an order nobody can fulfil, which
     * is the whole reason the base exists as a kind of its own rather than as a
     * plain article that happens to have children. Naming it here, on the module
     * that owns the records, is the nearest this codebase has to saying it once;
     * the modules that point at articles restate the two strings, because a
     * module may not depend on a module (§3) and a field option is a definition
     * rather than a call.
     *
     * @var list<string>
     */
    public const array SELLABLE = [self::PLAIN, self::SKU];

    /** Which base an SKU is a variant of. Empty on every other kind. */
    public const string SKU_OF = 'sku_of';

    public function blueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: self::KEY,
            label: 'module',
            table: 'article',
            fields: [
                // **Which of the three kinds this article is** ([XIV-133], §5.5).
                // Its options *are* the kinds, so there is no second list
                // anywhere that could disagree about what an article may be.
                //
                // Required, because a record with no kind carries only the
                // fields that belong to every kind and would come out as a title
                // and nothing else. The chooser in front of the form is what
                // §5.5 already does for a contact, and it is the one thing about
                // adding an article that this ticket changes.
                new FieldBlueprint(
                    key: self::KIND,
                    label: 'field.kind',
                    type: 'choice',
                    required: true,
                    // "Show me everything sold in variants" is a question a
                    // price list gets asked while somebody is tidying one.
                    filterable: true,
                    position: 5,
                    options: [
                        'choices' => [
                            self::PLAIN => 'choice.plain',
                            self::BASE => 'choice.base',
                            self::SKU => 'choice.sku',
                        ],
                        // **A generated catalogue is all plain articles** (§5.17,
                        // XIV-73), which is the same decision, on the same
                        // argument, as the voucher module's unrestricted
                        // article link. A base and its SKUs are a *relationship*,
                        // and the generator draws each field independently: it
                        // would make "Toner schwarz" a variant of "Beratung, pro
                        // Stunde", which is a demo of a feature nobody would
                        // recognise. Worse, the first article of a run is as
                        // likely to be an SKU as anything else, and an SKU
                        // generated before any base exists has nothing to point
                        // at. {@see \Xivi\Core\Field\Type\ReferenceFieldType}
                        // reads its candidates once per field, so an empty first
                        // answer is the answer for the rest of the run.
                        //
                        // The cost is that a demo tenant does not show variants
                        // off. That is worth paying twice over here: the feature
                        // is two records and a link, so a person who wants to see
                        // it makes one in about a minute.
                        'samples' => [self::PLAIN],
                    ],
                ),
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
                // **Which base this SKU is a variant of** ([XIV-133]).
                //
                // A self-reference, which is what makes the whole feature cost
                // the engine nothing: an SKU is a *record of this module*, so
                // every reference that already points at an article, whether
                // an order line, an invoice line or a voucher's restriction,
                // points at one of these with no code changing, and an order line
                // therefore records which variant was sold by doing exactly what
                // it already did. The alternative was a collection row on the
                // article, which reads better on a page and is not referenceable:
                // a row has no id anything may link to (§5.1, §5.12), and making
                // one would have been an engine capability with a single
                // consumer.
                //
                // **Narrowed to bases, and that narrowing does two jobs**
                // ([XIV-172] is the option's list form). It stops an SKU being a
                // variant of a plain article, which would leave the plain one
                // sellable beside its own variants; and it stops an SKU being a
                // variant of an SKU, so chains are impossible by construction
                // rather than by a rule somebody has to enforce later.
                //
                // Required on an SKU: a variant of nothing is a plain article
                // that has lost its price field.
                new FieldBlueprint(
                    key: self::SKU_OF,
                    label: 'field.sku_of',
                    type: 'reference',
                    required: true,
                    // "Everything under the T-shirt" is the question this link
                    // exists to answer, and the record page answers it from the
                    // other end for free (XIV-52).
                    filterable: true,
                    variants: [self::SKU],
                    position: 15,
                    options: [
                        ReferenceFieldType::MODULE => self::KEY,
                        ReferenceFieldType::VARIANT => [self::BASE],
                    ],
                ),
                // **Not on an SKU**, and that absence is the point of the whole
                // ticket ([XIV-133]). Three sizes of one T-shirt share one
                // description, written once on the base, and there is no copy on
                // the variant to drift away from it or to be edited three times.
                // A base keeps it for the same reason: the description is a fact
                // about the product rather than about the thing being sold.
                new FieldBlueprint(
                    key: 'description',
                    label: 'field.description',
                    type: 'textarea',
                    filterable: true,
                    variants: [self::PLAIN, self::BASE],
                    // Not a column: a paragraph in a table squeezes every other
                    // column into nothing, and the list is for finding an article
                    // rather than for reading about it.
                    listed: false,
                    position: 20,
                ),
                // **The price is the SKU's own, and a base has none** ([XIV-133]).
                //
                // Half of the short, fixed list of what a variant overrides. The
                // other half is its title. It is not inherited from the base,
                // because there is nothing there to inherit: an article sold in
                // three sizes at three prices has no one price, and a base
                // carrying a fourth number would only be a number that disagrees
                // with all of them.
                //
                // A base whose price field disappears **keeps whatever was in
                // it**, which is §5.5's storage rule and is what lets a plain
                // article that turns out to sell in sizes become a base by
                // changing one dropdown. Nothing is rewritten, and every order
                // line that already named it still reads exactly as it did,
                // because a line holds its own copy (XIV-18).
                new FieldBlueprint(
                    key: 'price',
                    label: 'field.price',
                    type: 'currency',
                    filterable: true,
                    variants: [self::PLAIN, self::SKU],
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
                //
                // **On an SKU it is taken from the base** ([XIV-133]), through
                // the same declaration an order line uses to take it from here
                // (XIV-18): copied once, when the SKU is written, and the SKU's
                // own from then on. Not on the short list of what a variant
                // overrides, in other words, and not enforced either: a copy
                // somebody edits is somebody's decision, exactly as it is on a
                // line, and the record page marks it as drifted rather than
                // arguing. A plain article carries the same declaration and
                // nothing happens: it names no base, so there is nothing to take
                // from ({@see \Xivi\Core\Record\InheritedValues}).
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
                        ...InheritedValue::from(self::SKU_OF, 'unit'),
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
                        // And taken from the base on an SKU, for the reason the
                        // unit above gives at length ([XIV-133]). Two sizes of
                        // one shirt are not taxed differently, so the rate is the
                        // base's answer and the variant only carries a copy of
                        // it. That matters more here than for the unit, because
                        // an empty rate is not a blank on a document, it is no
                        // VAT at all.
                        ...InheritedValue::from(self::SKU_OF, 'tax_rate'),
                    ],
                ),
            ],
            // No presets. Three fields is already the smallest honest version of
            // this module, so a "basic" one could only leave out the price —
            // which is the thing an article is for (§6.1). The kinds are not a
            // preset either and could not be: a preset picks which fields a
            // customer installs, and all three kinds are the same installation.
            icon: 'box-seam',
            // One module, three kinds of record ([XIV-133], §5.5), on the
            // same mechanism that makes a contact a person or a company and for
            // the same reason: two modules would make every link into a catalogue
            // polymorphic, which is the shape that cannot carry a foreign key.
            variantField: self::KIND,
        );
    }
}

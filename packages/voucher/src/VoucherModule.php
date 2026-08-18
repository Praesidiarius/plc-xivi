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

namespace Xivi\Voucher;

use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModuleProvider;
use Xivi\Voucher\Code\VoucherCodeFieldType;

/**
 * Codes a customer hands out, and what each one is worth (XIV-103).
 *
 * A declaration and nothing else, like every module before it — no controller,
 * no entity, no form class, no template. What is new is one field type and one
 * counter, and both are outside this file on purpose: this stays the single
 * readable answer to "what is a voucher here".
 *
 * **Applying one to an order is [XIV-104] and is not in this module at all.**
 * What is here is a voucher *existing*: being called something, being worth
 * something, being in date, and being redeemable a bounded number of times.
 *
 * ### The kind is a variant, and that is what variants are for
 *
 * A voucher is money off a total, a percentage off a total, or a free article.
 * Three kinds, one shape — §5.5 — and the deciding fact is the one that section
 * names: **the fields depend on the answer.** An absolute voucher has an amount
 * and no percentage; a free-article voucher has neither and has a link and a
 * quantity instead.
 *
 * The two alternatives both lose, and it is worth saying how.
 *
 * *Three modules* would put three entries in the navigation for one idea, and
 * would make "which voucher was used on this order" a polymorphic reference —
 * an id plus a type saying which table it points at, which is the shape §5.2
 * refused once already and which [XIV-104] would then have to carry for ever.
 *
 * *One shape with a nullable field per kind* would offer every customer an
 * amount, a percentage, an article and a quantity on every voucher, with nothing
 * anywhere saying that filling two of them in is nonsense. Validation would have
 * to grow a rule the engine has no way to express, and the form would ask four
 * questions where one is meant.
 *
 * §5.5's consequence follows for free and is a feature rather than a cost:
 * **adding a voucher asks which kind first**, because the fields depend on the
 * answer and something has to settle it before the form is drawn.
 *
 * ### It does not require the article module
 *
 * Only one of the three kinds needs an article to point at, and `requires` is
 * per module rather than per variant ([XIV-23]). Declaring the requirement would
 * mean a customer who wants `GIVE-10` off a total cannot have vouchers at all
 * unless they also keep a catalogue — a module refused over a kind they were
 * never going to use.
 *
 * So it is `uses`, which is precisely the distinction [XIV-23] drew for the order
 * module's article lines: installing succeeds, and the part that depends on the
 * missing module is **not offered**. That is not a promise this module has to
 * keep by hand. {@see \Xivi\Core\Metadata\AvailableVariants} already hides a
 * variant whose *required* reference points at a module the customer has not
 * installed, and both the record form and the "which kind" chooser ask it — so a
 * customer with no articles is offered two kinds, and the third is not there to
 * be chosen wrongly. The one thing this module has to get right for that to
 * work is that the article link is **required** on its variant, which is also
 * what it should be for its own sake: a free-article voucher that names no
 * article gives away nothing.
 *
 * It is also why §7.6's other answer — a link into an uninstalled module reads as
 * `#id` and matches nothing — is the right *fallback* here and the wrong primary
 * mechanism. Reading as `#id` is what should happen to a voucher created while
 * the article module was installed and read after it was removed. Offering
 * somebody a kind they can only fill in with a picker that has nothing in it is
 * a different thing: broken rather than degraded.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VoucherModule implements ModuleProvider
{
    public const string KEY = 'voucher';

    /** What the voucher is called, and the one field that is unique. */
    public const string CODE = 'code';

    /** Which of the three kinds it is — the variant field (§5.5). */
    public const string KIND = 'kind';

    public const string ABSOLUTE = 'absolute';
    public const string RELATIVE = 'relative';
    public const string FREE_ARTICLE = 'free_article';

    /** What each kind is worth. One of these per kind, and never two at once. */
    public const string AMOUNT = 'amount';
    public const string PERCENTAGE = 'percentage';
    public const string ARTICLE = 'article';
    public const string QUANTITY = 'quantity';

    /** When it is good for, read rather than stored (see Validity\VoucherValidity). */
    public const string VALID_FROM = 'valid_from';
    public const string VALID_UNTIL = 'valid_until';

    /** How many times it may be used. Empty means unlimited — see below. */
    public const string MAX_REDEMPTIONS = 'max_redemptions';

    /** Which module the free-article kind points at. Its key, not its package. */
    private const string ARTICLE_MODULE = 'article';

    public function blueprint(): ModuleBlueprint
    {
        return new ModuleBlueprint(
            key: self::KEY,
            label: 'module',
            table: 'voucher',
            fields: [
                // **The name of the thing, and the only field a customer outside
                // this installation will ever see.**
                //
                // Optional rather than required, which looks wrong for a
                // record's title and is the whole affordance: leaving it empty is
                // how somebody asks for a generated one
                // (Code\AssignsVoucherCodes). Required, there would be no way to
                // ask.
                //
                // Not `derived`. A derived field is shown and never offered for
                // editing (§5.1), which is right for a document number nobody
                // chooses and exactly wrong here — `GIVE-10` is the point.
                //
                // **Unique, and the database says so** ([XIV-109]). Two vouchers
                // answering to one code is the failure this module cannot
                // recover from: a code is redeemed by name, so a duplicate means
                // the till picks one of them and the customer's limits, dates
                // and discount are whichever it happened to pick. The flag builds
                // a partial unique expression index over the column, so the
                // promise is kept by the thing holding the row rather than by
                // whoever asked first — and the validator in front of it is what
                // turns the second attempt into a message on this field while
                // the form is still open, rather than a failed write.
                new FieldBlueprint(
                    key: self::CODE,
                    label: 'field.code',
                    type: VoucherCodeFieldType::KEY,
                    unique: true,
                    filterable: true,
                    title: true,
                    position: 10,
                ),
                // The variant field. An ordinary choice field, and the variants
                // *are* its options (§5.5) — there is no second list anywhere
                // that could disagree with it.
                new FieldBlueprint(
                    key: self::KIND,
                    label: 'field.kind',
                    type: 'choice',
                    required: true,
                    filterable: true,
                    position: 20,
                    options: [
                        'choices' => [
                            self::ABSOLUTE => 'kind.absolute',
                            self::RELATIVE => 'kind.relative',
                            self::FREE_ARTICLE => 'kind.free_article',
                        ],
                        // What a book of vouchers looks like, for demo data
                        // (§5.17). Money off is the ordinary case and free
                        // articles are the rare one, so a uniform draw over three
                        // options would make a third of every demo tenant's
                        // vouchers a free article — which is not a promotion
                        // anybody runs.
                        'samples' => [
                            self::ABSOLUTE, self::ABSOLUTE, self::ABSOLUTE,
                            self::RELATIVE, self::RELATIVE,
                            self::FREE_ARTICLE,
                        ],
                    ],
                ),
                // **Money off a total.** `currency` rather than `decimal`,
                // because it is money and the difference between the two types is
                // meaning rather than storage (§5.1, XIV-22): it prints with the
                // instance's currency symbol, and a symbol beside a percentage
                // would be wrong in a way no formatting fixes.
                //
                // The floor is zero. A voucher worth less than nothing is a
                // surcharge, which is a different feature with a different name;
                // the field refuses one rather than letting a typo become one.
                new FieldBlueprint(
                    key: self::AMOUNT,
                    label: 'field.amount',
                    type: 'currency',
                    required: true,
                    filterable: true,
                    variants: [self::ABSOLUTE],
                    position: 30,
                    options: ['min' => 0],
                ),
                // **A percentage off a total.** `decimal` for the same reason the
                // article module's VAT rate is one: it is measured rather than
                // counted, and it is not money.
                //
                // Capped at 100, which is the sensible maximum and is not merely
                // tidiness — a 120% voucher is an order that owes the customer
                // money, and nothing downstream is built to hand any back. Two
                // places, because 8.5% off is an ordinary offer and a scale of
                // zero would silently round it.
                new FieldBlueprint(
                    key: self::PERCENTAGE,
                    label: 'field.percentage',
                    type: 'decimal',
                    required: true,
                    filterable: true,
                    variants: [self::RELATIVE],
                    position: 40,
                    options: [
                        'min' => 0,
                        'max' => 100,
                        'scale' => 2,
                        // What a discount actually looks like (§5.17, XIV-24).
                        // Drawn uniformly from 0 to 100 a demo tenant is full of
                        // 63.90% vouchers, which are arithmetically fine and
                        // which nobody can sanity-check by looking (XIV-73).
                        'samples' => [5, 10, 10, 15, 20, 25, 50],
                    ],
                ),
                // **The article this voucher gives away**, on the one kind that
                // gives one away. A link into another module ([XIV-13]) — one
                // hop, a key in a declaration, and no import from that package.
                //
                // **Required, and that is load-bearing twice.** Once for its own
                // sake: a free-article voucher naming no article gives nothing
                // away. And once for the module boundary — `AvailableVariants`
                // hides a variant whose *required* reference points at a module
                // the customer has not installed, and only a required one, so
                // this flag is what makes the `uses` decision in the class
                // docblock actually happen.
                new FieldBlueprint(
                    key: self::ARTICLE,
                    label: 'field.article',
                    type: 'reference',
                    required: true,
                    filterable: true,
                    variants: [self::FREE_ARTICLE],
                    position: 50,
                    options: [ReferenceFieldType::MODULE => self::ARTICLE_MODULE],
                ),
                // How many of it. `decimal` rather than `integer`, matching an
                // order line's own quantity (§5.1): half an hour of consulting is
                // a thing somebody gives away, and [XIV-104] will copy this
                // straight onto a line whose quantity is a decimal — two types
                // meeting there would be a conversion nobody asked for.
                new FieldBlueprint(
                    key: self::QUANTITY,
                    label: 'field.quantity',
                    type: 'decimal',
                    required: true,
                    variants: [self::FREE_ARTICLE],
                    position: 60,
                    options: ['min' => 0, 'scale' => 2, 'samples' => [1, 1, 1, 2, 3]],
                ),
                // **When it is good for.** Both optional, and both absences mean
                // "no boundary in that direction" rather than "not yet" or
                // "already over" — see Validity\VoucherValidity, which is also
                // where the argument lives for why *expired* is a read and never
                // a stored state.
                new FieldBlueprint(
                    key: self::VALID_FROM,
                    label: 'field.valid_from',
                    type: 'date',
                    filterable: true,
                    position: 70,
                ),
                new FieldBlueprint(
                    key: self::VALID_UNTIL,
                    label: 'field.valid_until',
                    type: 'date',
                    filterable: true,
                    position: 80,
                ),
                // **Once, a fixed number, or unlimited — and unlimited is the
                // empty field.**
                //
                // Three states in one optional integer, which is fewer moving
                // parts than it looks like: "once" is 1, "N times" is N, and
                // unlimited is **nothing stored at all**. There is deliberately
                // no sentinel — not 0, not -1, not 999999999 — because a sentinel
                // is a number, and a number gets compared: `redeemed < 999999999`
                // is true for reasons that have nothing to do with anybody having
                // asked for an unlimited voucher, and it stops being true on the
                // day somebody's promotion is more popular than the person who
                // picked the constant imagined. Absence cannot be compared by
                // accident. The guard in Redemption\VoucherRedemptions has to
                // write the branch out as `IS NULL`, which is the point.
                //
                // A choice field of once/limited/unlimited plus a number was the
                // alternative and is worse: the shape already has a variant field
                // and it is the discount kind, so a second three-way choice
                // could not hide the number the way variants hide fields, and a
                // customer would be able to pick "unlimited" with 5 still in the
                // box beside it. Two controls that can disagree, to say what one
                // empty box says.
                //
                // The floor is 1. Zero redemptions is not a voucher, it is a
                // voucher somebody has switched off, and this module has no
                // notion of switching one off — the dates are how that is said.
                new FieldBlueprint(
                    key: self::MAX_REDEMPTIONS,
                    label: 'field.max_redemptions',
                    type: 'integer',
                    filterable: true,
                    position: 90,
                    options: [
                        'min' => 1,
                        // Most vouchers in the wild are single-use or unlimited
                        // and very few are "exactly 37 times", so the demo data
                        // says so — null included, because unlimited is the state
                        // whose page nobody thinks to look at (§5.17).
                        'samples' => [1, 1, 1, 5, 10, 100, null],
                    ],
                ),
            ],
            icon: 'ticket-perforated',
            // No presets. Every field here is either the code, the kind, or a
            // field only one kind can see, so the only subset a preset could
            // offer is "vouchers without dates" or "vouchers without limits" —
            // neither of which is a smaller version of the module, only a broken
            // one (§6.1).
            variantField: self::KIND,
            // Nothing. See the class docblock: only one kind of the three needs
            // articles, and `requires` cannot say "only for this variant".
            requires: [],
            // The free-article kind, and only it. Installing without articles
            // succeeds and that kind is simply not offered ([XIV-23]).
            uses: [self::ARTICLE_MODULE],
        );
    }
}

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
 * Codes a customer hands out, and what each one is worth (XIV-103, XIV-122).
 *
 * A declaration and nothing else, like every module before it — no controller,
 * no entity, no form class, no template. What is new is one field type and one
 * counter, and both are outside this file on purpose: this stays the single
 * readable answer to "what is a voucher here".
 *
 * ### The kind says two things at once, and that is the whole shape (XIV-122)
 *
 * A voucher has a **mode** — is it applied to the whole document, or to one line
 * of it? — and a **kind** — is it a fixed amount off, or a percentage off? The
 * two are independent questions with two answers each, and both of them change
 * what a voucher *is*:
 *
 * | mode  | applied to        | what it does           |
 * | ----- | ----------------- | ---------------------- |
 * | order | the whole document | **adds its own line**  |
 * | line  | one line, chosen when applied | **reduces that line** |
 *
 * **They are one field here rather than two**, and the four constants below are
 * the four combinations written out. That is §5.5's argument arriving at a 2×2:
 * the variants *are* the field's options and the fields depend on the answer, so
 * a shape whose fields depend on two answers has to be asked one question with
 * four answers rather than two questions the engine could not relate. The
 * alternative — a `mode` choice beside a `kind` choice — is the "one shape with a
 * nullable field per kind" mistake [XIV-103] rejected, one level up: nothing
 * anywhere could say that an *order* voucher restricted to an article is
 * nonsense, because a variant can hide a field and a plain choice field cannot.
 *
 * **Which combinations exist is therefore a list rather than a rule**, and the
 * list is all four. Each of them is a promotion somebody runs: ten francs off an
 * order, a tenth off an order, ten francs off one line, a tenth off one line. What
 * does *not* exist is the fifth thing that a `mode` field beside a `kind` field
 * would have allowed — an **order voucher restricted to an article** — and it is
 * absent because the restriction is declared only on the two line variants. An
 * order voucher comes off the document as a whole; "as a whole, but only if it
 * contains a hammer" is a rule about which orders qualify, which is a different
 * feature and a much larger one.
 *
 * ### `free_article` is gone, and it was two decisions wearing one name
 *
 * [XIV-103] shipped a third kind: an article given away, at a quantity, for
 * nothing. It described neither half of the shape above once the modes existed,
 * and what it really was is now sayable in the general vocabulary: **a line
 * voucher, restricted to that article, at 100%.** So it is not renamed to
 * something more accurate, it is *derived* — which is the better outcome, because
 * a kind of its own would have meant a fourth branch in every reader for a case
 * the other three already cover.
 *
 * The one thing it stops doing is *adding* the free article as a line. That is
 * deliberate and follows from the mode: a line voucher reduces a line that is
 * there, so the article is put on the order the way every other article is and
 * the voucher takes its price off. It is one more step for whoever types the
 * order, and in exchange the free article is a line somebody chose, with a
 * quantity somebody chose, priced from the catalogue — rather than a row appearing
 * underneath at a quantity the voucher decided months earlier.
 *
 * ### It does not require the article module, and the guard that used to say so
 * has been removed on purpose
 *
 * Only the two line kinds can name an article at all, and `requires` is per module
 * rather than per variant ([XIV-23]). Declaring the requirement would mean a
 * customer who wants `GIVE-10` off a total cannot have vouchers unless they also
 * keep a catalogue — a module refused over a kind they were never going to use. So
 * it is `uses`, exactly as [XIV-103] decided, and that decision is unchanged.
 *
 * **What changed is the mechanism, and it is a deliberate loss.** [XIV-103] made
 * the article link `required: true` and called that "load-bearing twice": once for
 * its own sake, and once because {@see \Xivi\Core\Metadata\AvailableVariants}
 * hides a variant whose *required* reference points at a module the customer has
 * not installed — so a customer with no catalogue was offered two kinds and not
 * the third.
 *
 * The link is now **optional**, because that is what the feature is: a line
 * voucher may be restricted to one article, or may go on any line at all —
 * including a custom line, which has no article and is exactly where a negotiated
 * discount lands. An optional reference is not a reason to hide a kind, and
 * `AvailableVariants` correctly says nothing about it. **So that guard no longer
 * fires, and all four kinds are offered to every customer.**
 *
 * That is the right outcome rather than a regression — "ten francs off one line"
 * is a perfectly good voucher for a customer with no catalogue, and hiding it
 * would refuse them a feature that works — but the empty picker [XIV-23] was
 * avoiding is a real thing and still has to be avoided. It is, one class over:
 * {@see \Xivi\Core\Module\AvailableFields} now takes an *optional* variant-scoped
 * reference away from a customer who cannot fill it in, which is precisely the
 * case `AvailableVariants` leaves alone. The kind is offered; the restriction
 * simply is not a field they have.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VoucherModule implements ModuleProvider
{
    public const string KEY = 'voucher';

    /** What the voucher is called, and the one field that is unique. */
    public const string CODE = 'code';

    /** Which of the four kinds it is — the variant field (§5.5). */
    public const string KIND = 'kind';

    /**
     * The four, and each name says its mode first and its arithmetic second.
     *
     * The mode is first because it is the question with the larger answer: it
     * decides where the voucher may be applied and what applying it *does*,
     * where the second half only decides how the figure is worked out.
     */
    public const string ORDER_AMOUNT = 'order_amount';
    public const string ORDER_PERCENTAGE = 'order_percentage';
    public const string LINE_AMOUNT = 'line_amount';
    public const string LINE_PERCENTAGE = 'line_percentage';

    /**
     * Applied to one line, chosen when it is applied.
     *
     * A list rather than a prefix test on the key. The keys are the customer's
     * once the module is installed and a string test would be a rule hidden in a
     * verb; this is the rule, in the file that decides it.
     *
     * @var list<string>
     */
    public const array LINE_KINDS = [self::LINE_AMOUNT, self::LINE_PERCENTAGE];

    /**
     * Applied to the document as a whole.
     *
     * @var list<string>
     */
    public const array ORDER_KINDS = [self::ORDER_AMOUNT, self::ORDER_PERCENTAGE];

    /** What each kind is worth. One of these per kind, and never two at once. */
    public const string AMOUNT = 'amount';
    public const string PERCENTAGE = 'percentage';

    /** Which lines a line voucher may go on, when it is restricted at all. */
    public const string ARTICLE = 'article';

    /** When it is good for, read rather than stored (see Validity\VoucherValidity). */
    public const string VALID_FROM = 'valid_from';
    public const string VALID_UNTIL = 'valid_until';

    /** How many times it may be used. Empty means unlimited — see below. */
    public const string MAX_REDEMPTIONS = 'max_redemptions';

    /** Which module the article restriction points at. Its key, not its package. */
    private const string ARTICLE_MODULE = 'article';

    /** Whether a voucher of this kind is applied to one line rather than to the document. */
    public static function isLineKind(mixed $kind): bool
    {
        return \is_string($kind) && \in_array($kind, self::LINE_KINDS, true);
    }

    /** Whether it is applied to the document as a whole. */
    public static function isOrderKind(mixed $kind): bool
    {
        return \is_string($kind) && \in_array($kind, self::ORDER_KINDS, true);
    }

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
                // that could disagree with it, which is exactly what makes the
                // four options above the whole statement of which mode-and-kind
                // combinations exist (XIV-122).
                new FieldBlueprint(
                    key: self::KIND,
                    label: 'field.kind',
                    type: 'choice',
                    required: true,
                    filterable: true,
                    position: 20,
                    options: [
                        'choices' => [
                            self::ORDER_AMOUNT => 'kind.order_amount',
                            self::ORDER_PERCENTAGE => 'kind.order_percentage',
                            self::LINE_AMOUNT => 'kind.line_amount',
                            self::LINE_PERCENTAGE => 'kind.line_percentage',
                        ],
                        // What a book of vouchers looks like, for demo data
                        // (§5.17). A voucher off the whole order is the ordinary
                        // promotion and a line voucher is the negotiated one, so
                        // a uniform draw over four options would make half of
                        // every demo tenant's vouchers line vouchers — which is
                        // not the mix any shop has. The weighting XIV-103 chose
                        // between money off and a percentage is kept inside each
                        // mode, so this is that list crossed with the new axis
                        // rather than a new opinion about the old one.
                        'samples' => [
                            self::ORDER_AMOUNT, self::ORDER_AMOUNT, self::ORDER_AMOUNT,
                            self::ORDER_PERCENTAGE, self::ORDER_PERCENTAGE,
                            self::LINE_AMOUNT,
                            self::LINE_PERCENTAGE,
                        ],
                    ],
                ),
                // **A fixed amount off.** `currency` rather than `decimal`,
                // because it is money and the difference between the two types is
                // meaning rather than storage (§5.1, XIV-22): it prints with the
                // instance's currency symbol, and a symbol beside a percentage
                // would be wrong in a way no formatting fixes.
                //
                // The floor is zero. A voucher worth less than nothing is a
                // surcharge, which is a different feature with a different name;
                // the field refuses one rather than letting a typo become one.
                //
                // **There is no ceiling, in either mode, and that is decided
                // rather than left out** (XIV-122). What an amount is too large
                // for is the document it is used on, which this field cannot see:
                // fifty francs is a sensible voucher and a silly one depending on
                // what somebody puts it on. So the ceiling is applied where the
                // document is known — {@see \Xivi\Core\Money\DerivesTotals} floors
                // it at what the order charges in order mode and at what the line
                // charges in line mode, and never turns either negative.
                new FieldBlueprint(
                    key: self::AMOUNT,
                    label: 'field.amount',
                    type: 'currency',
                    required: true,
                    filterable: true,
                    variants: [self::ORDER_AMOUNT, self::LINE_AMOUNT],
                    position: 30,
                    options: ['min' => 0],
                ),
                // **A percentage off.** `decimal` for the same reason the article
                // module's VAT rate is one: it is measured rather than counted,
                // and it is not money.
                //
                // Capped at 100, which is the sensible maximum and is not merely
                // tidiness — a 120% voucher is a document that owes the customer
                // money, and nothing downstream is built to hand any back. Two
                // places, because 8.5% off is an ordinary offer and a scale of
                // zero would silently round it.
                //
                // **100 is also where a free article now lives** (XIV-122): a
                // line voucher restricted to one article at a hundred percent is
                // that article given away, said in the vocabulary the rest of the
                // module already had.
                new FieldBlueprint(
                    key: self::PERCENTAGE,
                    label: 'field.percentage',
                    type: 'decimal',
                    required: true,
                    filterable: true,
                    variants: [self::ORDER_PERCENTAGE, self::LINE_PERCENTAGE],
                    position: 40,
                    options: [
                        'min' => 0,
                        'max' => 100,
                        'scale' => 2,
                        // What a discount actually looks like (§5.17, XIV-24).
                        // Drawn uniformly from 0 to 100 a demo tenant is full of
                        // 63.90% vouchers, which are arithmetically fine and
                        // which nobody can sanity-check by looking (XIV-73). 100
                        // is in the list on purpose since XIV-122: a free article
                        // is the promotion nobody thinks to generate.
                        'samples' => [5, 10, 10, 15, 20, 25, 50, 100],
                    ],
                ),
                // **Which lines this voucher may go on** — and it is a
                // *restriction*, not a target (XIV-122).
                //
                // [XIV-103] had this field naming the article a voucher gave
                // away, and an earlier revision of [XIV-122] had it naming the
                // line a voucher should find. Both were the same mistake in
                // different clothes: **a custom line has no article**, and a
                // custom line is exactly where a negotiated discount lands. A
                // voucher that could only reach lines carrying a catalogue entry
                // would miss the case the feature exists for.
                //
                // So the line is chosen when the voucher is applied — by being
                // named on it — which asks nothing of the line at all, and this
                // field only narrows what may be chosen. **Named**, and the
                // voucher may only go on a line carrying that article.
                // **Empty**, and it may go on any line, custom included.
                //
                // Optional, therefore, and that is what removed [XIV-103]'s
                // `AvailableVariants` guard — see the class docblock, where the
                // loss is argued rather than noted.
                //
                // Only on the two line kinds. An order voucher restricted to an
                // article is the combination this shape does not have, and this
                // list is where it is refused (§5.5): a variant a field is not
                // declared on cannot be given one.
                new FieldBlueprint(
                    key: self::ARTICLE,
                    label: 'field.article',
                    type: 'reference',
                    filterable: true,
                    variants: [self::LINE_AMOUNT, self::LINE_PERCENTAGE],
                    position: 50,
                    options: [
                        ReferenceFieldType::MODULE => self::ARTICLE_MODULE,
                        // **A generated voucher restricts nothing** (§5.17,
                        // XIV-73). A reference otherwise samples a real record,
                        // and a demo tenant full of vouchers that may only go on
                        // one particular office chair is a demo of a feature
                        // nobody would recognise. The unrestricted voucher is
                        // the ordinary one and is what a generated book should
                        // be full of.
                        'samples' => [null],
                    ],
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
                // **A use is a document, in both modes** (XIV-122). A voucher put
                // on three lines of one order is one order carrying it and takes
                // one use — see Redemption\RedeemsVouchers, where the invariant
                // is stated and the arithmetic that keeps it is done.
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
            // field only some kinds can see, so the only subset a preset could
            // offer is "vouchers without dates" or "vouchers without limits" —
            // neither of which is a smaller version of the module, only a broken
            // one (§6.1).
            variantField: self::KIND,
            // Nothing. See the class docblock: only the line kinds can name an
            // article, and `requires` cannot say "only for this variant".
            requires: [],
            // The article restriction, and only it. Installing without articles
            // succeeds, all four kinds are offered, and the restriction is simply
            // not a field that customer has ([XIV-23], XIV-122).
            uses: [self::ARTICLE_MODULE],
        );
    }
}

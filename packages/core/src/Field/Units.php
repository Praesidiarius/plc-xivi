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

namespace Xivi\Core\Field;

use Xivi\Core\Field\Type\ChoiceFieldType;

/**
 * What a quantity is counted in, as a `choice` field's shipped options
 * (XIV-118).
 *
 * An order line saying `2.5` is a line somebody has to ask about; one saying
 * `2.5 hours` is a line they can check. The unit belongs to the **article** —
 * a desk is sold by the piece whichever order it appears on — and the line
 * takes a copy of it the same way it takes the title and the price
 * ({@see \Xivi\Core\Record\InheritedValue}).
 *
 * ### Why a shipped list rather than a managed one
 *
 * Three shapes were possible and §6.1 decides between them. A free `choice`
 * field the customer fills in themselves gives every installation its own
 * spelling of "hour / hours / Std. / h" and gives a new customer nothing at
 * all on their first day. A *managed list* — a small table of units, browsable
 * and maintained — is a screen, and a screen for seven words is a screen that
 * has to be found, learned and kept; worse, it would be the second half-answer
 * to the question [XIV-127] asks properly, which is a list a customer maintains
 * **once** and several fields across several modules point at. Units are one
 * instance of that question and not a special case of it, so building a table
 * here would be building a third of that feature and then having to unbuild it.
 *
 * What is left is the third shape and it is the one §6.1 already describes: a
 * blueprint **seeds** a customer's definitions and then stops having a say.
 * These seven values are written into that customer's `unit` field when the
 * module is installed, translated into their language on the way in like every
 * other label, and from that moment they are the customer's own options rather
 * than this class's.
 *
 * **The wholesaler who sells by the pallet can add one** (XIV-144). This said
 * the opposite for as long as the field existed — the metadata editor drew no
 * control for a choice field's options — and the gap was closed without being
 * closed unit-shaped, which was the condition: every variant field and every
 * lifecycle's status field is a `choice` field too, so a module's own field's
 * options may be added to and renamed and **never removed**. Nobody deletes
 * `confirmed` from a table cell; the seven below stay seven and an eighth is
 * the customer's to add. §5.4 has the argument.
 *
 * ### Why the values are ASCII and the labels are not
 *
 * The **value** is what every record holds and what an inherited copy compares
 * against, so it is a stable key — `m2`, never `m²`. The **label** is what a
 * document prints and is the customer's to rename. That split is the reason
 * this class exists at all rather than the list being written out three times:
 * the article's field, the order line's and the invoice line's must agree on
 * the *values* or a copied unit renders as its own key on somebody's invoice.
 * Modules may not depend on each other (§3), so the one place all three can
 * share is here.
 *
 * ### No plurals, and that is a decision
 *
 * "1 hour" and "2 hours" are different words, and the ICU catalogues in this
 * project handle exactly that — for sentences *the engine* says. A unit label
 * is not one of those. It stops being a catalogue key the moment the module is
 * installed (§6.1): what is stored in the definition is text, in the tenant's
 * language, which the customer may rename to anything at all. There is no key
 * left to look a plural form up under, and a customer's own "Palette" would
 * have none either — so pluralising the seven shipped ones would produce a
 * document where some units agreed with their number and some did not, which is
 * worse than a document where none do.
 *
 * So the label is **short, invariant and written in the form a line usually
 * needs**: the plural where the word has one, because a quantity of exactly one
 * is the exception on an invoice and `2.5 hour` is a worse error than
 * `1 hours`. The symbols — `kg`, `m`, `m²` — have no plural in any language
 * this ships in, which is most of the list already.
 *
 * ### What this is not
 *
 * **Not conversion.** Buying by the kilo and selling by the gram is a genuinely
 * larger feature — it needs a factor, a direction and a rounding rule per pair —
 * and nothing here implies it. A unit is a label beside a number.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class Units
{
    /** Sold by time. */
    public const string HOUR = 'hour';
    public const string DAY = 'day';

    /** Sold by the thing. */
    public const string PIECE = 'piece';

    /** Sold by measurement. */
    public const string KILOGRAM = 'kg';
    public const string METRE = 'm';
    public const string SQUARE_METRE = 'm2';
    public const string LITRE = 'litre';

    /**
     * The shipped set, as a field's options for a blueprint to spread into its
     * own.
     *
     * The labels are **keys in the declaring module's own catalogue**, not
     * sentences, because that is what {@see \Xivi\Core\Module\ModuleInstaller}
     * resolves them against as it writes the definitions — the same treatment
     * an order's `status.draft` gets. Every module using this therefore carries
     * a `unit:` block in its own translation files, which is the same small
     * duplication order and invoice already accept for `field.quantity` and
     * every other word they share: a module owning its own vocabulary is what
     * makes it installable on its own.
     *
     * @return array{choices: array<string, string>}
     */
    public static function shipped(): array
    {
        return [ChoiceFieldType::CHOICES => [
            self::HOUR => 'unit.hour',
            self::DAY => 'unit.day',
            self::PIECE => 'unit.piece',
            self::KILOGRAM => 'unit.kilogram',
            self::METRE => 'unit.metre',
            self::SQUARE_METRE => 'unit.square_metre',
            self::LITRE => 'unit.litre',
        ]];
    }

    /**
     * What a catalogue of demo data is sold in (§5.17, XIV-24).
     *
     * Weighted by repetition rather than by weights, which is this project's own
     * idiom ({@see \Xivi\Core\Demo\FieldSampler}): most of what anybody sells is
     * sold by the piece, a working minority by the hour, and the measured units
     * are the tail. Drawn uniformly from the seven instead, a demo catalogue
     * would sell a seventh of its office chairs by the square metre — totals
     * that are arithmetically perfect and that nobody can read.
     *
     * **`null` among them on purpose.** An article with no unit is not a defect
     * and never was: a yearly maintenance fee is sold as itself, and it is the
     * case a generated tenant most needs to contain, because it is the one every
     * page and every document has to keep rendering exactly as it did before
     * this field existed.
     *
     * @return list<?string>
     */
    public static function samples(): array
    {
        return [
            self::PIECE, self::PIECE, self::PIECE, self::PIECE,
            self::HOUR, self::HOUR,
            self::DAY,
            self::KILOGRAM, self::METRE,
            null,
        ];
    }
}

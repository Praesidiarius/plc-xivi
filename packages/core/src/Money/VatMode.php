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

namespace Xivi\Core\Money;

use Xivi\Core\Field\Type\ChoiceFieldType;

/**
 * Whether the price somebody typed already has the VAT in it (XIV-116).
 *
 * A shop in Zurich, Vienna or Munich prices a lamp at 19.95 *including* VAT,
 * because that is the number on the shelf and, for anything sold to consumers,
 * the number the law says has to be shown. Until this existed the engine could
 * only be told a net price and would add tax on top, so that shop had to divide
 * by 1.081 themselves, type 18.46, and hope the arithmetic came back to 19.95.
 * At 19.95 it does not: 18.46 plus 8.1% of 18.46 is 19.96, a rappen above the
 * shelf. That rappen is the whole reason this enum exists.
 *
 * **Two values and not three.** "No VAT" is already representable and always was
 * — it is a *rate* of nothing, which §5.9 settled when it decided the rate lives
 * on the line rather than on the document, so that a document can carry two of
 * them. A document with no rates anywhere shows no VAT table and owes no VAT in
 * either mode, and adding a third value here would have been a second way to say
 * something the rate already says, with the two free to disagree.
 *
 * **The absence of a value is {@see self::Excluded} and that is load-bearing.**
 * Every order and every invoice that existed before this field did carries
 * nothing here, and every one of them was priced net. So the mapping from "no
 * answer" to "prices exclude VAT" is not a convenience default: it is the
 * statement that this feature adds a way to say something new without changing
 * what anybody already said. {@see self::of()} is the only place that mapping is
 * written, so there is one answer to it rather than one per caller.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum VatMode: string
{
    /**
     * The price typed is net; VAT is added on top.
     *
     * What every document in every tenant is, today and before this existed, and
     * what the engine did unconditionally until XIV-116.
     */
    case Excluded = 'excluded';

    /**
     * The price typed is gross; the VAT is already inside it.
     *
     * The promise attached to this value is narrower and stronger than "compute
     * the other way round": **the gross the customer typed is the gross that
     * prints.** See {@see DerivesTotals}, which is where it is kept.
     */
    case Included = 'included';

    /**
     * Whatever a record's field held, read as a mode.
     *
     * Never throws and never returns null, because there is no caller that could
     * usefully do anything with either. A deriver runs inside a save's
     * transaction and an exception from one is a bug rather than a decision
     * (§5.9), so a value this does not recognise — a hand-edited request, an
     * import row, a field a customer re-purposed after deleting the shipped
     * options (§5.4 lets them) — has to resolve to *something*. It resolves to
     * `Excluded`, which is the reading every stored record already has and the
     * only one that cannot restate a total somebody has been sent.
     */
    public static function of(mixed $value): self
    {
        return \is_string($value) ? self::tryFrom($value) ?? self::Excluded : self::Excluded;
    }

    /**
     * The two modes as a `choice` field's options, for a blueprint to spread into
     * its own.
     *
     * The same shape {@see \Xivi\Core\Field\Units} takes and for the same reason:
     * the order module's field and the invoice module's must agree on the
     * **values**, because a document seeded from an order carries its mode
     * across (§5.12) and a value the other field has never heard of would render
     * as its own key on somebody's bill. Modules may not depend on each other
     * (§3), so core is the only place the two can share one list — and it is the
     * same place the arithmetic that reads them lives, which is the property
     * worth having.
     *
     * The labels are **keys in the declaring module's own catalogue** rather than
     * sentences, because that is what {@see \Xivi\Core\Module\ModuleInstaller}
     * resolves them against as it writes a customer's definitions. So every
     * module using this carries a `vat_mode:` block in its own translation files,
     * which is the small duplication that keeps a module installable on its own.
     *
     * @return array{choices: array<string, string>}
     */
    public static function shipped(): array
    {
        return [ChoiceFieldType::CHOICES => [
            self::Excluded->value => 'vat_mode.excluded',
            self::Included->value => 'vat_mode.included',
        ]];
    }

    /**
     * What a generated tenant's documents are priced in (§5.17, XIV-24).
     *
     * **One value, and that is the decision rather than an omission.** A choice
     * field otherwise gets a uniform draw over its options, which here would make
     * half a demo order book shelf-priced and half of it net — not a business
     * anybody runs, and a tenant in which no figure can be checked at a glance
     * because the reader has to look up which mode each document was in first.
     * A shop is a shop.
     *
     * Excluded rather than included, so that a generated tenant's totals are
     * identical to the ones the same generator produced before this field
     * existed. That is worth more than demonstrating the setting while the
     * arithmetic is new, and demonstrating it costs one dropdown on any document
     * somebody opens.
     *
     * @return list<string>
     */
    public static function samples(): array
    {
        return [self::Excluded->value];
    }
}

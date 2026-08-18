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

namespace App\Registry\Pricing;

use Xivi\Core\Money\Amount;

/**
 * One module's place on this deployment's price list (XIV-101).
 *
 * A {@see ModulePricing} and, when that decision is `priced`, the amount — held
 * together in one object because they are one fact and are wrong apart. The
 * combinations that do not mean anything are refused in the constructor rather
 * than checked by every reader: a row saying `free` with 49.00 still in the
 * amount column is a number somebody will eventually read, and the moment it can
 * exist is the moment somebody has to remember not to.
 *
 * ## The money is §5.9's money, and there is no second representation
 *
 * Stored and carried as a **decimal string at two places**, arithmetic done by
 * {@see Amount} on `brick/math`, exactly as a record's currency field is
 * (`CurrencyFieldType`, §5.9). Nothing here sees a float at any point. That is
 * not a stylistic preference: 19.90 is not representable in binary floating
 * point, and a price list is precisely the place where a hundredth of a rappen
 * short becomes a figure somebody phones about. A system that got money right in
 * every customer's documents and then priced its own modules in `float` would be
 * an embarrassing exception, and it would be one written by whoever found this
 * class easier to add a `float $price` to than to read.
 *
 * `Amount::SCALE` is the scale, so this and an invoice line round the same way,
 * from the same constant, under the same rule.
 *
 * ## Zero is refused, and that is the whole point of the class
 *
 * `priced` at 0.00 is {@see ModulePricing::Free} spelled in a way nothing can
 * distinguish from a form somebody submitted before finishing. The three states
 * the ticket asked for only stay distinguishable if the boundary between two of
 * them cannot be reached by typing a number, so it cannot be: an amount must be
 * positive, and "no money involved" has its own case and its own word.
 *
 * Negative is refused for the duller reason — a module that pays the customer to
 * install it is not a thing this deployment can have meant, and a minus sign in
 * a price field is a typo far more often than it is a policy.
 *
 * ## What this object deliberately does not carry
 *
 * **A currency.** One deployment sells in one currency, so a currency per module
 * would be a column whose only reachable state is "two modules disagree". See
 * {@see PriceCurrency} for where the answer lives and why it is not the tenant's.
 *
 * **A period.** There is no `monthly` here and no `per year`, and that is a
 * decision rather than an omission — §6.5 has the argument and what was rejected
 * with it. A one-off price is a number; a recurring one is a renewal date, a
 * billing run, a grace period and a dunning process, none of which exist.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModulePrice
{
    /**
     * @param string|null $amount a decimal string at {@see Amount::SCALE}, and
     *                            only ever non-null for {@see ModulePricing::Priced}
     */
    private function __construct(
        public ModulePricing $pricing,
        public ?string $amount,
    ) {
    }

    /**
     * Nobody has decided, which is where a module starts and is **not** free.
     *
     * The default the entity's constructor uses, so a row written for some other
     * reason — publishing a module, most likely — cannot accidentally state a
     * price nobody chose.
     */
    public static function unpriced(): self
    {
        return new self(ModulePricing::Unpriced, null);
    }

    /** Decided: no money is involved. Every module in this build is here today. */
    public static function free(): self
    {
        return new self(ModulePricing::Free, null);
    }

    /** Decided: this deployment does not sell it, whatever state the module is in. */
    public static function notForSale(): self
    {
        return new self(ModulePricing::NotForSale, null);
    }

    /**
     * Decided: it costs this much, in {@see PriceCurrency}'s currency.
     *
     * Whatever it is handed is put through {@see Amount}, which is what makes
     * "19.9" and "19.90" one stored value rather than two — so a price typed
     * into the operator screen and the same price typed into the console produce
     * the same row, and a `WHERE price_amount = …` is a question with an answer.
     *
     * @throws \InvalidArgumentException when the value is not a number, or is not
     *                                   more than nothing
     */
    public static function of(string|Amount $amount): self
    {
        $parsed = $amount instanceof Amount ? $amount : Amount::of($amount);

        if ($parsed === null) {
            throw new \InvalidArgumentException(sprintf(
                'A module price has to be a number, and "%s" is not one.',
                \is_string($amount) ? $amount : (string) $amount,
            ));
        }

        // Rounded first and *then* tested, so that 0.001 is refused as the zero
        // it is about to be stored as rather than accepted as the positive
        // number it briefly was. The alternative writes 0.00 into a row that
        // claims to be priced, which is the one combination this class exists to
        // make unreachable.
        $rounded = $parsed->rounded();

        if (!$rounded->isPositive()) {
            throw new \InvalidArgumentException(sprintf(
                'A priced module has to cost more than nothing, and "%s" does not. '
                . 'A module that costs nothing is free, which is its own decision and has its own word.',
                $rounded,
            ));
        }

        return new self(ModulePricing::Priced, (string) $rounded);
    }

    /**
     * The pair as they come out of the database, which is the one place both
     * halves arrive separately and might disagree.
     *
     * Everything else in the codebase builds one of the four named constructors
     * above; this exists for {@see \App\Registry\Entity\Module} to rebuild what
     * Doctrine hydrated, and it is where a row written by hand, by an older
     * release or by a half-finished migration is caught — loudly, on read, rather
     * than by whatever reads `amount` next and finds null.
     *
     * @throws \InvalidArgumentException when the two halves cannot both be true
     */
    public static function fromStorage(ModulePricing $pricing, ?string $amount): self
    {
        if ($pricing->needsAmount()) {
            if ($amount === null) {
                throw new \InvalidArgumentException(
                    'A module row says "priced" and carries no amount. One of the two is wrong, '
                    . 'and reading it as free would be the worse guess.',
                );
            }

            return self::of($amount);
        }

        if ($amount !== null) {
            throw new \InvalidArgumentException(sprintf(
                'A module row says "%s" and carries the amount %s. A price left behind on a '
                . 'decision that has none is a number something will read one day.',
                $pricing->value,
                $amount,
            ));
        }

        return new self($pricing, null);
    }

    /**
     * The amount as arithmetic, or null when this decision has none.
     *
     * Null for free as well as for undecided, and the caller has to tell them
     * apart by asking {@see $pricing} — which is the point. "How much is it"
     * genuinely has no numeric answer for a module nobody has priced, and
     * returning zero here would hand every careless reader the exact confusion
     * the four cases exist to prevent.
     */
    public function amount(): ?Amount
    {
        return $this->amount === null ? null : Amount::of($this->amount);
    }

    /** Whether the store may offer a module on these terms — see {@see ModulePricing::mayBeOffered()}. */
    public function mayBeOffered(): bool
    {
        return $this->pricing->mayBeOffered();
    }

    /** Whether obtaining it involves money changing hands. */
    public function costsMoney(): bool
    {
        return $this->pricing === ModulePricing::Priced;
    }

    /**
     * Whether two prices say the same thing.
     *
     * Used by the writer to leave a row alone when nothing changed, so that
     * `updated_at` keeps meaning "when the price last actually moved" rather than
     * "when somebody last pressed save" — the same reason {@see \App\Registry\Entity\Module}
     * keeps its row when a module goes back to development.
     */
    public function equals(self $other): bool
    {
        return $this->pricing === $other->pricing && $this->amount === $other->amount;
    }
}

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

use App\Registry\Entity\ModuleState;

/**
 * What this deployment has decided a module costs (XIV-101).
 *
 * The second decision the registry's `module` row carries, beside
 * {@see ModuleState}, and it is out here for the same argument [XIV-7] made
 * about that one. A blueprint is *code*: it ships identically to every
 * deployment, so a price written into `packages/invoice/` would be a price every
 * installation inherits and none of them chose. What a company charges its own
 * customers is a fact about that company's business, and a business fact goes in
 * the control plane where somebody can change it on the day it changes rather
 * than on the day there is a release (§6.2, §3).
 *
 * ## Four cases, of which three are decisions and one is the absence of one
 *
 * The ticket asked for three states and they are here — but a fourth is what
 * makes the three mean anything, and leaving it out is the mistake this enum
 * exists to make impossible.
 *
 * * {@see self::Unpriced} — **nobody has said.** Not free. This is where every
 *   module starts, and where every module that already had a row was deliberately
 *   *not* put (see the migration): "free" and "no price set yet" are different
 *   facts, and collapsing them is how a module ships at zero on the day somebody
 *   adds the column. A store that offered an unpriced module would be giving away
 *   something whose price was simply never typed, so the store does not offer it.
 * * {@see self::Free} — **install it, no money involved.** A real decision, and
 *   the one every module in this repository is in today: §6.3 says so in as many
 *   words, which is why the migration writes this onto the rows that already
 *   existed rather than leaving them undecided. Recording a fact is not the same
 *   as inventing one.
 * * {@see self::Priced} — **it costs this much**, and an amount is compulsory.
 *   {@see ModulePrice} refuses the combination without one, and refuses zero,
 *   because a module priced at nothing is {@see self::Free} said less clearly.
 * * {@see self::NotForSale} — **this deployment does not sell it.** Distinct from
 *   `development` on the other axis, and the distinction is the reason the case
 *   exists: `development` is a statement about the *module* — it is not finished,
 *   platform-wide, for everybody — while this is a statement about *this
 *   company's price list*. A finished module that one deployment bundles into a
 *   contract, another sells outright, and a third has retired is one module in
 *   three commercial situations, and moving it back to `development` to stop
 *   selling it would be telling every reader that the code is unfinished. It is
 *   not.
 *
 * ## The two axes are independent, on purpose
 *
 * Nothing here duplicates {@see ModuleState} and nothing here overrides it. A
 * module is offered in the store when the platform says it is finished **and**
 * this deployment says it is for sale; either one saying no is enough. Folding
 * the two into one enum was considered, and what that would have produced is a
 * `development | published | free | priced` list — four values answering two
 * questions, in which "published and not for sale" has no spelling at all.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum ModulePricing: string
{
    /** Nobody has decided. The default, and deliberately not free. */
    case Unpriced = 'unpriced';

    /** Decided: it costs nothing. */
    case Free = 'free';

    /** Decided: it costs {@see ModulePrice::amount()}. */
    case Priced = 'priced';

    /** Decided: this deployment does not sell it, however finished it is. */
    case NotForSale = 'not_for_sale';

    /**
     * Whether the store (XIV-6) may offer a module priced this way.
     *
     * Both undecided and not-for-sale are refused, and they are refused for
     * opposite reasons that arrive at the same place. Not-for-sale is somebody
     * saying no; unpriced is nobody having said anything, and offering it anyway
     * would be answering on their behalf with the one answer this enum exists to
     * stop being given by default.
     *
     * **A not-for-sale module is not listed at all**, rather than listed and
     * unbuyable. That was the open question in the ticket and it is settled here:
     * the store is a place to obtain modules, so a row somebody cannot act on is
     * an advertisement for something the deployment has decided not to sell, and
     * the reader's only available response to it is to ask why. A deployment that
     * genuinely wants to tease something is asking for a different feature — a
     * "coming soon" list, with a date on it — and would be badly served by this
     * one pretending to be it.
     */
    public function mayBeOffered(): bool
    {
        return match ($this) {
            self::Free, self::Priced => true,
            self::Unpriced, self::NotForSale => false,
        };
    }

    /**
     * Whether an amount belongs with this decision.
     *
     * True for exactly one case. Everything else carries no amount, and
     * {@see ModulePrice} treats an amount handed in beside one of them as a
     * mistake worth throwing over rather than a value worth quietly dropping — a
     * price left behind on a module somebody moved to "free" is a number that
     * will be read one day by something that forgot to check this field first.
     */
    public function needsAmount(): bool
    {
        return $this === self::Priced;
    }

    /** Whether anybody has made a decision about this module's price at all. */
    public function isDecided(): bool
    {
        return $this !== self::Unpriced;
    }
}

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

namespace App\Tenant\Security;

use Xivi\Core\Permission\PermissionVerb;

/**
 * The store's two verbs, which are the second permission axis (§8.4.3, XIV-6).
 *
 * §8.4 predicted this one by name and said what would trigger it: *"when
 * something wants a verb this enum has not got — the store's browse and install
 * (XIV-6) — that is the moment to add a second axis, with a real second case to
 * design it against rather than a guess."* This is that case, so here is the
 * axis.
 *
 * **Why not two more cases on ModuleAction.** Every ModuleAction is something
 * done to *a module's records*, and a grant on one names the module whose records
 * they are. Neither of these fits that sentence:
 *
 * * **Browse** is about no module whatsoever. It is about the shop window.
 * * **Install** is about a module the customer specifically does **not** have —
 *   which is the sharp end of it, because a per-module grant has nothing to
 *   attach to. Granting `install` on `invoice` would only be grantable by
 *   somebody who could already see that invoice exists, on a tenant where it does
 *   not, and would have to be granted again for every module ever shipped. The
 *   authority being described is not "may install invoice"; it is "may decide
 *   what this installation consists of", which is one grant about the business
 *   rather than a growing list about modules.
 *
 * Forcing them in would also have made {@see PermissionArea} lie, since the
 * areas' whole premise is that the *verbs* stay ModuleAction's and only the
 * subject changes. Here the verbs are what changed.
 *
 * **The subject is `@store`**, stored in `permission_grant.module_key` exactly as
 * an area is, and for the same reason: that column was never a join (§8.4). The
 * `@` prefix cannot collide with a module key, which routes require to match
 * `[a-z][a-z0-9_]*`. So a second axis costs no schema change — the table already
 * held "a subject, a verb, a scope" and had opinions about none of them.
 *
 * **Scope does not apply.** There is one store, it is nobody's own, and a module
 * the customer has not installed has no records to own. Both cases therefore
 * answer false to {@see isScopable()} and the screens offer yes or no.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum StoreAction: string implements PermissionVerb
{
    /**
     * Which subject a grant on this axis names — see the class docblock.
     *
     * A constant rather than a PermissionArea case: an area answers ModuleAction
     * verbs by definition, and this one answers none of them.
     */
    public const string SUBJECT = '@store';

    /** Seeing what this build offers, and what each module is. */
    case Browse = 'browse';

    /**
     * Adding a module to this installation, which writes definitions into the
     * customer's own database and can never be undone by the same screen —
     * there is no uninstall, and the preset chosen is permanent (§6.1, XIV-70).
     */
    case Install = 'install';

    /**
     * Asking for a module that costs money (XIV-102).
     *
     * **A third case rather than a reuse of `install`, and this is the decision
     * the ticket asked to be made rather than inherited.** The two are close
     * enough that folding them together is the obvious move and it is wrong, for
     * a reason that has nothing to do with software:
     *
     * * `install` is *"may decide what this installation consists of"* — the
     *   sentence the class docblock above already uses for it. That is an
     *   authority over the shape of the system, and the person who holds it in a
     *   twelve-person company is whoever set the thing up.
     * * `buy` is may **commit this company to a payment**. That is an authority
     *   over its money, and in the same twelve-person company it belongs to
     *   somebody else entirely — very possibly to somebody who would not know
     *   what a preset was.
     *
     * The two coincide often and are not the same, and the direction of the
     * mistake decides it: granting `install` to an office manager so they can add
     * the follow-ups module, and thereby granting them the ability to order a
     * module the owner has to pay for, is a surprise nobody consented to. The
     * reverse — a separate grant that mostly gets handed to the same person —
     * costs one more switch on a screen that already draws every other one.
     *
     * **It costs nothing today and cannot break anything today.** Every module in
     * this repository is free, so nothing is buyable and nobody's existing grants
     * change meaning; the day a deployment prices something is the day somebody
     * has to decide who may spend, which is exactly when that decision should be
     * asked for rather than assumed. And it does **not** imply `install`: a
     * purchase request installs nothing at all — an operator does that — so the
     * grant that lets somebody ask genuinely never touches their database.
     *
     * The counter-argument, written down because it is not stupid: one grant is
     * simpler, and a deployment that wants one authority can grant both to the
     * same group in one screen. True, and it is the reason this is a third case
     * on an existing axis rather than a third axis.
     */
    case Buy = 'buy';

    /**
     * Never, for any case, and the reason is the same one that makes this a
     * separate axis: no verb here names a record that already exists.
     */
    public function isScopable(): bool
    {
        return false;
    }

    /**
     * A key in the `messages` domain, as an area's is: the store is the
     * application's, not any module's, so its words are not in the `xivi`
     * catalogue that modules share.
     */
    public function labelKey(): string
    {
        return 'permission.store.' . $this->value;
    }

    /** Bootstrap Icons name, without the `bi-` prefix. */
    public function icon(): string
    {
        return match ($this) {
            self::Browse => 'shop',
            self::Install => 'download',
            // A bag rather than a card or a coin, and that is not decoration: an
            // icon of a card on a screen that takes no card is the smallest
            // version of the lie this whole ticket refuses to tell.
            self::Buy => 'bag',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }
}

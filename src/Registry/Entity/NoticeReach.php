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

namespace App\Registry\Entity;

/**
 * How far into a customer's day a notice is allowed to come (XIV-166,
 * docs/architecture/identity-and-access.md §8.16).
 *
 * **This is the column that makes a notice two channels rather than one**, and
 * everything else XIV-166 added hangs off it, so it is worth being precise about
 * what the two are.
 *
 * A notice on the dashboard is something a customer reads *when they choose to*.
 * They open the landing page, the card is above their work, they read it or they
 * put it away and get on. Nothing about the rest of their day changes, and if
 * they never open the dashboard again this week they never see it. That is a
 * perfectly good channel for *"the invoice module gained payment terms"*, and it
 * is the wrong one for *"this installation is going down at 22:00 tonight"*.
 *
 * A notice on every page is something a customer **cannot get away from**. It is
 * in the shell, above whatever they came to do, on the record they are editing
 * and the list they are filtering and the field editor they are halfway through.
 * That is not the dashboard channel with a flag set on it; it is a different
 * thing an operator is choosing to do, and choosing it should feel like a
 * decision rather than like ticking a box.
 *
 * ## Why {@see Dashboard} is the default and why that matters mechanically
 *
 * Because the loud channel has to stay rare to stay loud, and because a default
 * is what an operator in a hurry publishes. But there is a second reason, and it
 * is the one that shows up in a query plan rather than in a design argument:
 * **a notice with this reach must not cost anything on any page except the
 * dashboard.** The shell's read filters on `reach = 'every_page'` in the `WHERE`
 * clause, so a dashboard notice is not merely drawn nowhere else, it is *found*
 * nowhere else, and the second query {@see \App\Tenant\Notice\NoticeInbox} would
 * otherwise make against the customer's own database never happens. The default
 * is free, and free is a property of the clause rather than of the template.
 *
 * ## The two are alternatives, and an every-page notice is not also a card
 *
 * A page-wide banner on the dashboard *and* a card in the notices widget would be
 * the same sentence printed twice on the one page where the customer is most
 * likely to be reading carefully. So the widget draws {@see Dashboard} and the
 * shell draws {@see EveryPage}, the two sets are disjoint by construction, and
 * nothing has to remember to subtract one from the other.
 *
 * ## A third case is a column value and nothing else
 *
 * Which is why this is a short string rather than a boolean, the reasoning
 * {@see NoticeAudience} gives for the same shape. *"Every page except the ones
 * somebody is typing into"* and *"the dashboard and the module a notice is
 * about"* are both imaginable and neither has been asked for.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum NoticeReach: string
{
    /**
     * The dashboard's notices widget, and nowhere else. The default.
     *
     * What every notice written before XIV-166 was, which is why the migration
     * back-fills this value rather than inventing a state: the behaviour those
     * rows already had *is* this case, and an operator should not have to touch a
     * published notice to keep it doing what it was doing.
     */
    case Dashboard = 'dashboard';

    /** A band in the shell, above the content, on every page of the customer's installation. */
    case EveryPage = 'every_page';

    /**
     * A key in the `messages` domain, so the operator's form and the customer's
     * banner can name this without either of them holding an English string.
     */
    public function labelKey(): string
    {
        return 'notice.reach.' . $this->value;
    }

    /**
     * Whether a reader may put a notice with this reach away for themselves.
     *
     * **The decision XIV-166 was asked to make, expressed as a method on the
     * reach rather than as an `if` in a template**, because two screens and one
     * write path all have to agree about it and the disagreement would be
     * silent: a banner drawing a button that {@see \App\Tenant\Notice\NoticeInbox}
     * refuses to act on is a control that does nothing, which is worse than no
     * control at all.
     *
     * A dashboard notice is dismissible, unchanged since XIV-120: dismissing is
     * *"I have read this"*, per person, written in the customer's own database,
     * and the dashboard is exactly where a thing somebody is finished with should
     * stop appearing.
     *
     * **An every-page notice is not.** The two objections are both real and they
     * point in opposite directions, so neither can simply be accepted:
     *
     * * *A customer who dismisses a danger banner once and never sees it again
     *   has defeated the point.* True, and it is worse than it sounds, because it
     *   makes the loud channel indistinguishable from the quiet one after a single
     *   click: reach would then be *"appears on one extra page, once"*.
     * * *A notice nobody can dismiss is an operator holding a page hostage.* Also
     *   true, and this feature's whole failure mode is asymmetric power quietly
     *   exercised.
     *
     * The way out is that only one of those two parties should be paying, and it
     * is the one who chose the channel. So the reader cannot switch an every-page
     * notice off, **and the operator cannot publish one without saying when it
     * ends** ({@see \App\Registry\Notice\NoticeBoard::publish()} refuses it, in
     * the same list as every other way a notice would be a lie). The hostage
     * situation is not answered by giving the customer a button; it is answered by
     * the loud channel costing the operator a date they have to think about, and
     * by withdrawal being one press on a screen that leads with what is live.
     *
     * A consequence worth stating because it is what a reader of the schema will
     * wonder: `notice_dismissal` therefore only ever holds rows about
     * {@see Dashboard} notices. Per person stays per person there, for §8.16's
     * reason (the first colleague to open the dashboard must not be able to take a
     * maintenance window off everybody else's screen), and *per tenant* is not a
     * question the loud channel raises at all, because nothing is stored for it in
     * either database.
     */
    public function isDismissible(): bool
    {
        return $this === self::Dashboard;
    }
}

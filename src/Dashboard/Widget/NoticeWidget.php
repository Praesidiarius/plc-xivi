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

namespace App\Dashboard\Widget;

use App\Tenancy\TenantContext;
use App\Tenant\Entity\User;
use App\Tenant\Notice\NoticeInbox;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Xivi\Core\Dashboard\DashboardWidget;
use Xivi\Core\Dashboard\WidgetPanel;

/**
 * What the people running this installation have to say (XIV-120,
 * docs/architecture/identity-and-access.md §8.16).
 *
 * **Above everything**, including the follow-up list, and it is the only widget
 * that has a claim to that position: a maintenance window on Sunday is
 * information about whether the work below it can be done at all. It is also the
 * only widget somebody else writes, which is the argument against letting it sit
 * wherever the list happens to put it — though a reader who moves it, or removes
 * it, is doing something §8.3.1 deliberately allows, and that is discussed
 * below.
 *
 * ## Why a dashboard widget and not a page, a banner or an email
 *
 * **Not an email**, because that is a second channel with its own deliverability
 * problems, and this is information that belongs where the work is. §8.7 already
 * describes how much has to be true for a customer's installation to send mail
 * reliably; none of it should stand between an operator and *"we are upgrading on
 * Sunday"*.
 *
 * **Not a page**, because a page is somewhere people go and this is something
 * people should meet. **Not a banner welded to the layout**, because [XIV-66]
 * just built the seam that makes a card a class and a template, and inventing a
 * second mechanism for the same job a week later would be the thing that seam
 * exists to stop.
 *
 * ## This widget costs a query in `panel()`, which is a departure worth naming
 *
 * {@see DashboardWidget::panel()} is documented as cheap by contract: it is asked
 * of every widget on every render, before the reader's layout is applied, so a
 * widget that counts rows there charges the page for a card somebody may have
 * hidden. This one asks the registry whether anything is live for this customer,
 * which is a database read.
 *
 * It is deliberate, and the alternative is worse. "Does this apply to you" is
 * answerable for follow-ups from a per-request metadata cache and for the module
 * tiles from the navigation; for a notice it is *exactly* the question the
 * database holds. A widget that returned a panel unconditionally would put a
 * permanent, usually-empty "Announcements" card on every dashboard in every
 * installation — furniture, which is what §8.10 and the purchase screen both
 * refuse, and which would make the one week the card *does* say something the
 * week nobody notices it.
 *
 * The cost is bounded rather than hand-waved: one indexed `SELECT` on the control
 * connection, which is already open because resolving the tenant needed it, and a
 * second query against the customer's database **only when the first found
 * something** ({@see NoticeInbox}). An installation that announces nothing —
 * which is most of them, most weeks — pays one read.
 *
 * `defer` is therefore false as well: there is nothing left to fetch by the time
 * the panel exists, so deferring would buy a second round trip to render text
 * that is already in memory.
 *
 * ## What it does *not* draw, since XIV-166
 *
 * Every notice whose reach is `every_page`. Those are drawn by the shell, on the
 * dashboard as on every other page, and this widget filters them out in the
 * `WHERE` clause rather than in the template: the two sets are disjoint by
 * construction, so the one page where both surfaces exist at once cannot print
 * the same announcement twice. See {@see \App\Registry\Entity\NoticeReach}.
 *
 * The cards here are also unchanged in appearance, which is the half of §8.16's
 * *"no severity"* bullet that survives XIV-166: a dashboard notice carries a
 * priority in the database and is drawn in one weight regardless, because the
 * argument that four weights invite everything to become a warning is still true
 * of a page somebody chose to open.
 *
 * ## A reader may hide it, and that is not a hole in the channel
 *
 * §8.3.1's layout lets anybody untick any widget, this one included. That looks
 * like it undermines the point until you notice what it is next to: the same
 * person may also dismiss every notice individually, and neither of those is the
 * failure mode the ticket is against. *An operator believing they have told
 * somebody who was never shown anything* is the failure, and this widget appears
 * on every dashboard by default, unhidden, for everybody the notice was addressed
 * to. Somebody who has deliberately removed the card has been told and has
 * chosen; that is a different sentence.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsTaggedItem(priority: 20)]
final readonly class NoticeWidget implements DashboardWidget
{
    /** What a saved layout writes down when somebody keeps this card. */
    public const string KEY = 'notices';

    public function __construct(
        private Security $security,
        private TenantContext $context,
        private NoticeInbox $inbox,
    ) {
    }

    public function panel(): ?WidgetPanel
    {
        $reader = $this->security->getUser();

        // No tenant means the login page and no user means nobody to tell
        // anything to. Neither is an error; both are pages this widget has no
        // business on.
        if (!$reader instanceof User || !$this->context->hasTenant()) {
            return null;
        }

        $notices = $this->inbox->onTheDashboard($reader);

        if ($notices === []) {
            // Nothing live, nothing addressed here, or this reader has put it all
            // away. "This does not apply to you" rather than an empty card — see
            // the class docblock for why an empty card would be the worse
            // outcome for the one week it matters.
            return null;
        }

        return new WidgetPanel(
            key: self::KEY,
            template: 'dashboard/widget/notices.html.twig',
            nameKey: 'dashboard.notices',
            data: ['notices' => $notices],
        );
    }
}

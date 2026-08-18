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
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Xivi\Core\Dashboard\DashboardWidget;
use Xivi\Core\Dashboard\WidgetPanel;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * What is on the reader's own list and wants doing (XIV-81).
 *
 * The first thing on the page, above the module tiles, because navigation is
 * always available and a deadline is not. `#[AsTaggedItem]` is where that is
 * said, and it is the only place — nothing keeps an ordered list of widgets.
 *
 * **What this decides is whether the card is drawn at all**, and nothing else.
 * The card itself, the lens and the reading are
 * {@see \App\Twig\Components\DueFollowUps}'s since XIV-84. This used to hold all
 * of it and to take the lens from a `?follow_ups=today` query parameter — three
 * links and a page load, on the argument that a GET which changes what a page
 * shows is a GET. That was true and it was still the wrong trade: narrowing a
 * summary is not navigation, so it wants no history entry and no room on a URL
 * shared with every other widget's state.
 *
 * The split left here is the one worth keeping: **whether this customer does
 * follow-ups at all** is a question about the installation, which the dashboard
 * can answer before rendering anything, while *what is on this reader's list* is
 * a question that changes while they look at it.
 *
 * **The widget shows even when the lens is empty, and disappears only when no
 * module in the installation takes follow-ups at all.** Those are different
 * questions and the difference matters: "nothing due this week" is information
 * somebody wants — it is the good news the widget exists to be able to deliver —
 * while a box that vanished as soon as you narrowed it would be a control that
 * removes itself and leaves no way back to the lens that had something in it.
 *
 * **And the condition is deliberately not "any module this reader may view".**
 * That was the first version and it is wrong in exactly the case §5.18 built the
 * feature around: revoking a View grant does not unassign anybody's outstanding
 * work, so a reader can hold follow-ups on a module they can no longer open —
 * the case {@see \App\Tenant\FollowUp\AssignedFollowUp} exists for. Hiding the
 * whole widget from them would take that work off the screen entirely, which is
 * the one behaviour this ticket rules out from end to end. The cost of getting
 * it right is that somebody with no grants at all sees an empty box, and an
 * empty box that says "nothing on your list" is a true sentence.
 *
 * **The check costs no query**: the module definitions come from the metadata
 * repository's per-request cache (XIV-53). That was a nice property in XIV-81 and
 * is a requirement since XIV-66, where `panel()` is asked of every widget on every
 * render — before the reader's layout is applied, because the layout is a list of
 * keys and the keys come from here — so a widget that counted rows in this method
 * would charge the page for a card somebody had hidden.
 *
 * **It sets no `defer` flag, and that is not an oversight** (XIV-66). Its whole
 * body is a Live Component already, so deferring is `loading="defer"` on the
 * mount in its own template — one round trip for the panel's contents. Asking the
 * dashboard to defer the panel *as well* would wrap a deferred component in a
 * deferred component and buy a second round trip for nothing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsTaggedItem(priority: 10)]
final readonly class FollowUpWidget implements DashboardWidget
{
    /** What a saved layout writes down when somebody keeps this card. */
    public const string KEY = 'follow_ups';

    public function __construct(
        private Security $security,
        private TenantContext $context,
        private MetadataRepository $metadata,
    ) {
    }

    public function panel(): ?WidgetPanel
    {
        $reader = $this->security->getUser();

        // No tenant means the login page, and no user means nobody to have a
        // list. Neither is an error; both are pages this widget has no business
        // on.
        if (!$reader instanceof User || !$this->context->hasTenant()) {
            return null;
        }

        if (!$this->anythingTakesFollowUps()) {
            return null;
        }

        // No data, because the component reads its own. What the panel carries is
        // where to draw it and what to call it; everything a follow-up is stays
        // on the other side of the component's endpoint, which is what makes the
        // lens survivable without this widget being asked again.
        return new WidgetPanel(
            key: self::KEY,
            template: 'dashboard/widget/follow_ups.html.twig',
            nameKey: 'dashboard.follow_ups',
        );
    }

    /**
     * Whether this customer does follow-ups at all.
     *
     * Every module switched off (§5.18), or nothing installed, and there is
     * nothing this widget could ever say to anybody here — so it stays off a page
     * it would only be furniture on.
     *
     * Deliberately *not* "does this person have any follow-ups", which would be a
     * query and would be the wrong question besides: somebody whose list is empty
     * this week should still see the box that would have told them if it were
     * not. And deliberately not "any module this reader may view" either — see
     * the class docblock, where that version is the one that got this wrong.
     */
    private function anythingTakesFollowUps(): bool
    {
        foreach ($this->metadata->all() as $module) {
            if ($module->hasFollowUps()) {
                return true;
            }
        }

        return false;
    }
}

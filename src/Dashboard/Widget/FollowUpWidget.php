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

use App\Dashboard\DashboardWidget;
use App\Dashboard\WidgetPanel;
use App\Tenancy\TenantContext;
use App\Tenant\Entity\User;
use App\Tenant\FollowUp\FollowUpLens;
use App\Tenant\FollowUp\MyFollowUps;
use App\Tenant\Settings\DisplayTimezone;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\HttpFoundation\RequestStack;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * What is on the reader's own list and wants doing (XIV-81).
 *
 * The first thing on the page, above the module tiles, because navigation is
 * always available and a deadline is not. `#[AsTaggedItem]` is where that is
 * said, and it is the only place — nothing keeps an ordered list of widgets.
 *
 * **The lens is a query parameter, and the widget names it.** `?follow_ups=today`
 * rather than `?lens=today`, because a second widget with a control on it would
 * otherwise be one URL away from moving this one. Three links and no JavaScript:
 * a GET that changes what a page shows is a GET, and the alternative here was a
 * Live Component (XIV-33) for something that is three links and a page load.
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
 * repository's per-request cache (XIV-53).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsTaggedItem(priority: 10)]
final readonly class FollowUpWidget implements DashboardWidget
{
    /** The query parameter this widget's lens travels in. */
    public const string LENS = 'follow_ups';

    /**
     * How many lines fit before the card stops being a summary.
     *
     * XIV-80 left this here on purpose: its repository returns everything and
     * says so, because cutting a list off before the soft-delete filter runs
     * hands back short pages, and cutting it off after is a decision about how a
     * widget looks. This is the widget, and ten is what looks like a glance
     * rather than a backlog. What is cut off is counted and said out loud, which
     * is the half that stops a cap from being a lie.
     */
    private const int MOST = 10;

    public function __construct(
        private Security $security,
        private TenantContext $context,
        private RequestStack $requests,
        private MyFollowUps $mine,
        private DisplayTimezone $timezones,
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

        $request = $this->requests->getCurrentRequest();
        $lens = FollowUpLens::fromInput($request?->query->getString(self::LENS));

        $entries = $this->mine->due(
            $reader,
            $lens,
            // The zone the reader reads moments in (§8.4.4). Twig's own
            // `|date` already converts with no help from here — a listener sets
            // it per request — but the day and week boundaries are drawn in PHP
            // before anything renders, so they need the zone explicitly. That is
            // the same seam `HistorySection::of()` sits on.
            $this->timezones->of($reader),
            // Already composed: `UserLocaleListener` puts `FormattingLocale`'s
            // answer on the request, so `de` and `CH` have been joined into
            // `de_CH` by the time this reads it — and the region half is exactly
            // what decides which day the week starts on. Asking
            // `FormattingLocale` again here would compose the same string twice
            // and give a second thing the chance to disagree.
            $request?->getLocale() ?? \Locale::getDefault(),
        );

        return new WidgetPanel(
            'dashboard/widget/follow_ups.html.twig',
            [
                'lens' => $lens,
                'lenses' => FollowUpLens::cases(),
                'parameter' => self::LENS,
                'entries' => \array_slice($entries, 0, self::MOST),
                'more' => max(0, \count($entries) - self::MOST),
            ],
            'dashboard.follow_ups',
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

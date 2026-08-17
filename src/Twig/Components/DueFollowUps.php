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

namespace App\Twig\Components;

use App\Tenant\Entity\User;
use App\Tenant\FollowUp\AssignedFollowUp;
use App\Tenant\FollowUp\FollowUpLens;
use App\Tenant\FollowUp\MyFollowUps;
use App\Tenant\Settings\DisplayTimezone;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The dashboard's own list of what wants doing, and the control that narrows it
 * (XIV-81, made live by XIV-84).
 *
 * **The lens used to be a query parameter and is now component state.** Three
 * links, `?follow_ups=today`, and a full page load to answer a question the page
 * had already asked once. That was defensible while the dashboard was one widget
 * — a GET that changes what a page shows is a GET — and it stopped being the
 * moment the parameter had to survive alongside whatever the *next* widget puts
 * on the URL. Narrowing a summary is not navigation: nobody wants a history entry
 * for it, nobody links somebody else to their own follow-up list, and the address
 * bar was carrying state that belongs to one card on the page.
 *
 * So the lens lives here, the rest of the dashboard is not re-rendered to change
 * it, and the URL says what page you are on and nothing else.
 *
 * **Nothing here writes.** Same split as {@see FollowUps} on the record page and
 * for a weaker reason — there is simply nothing to write. This component reads a
 * list and decides how much of it to show, which is the case a live component is
 * least controversial for.
 *
 * **The lens is normalised through {@see FollowUpLens::fromInput()} on the way
 * in**, rather than trusted as a prop and rather than typed as the enum itself. A
 * prop is signed, so this is not about tampering; it is that `fromInput()` is
 * already where "no answer" and "an answer nobody recognises" both become the
 * default, and a second place deciding that is a second place to get it wrong.
 * The default itself stays {@see FollowUpLens::default()}'s to choose.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsLiveComponent('DueFollowUps')]
final class DueFollowUps
{
    use DefaultActionTrait;

    /**
     * Which lens has been picked, as its backed value.
     *
     * A string rather than the enum, so that the default can be "nothing was
     * said" and {@see getLens()} can answer that with `FollowUpLens::default()`.
     * Typing it as the enum would need a default written out here, which is the
     * one thing that class asks not to be copied.
     *
     * **Named `selected` rather than `lens`, and that is not cosmetic.** The
     * hydrator resolves a prop through a matching getter, so a prop called `lens`
     * beside a `getLens()` returning the enum is a prop it reads as the wrong
     * type and refuses to dehydrate. The state and the answer computed from it
     * are two things and now have two names.
     *
     * Not `writable`, because the only thing that should ever set it is the
     * action below — which normalises. A writable prop would let the browser put
     * an unrecognised string in the component's state, where it would sit until
     * something read it.
     */
    #[LiveProp]
    public string $selected = '';

    /**
     * How many lines fit before the card stops being a summary.
     *
     * Moved here with the rest of the reading. XIV-80 left the cap to the widget
     * on purpose — its repository returns everything and says so, because cutting
     * a list off before the soft-delete filter runs hands back short pages — and
     * ten is what looks like a glance rather than a backlog. What is cut off is
     * counted and said out loud, which is the half that stops a cap from being a
     * lie.
     */
    private const int MOST = 10;

    /**
     * This reader's due follow-ups, read once however many times the template
     * asks.
     *
     * The template asks twice — the rows, and how many did not fit. A component
     * is built, rendered and thrown away inside one request, so this is only
     * "twice in one render is once" rather than a cache with a lifetime.
     *
     * @var list<AssignedFollowUp>|null
     */
    private ?array $due = null;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requests,
        private readonly MyFollowUps $mine,
        private readonly DisplayTimezone $timezones,
    ) {
    }

    /** The lens actually in force, which is the default until somebody picks one. */
    public function getLens(): FollowUpLens
    {
        return FollowUpLens::fromInput($this->selected);
    }

    /** @return list<FollowUpLens> */
    public function getLenses(): array
    {
        return FollowUpLens::cases();
    }

    /** @return list<AssignedFollowUp> */
    public function getEntries(): array
    {
        return \array_slice($this->all(), 0, self::MOST);
    }

    /** How many did not fit, so the cap can say so. */
    public function getMore(): int
    {
        return max(0, \count($this->all()) - self::MOST);
    }

    /**
     * Narrow, or widen, without leaving the page.
     *
     * The argument goes through `fromInput()` rather than being stored as it
     * arrived, so the component's state is only ever one of the three cases —
     * a value nobody recognises selects the default rather than showing an empty
     * list with no lens highlighted, which is what storing it raw would draw.
     */
    #[LiveAction]
    public function show(#[LiveArg] string $lens): void
    {
        $this->selected = FollowUpLens::fromInput($lens)->value;
    }

    /**
     * @return list<AssignedFollowUp>
     */
    private function all(): array
    {
        $reader = $this->security->getUser();

        // The widget that mounts this has already established there is a reader
        // and a tenant. This is the component's own endpoint answering the same
        // question, because a component is reachable whether or not that widget
        // drew it.
        if (!$reader instanceof User) {
            return $this->due = [];
        }

        $request = $this->requests->getCurrentRequest();

        return $this->due ??= $this->mine->due(
            $reader,
            $this->getLens(),
            // The zone the reader reads moments in (§8.4.4). Twig's own `|date`
            // already converts with no help from here — a listener sets it per
            // request — but the day and week boundaries are drawn in PHP before
            // anything renders, so they need the zone explicitly. That is the
            // same seam `HistorySection::of()` sits on.
            $this->timezones->of($reader),
            // Already composed: `UserLocaleListener` puts `FormattingLocale`'s
            // answer on the request, so `de` and `CH` have been joined into
            // `de_CH` by the time this reads it — and the region half is exactly
            // what decides which day the week starts on. Asking
            // `FormattingLocale` again here would compose the same string twice
            // and give a second thing the chance to disagree.
            //
            // It holds on the live endpoint too: that is an ordinary request
            // through the same firewall and the same listener.
            $request?->getLocale() ?? \Locale::getDefault(),
        );
    }
}

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

use App\Dashboard\Dashboard;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Xivi\Core\Dashboard\WidgetPanel;

/**
 * One widget's card, fetched in a request of its own (XIV-66).
 *
 * **Why the dashboard owns the deferral rather than each widget.** `loading=
 * "defer"` is an attribute on a Live Component mount, so the obvious design is for
 * every widget that wants it to *be* a Live Component — which works fine for the
 * follow-up card, because that one already is one, and does not work at all for a
 * widget shipped by a module: `symfony/ux-live-component` is not a dependency of
 * `packages/invoice` and adding one to a package would be adding one to the
 * lockfile. So this is the generic mount, the dashboard applies it to any panel
 * that asks, and a module ships a widget class and a plain Twig template with no
 * front-end dependency of any kind. It is also the right place on its own merits:
 * XIV-84 drew the line at *the dashboard decides whether a card exists, the card
 * decides what is in it*, and when a card is fetched is a question about the page.
 *
 * **What the deferral actually saves, since a second request is not free.**
 * `Dashboard::available()` asks every widget for its panel on the first request —
 * it has to, because the reader's saved layout is a list of keys and the keys come
 * from the panels — and that call is cheap by contract: it decides whether a card
 * applies and nothing more. The *contents* are a promise
 * ({@see WidgetPanel::values()}), resolved by the template that draws the panel.
 * A deferred panel is not drawn on the first request, so its promise is never
 * called there, and its queries happen on this endpoint instead. The dashboard
 * therefore costs what its cheapest widgets cost, and a slow widget costs its own
 * tile.
 *
 * **The key is a prop and props are signed**, so what arrives here is what the
 * page put there. That is worth almost nothing as a security property and is
 * worth saying anyway: even a forged key would be resolved through
 * {@see Dashboard::panelFor()}, which asks the widget itself, and the widget
 * answers under the reader's own grants exactly as it does on the page. There is
 * no key that turns into a panel somebody was not already going to be shown.
 *
 * **A key that resolves to nothing renders nothing**, rather than throwing. This
 * endpoint is a second request, so the world may have moved between the page and
 * the fetch — a module uninstalled, a grant revoked — and the honest answer to
 * "that card no longer applies to you" is an empty card, which is the same
 * degradation a saved layout naming a dead widget gets.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsLiveComponent('DashboardPanel')]
final class DashboardPanel
{
    use DefaultActionTrait;

    /**
     * Which widget this card is.
     *
     * Not writable: nothing in the browser has any business changing which panel
     * a mounted card is showing. The dashboard decides that when it renders the
     * page, and a card that could be re-pointed would be a card whose identity is
     * negotiable.
     */
    #[LiveProp]
    public string $widget = '';

    public function __construct(private readonly Dashboard $dashboard)
    {
    }

    /** The panel to draw, or null if this widget no longer applies to this reader. */
    public function getPanel(): ?WidgetPanel
    {
        return $this->dashboard->panelFor($this->widget);
    }
}

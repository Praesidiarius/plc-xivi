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

namespace Xivi\Core\Dashboard;

/**
 * A widget's answer: what it is called, which template draws it, and what that
 * template is given (XIV-81, extended by XIV-66).
 *
 * **A template name and an array rather than a rendered string**, which is the
 * oldest decision in this class and still the load-bearing one. A widget that
 * returned HTML would be a service building markup, and every one of them would
 * then need the translator, the router and the escaper injected to do it — the
 * reasons Twig exists, rebuilt once per widget. Handing back a name and its data
 * keeps the rendering in the templating layer and keeps a widget a thing that
 * answers questions.
 *
 * **The name is a key rather than a sentence**, for the reason a permission
 * action hands out a label key rather than a label: a value object holding an
 * English string is a value object that has quietly become untranslatable. The
 * dashboard renders it through the translator, so a widget never has to hold one.
 *
 * ## What XIV-66 added, and why each of the four is here
 *
 * **A key**, because a saved layout is a list of them. Once a person may choose
 * which widgets they see and in what order, that choice has to be written down as
 * something stable, and the only stable thing on offer is a name the widget gives
 * itself. It lives on the panel rather than on the widget interface deliberately:
 * a widget that returns null produces no key, so "this does not apply to you" and
 * "this is not on offer to you" are the same fact expressed once. A saved layout
 * naming a key nothing produced — an uninstalled module, a deleted class, a
 * renamed widget — is dropped rather than resolved, which is the degradation §7.6
 * already chose for a stale `reference` and for the same reason: the missing thing
 * is a runtime fact about one customer, not a broken installation.
 *
 * **A domain**, because a module ships its own catalogue. `messages` is the
 * application's; `invoice` is the invoice module's. Without this a module widget
 * could only be named by adding a key to the application's file, which is the
 * dependency the whole ticket exists to remove.
 *
 * **A heading flag rather than a nullable title.** This used to be one nullable
 * `titleKey`, where null meant "draws no heading" — and that conflated two
 * questions the moment a picker existed, because a widget with no heading still
 * has to be nameable in a list of widgets somebody is choosing from. The module
 * tiles are exactly that case: a grid of labelled cards under the company name
 * needs no second label saying "Modules", and a checkbox offering it needs one.
 *
 * **A `defer` flag**, which is the widget saying its content is worth fetching
 * separately. See {@see self::$defer}.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class WidgetPanel
{
    /**
     * What the template is rendered with, or a promise of it.
     *
     * **The promise is the point** (XIV-66). `panel()` is asked of every widget on
     * every dashboard render — it has to be, because the reader's layout is a list
     * of keys and the keys come from there — and only a few of those panels are
     * drawn. A widget that built its rows eagerly would pay for a card the reader
     * hid, and a *deferred* widget would pay for it twice: once on the page it is
     * not drawn on and again on the request that draws it. Handing back something
     * that has not run yet is what makes `loading="defer"` save the work rather
     * than merely move it.
     *
     * An array stays an array. Most panels have nothing to compute — the module
     * tiles read a per-request cache, the follow-up card carries no data at all —
     * and wrapping those in a closure would be ceremony around an empty list.
     *
     * @var array<string, mixed>|(\Closure(): array<string, mixed>)
     */
    private array|\Closure $data;

    /**
     * @param string                                                  $key      what a saved layout writes down: a stable
     *                                                                          identifier this widget gives itself,
     *                                                                          namespaced by its module where it has one
     *                                                                          (`invoice.unpaid`)
     * @param string                                                  $template the Twig template that draws this panel
     * @param string                                                  $nameKey  a translation key naming this widget — the
     *                                                                          heading above it, and the label beside its
     *                                                                          checkbox in the picker
     * @param array<string, mixed>|(\Closure(): array<string, mixed>) $data     the panel's whole world, since a widget's
     *                                                                          template is included rather than extended
     *                                                                          and inherits nothing but the globals every
     *                                                                          page has. A closure is resolved only when
     *                                                                          the panel is drawn
     * @param bool                                                    $heading  whether to draw {@see $nameKey} above the
     *                                                                          panel. False for a panel that names itself
     * @param string                                                  $domain   which catalogue {@see $nameKey} is in —
     *                                                                          `messages` for the application's own, a
     *                                                                          module's key for a module's
     * @param bool                                                    $defer    whether this panel is fetched in a request
     *                                                                          of its own
     */
    public function __construct(
        public string $key,
        public string $template,
        public string $nameKey,
        array|\Closure $data = [],
        public bool $heading = true,
        public string $domain = 'messages',
        /**
         * Whether the page should draw this panel separately rather than inline.
         *
         * **Eight widgets each running their own counts, on the first page every
         * user loads after signing in, is the worst place in the product for a
         * page whose cost is the sum of its parts** — the same shape XIV-53 fixed
         * for metadata, arriving from a different direction. Fetching a panel in
         * a request of its own makes a slow widget cost its own tile instead of
         * the whole dashboard, and the reader gets navigation immediately rather
         * than when the slowest card has finished counting.
         *
         * **Off by default, because deferring is not free.** It is a second HTTP
         * round trip and a visible flash where a card used to be, which is a bad
         * trade for a panel that reads a per-request cache — the module tiles are
         * navigation, and navigation that arrives late is worse than navigation
         * that arrives with the page. A widget that touches the database says
         * true here; one that does not, does not.
         */
        public bool $defer = false,
    ) {
        $this->data = $data;
    }

    /**
     * The panel's data, resolving the promise if there is one.
     *
     * Called once, by the template that draws the panel. It is deliberately not
     * memoised: a `readonly` value object cannot cache and should not want to,
     * and a second caller wanting the same rows twice would be a second caller
     * that should have been handed them.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return \is_array($this->data) ? $this->data : ($this->data)();
    }
}

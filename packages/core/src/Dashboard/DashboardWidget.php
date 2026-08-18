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

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One thing the landing page has to say to whoever just signed in (XIV-81),
 * declarable by a module since XIV-66.
 *
 * **Why there is a concept here at all.** The dashboard was a placeholder whose
 * own docblock said it "gets replaced by something real once there are modules to
 * show", and the first real thing to show up was a list of due follow-ups. It
 * could have been an `{% if %}` in `dashboard/index.html.twig` holding a variable
 * the controller passed down — and that is precisely the shape that makes the
 * *second* widget a rewrite rather than a file. So the seam was cut while there
 * was one implementation to cut it around and the answer could still be small.
 *
 * **And it is deliberately small.** This is not a plugin framework: there is no
 * registry to configure and no layout engine. A widget is a service that decides
 * whether it has anything to say and, if so, names a template and hands it data.
 * Discovery and ordering are Symfony's tagged-iterator machinery doing what it
 * already does, which is the reach-for-the-component rule applied to a problem
 * that would otherwise have grown a `dashboard.yaml`.
 *
 * **Widgets are found by tag and ordered by priority.** {@see AutoconfigureTag}
 * below means implementing this interface is the whole of registering one, and
 * {@see AsTaggedItem} on the implementation is how it says where it wants to sit
 * — a higher priority is nearer the top of the page. Nothing keeps a list of
 * widgets, so nothing can disagree with the classes that exist.
 *
 * **A widget with nothing to say returns null, and that is not an error.** The
 * dashboard is one page shared by an administrator with every module and a
 * newcomer with none, so "this does not apply to you" is the ordinary case rather
 * than the exception. Returning null is how a widget stays out of the way without
 * the page having to know what it is for; deciding *for* the reader in the
 * template would be the special-casing this interface exists to stop.
 *
 * ## Why this moved into core (XIV-66)
 *
 * It lived in `App\Dashboard` while both implementations were the application's
 * own, and that was the honest place for it right up until a module wanted one.
 * The obstruction was structural rather than stylistic: deptrac's `App` layer is
 * every class under `App\`, a module package may depend on `Xivi\Core\` and on
 * nothing else, so an interface in the application is an interface
 * `packages/invoice` is forbidden to implement. Unpaid invoices is probably the
 * single most useful thing this product can put on a landing page, and it was
 * unreachable from the package that knows what an invoice is.
 *
 * So core declares the seam and the application collects what it finds, exactly
 * as {@see \Xivi\Core\Record\ValueDeriver}, {@see \Xivi\Core\Lifecycle\Lifecycle}
 * and {@see \Xivi\Core\Seed\Seed} already work. **A seam in core does not mean
 * everything using it moves**: the module tiles and the follow-up list are
 * application concerns — one reads the navigation, the other reads a table that
 * lives in `src/` — and both stayed exactly where they were, implementing this
 * from up there. Core gains no knowledge of what a user, a tenant or a module
 * package is by owning the interface; it gains a tag name.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AutoconfigureTag(DashboardWidget::TAG)]
interface DashboardWidget
{
    /**
     * The tag the application collects on.
     *
     * A constant rather than a string repeated in two packages, because the two
     * ends of this are now in different composer packages and a typo would show
     * up as a widget that simply never appears — the failure mode with no error
     * message attached to it.
     */
    public const string TAG = 'xivi.dashboard_widget';

    /**
     * What to draw for the person reading this request, or null to draw nothing.
     *
     * **No arguments, on purpose.** The obvious signature takes the request, or
     * the user, or both — and every one of those is a guess about what the
     * widgets after this one will need. A widget that wants the query string
     * injects `RequestStack`; one that wants the reader injects `Security`; and
     * the interface does not have to be reopened when the third one wants
     * something neither of them did. A module widget cannot inject either of
     * those application services and does not need to: it asks core's
     * {@see \Xivi\Core\Permission\RecordAccessProvider} what this reader may see,
     * which is the same question with the application's answer already in it.
     *
     * **Cheap, and that is a contract rather than advice** (XIV-66). This is
     * asked of *every* widget on every dashboard render — before a layout is
     * applied, because the layout is a list of keys and the keys come from here —
     * so a widget that counts rows in this method has put its cost on the page
     * whether or not the reader kept it. Deciding whether the card exists is what
     * belongs here; what is *in* the card belongs behind
     * {@see WidgetPanel::values()}, which is a promise the renderer resolves only
     * for a panel it is actually drawing. That is XIV-84's split — the dashboard
     * decides whether a card exists, the card decides what is in it — restated
     * one level down so that deferred loading can be worth anything.
     */
    public function panel(): ?WidgetPanel;
}

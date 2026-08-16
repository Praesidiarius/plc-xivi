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

namespace App\Dashboard;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One thing the landing page has to say to whoever just signed in (XIV-81).
 *
 * **Why there is a concept here at all.** The dashboard was a placeholder whose
 * own docblock said it "gets replaced by something real once there are modules to
 * show", and the first real thing to show up was a list of due follow-ups. It
 * could have been an `{% if %}` in `dashboard/index.html.twig` holding a variable
 * the controller passed down — and that is precisely the shape that makes the
 * *second* widget a rewrite rather than a file. So the seam is cut now, while
 * there is one implementation to cut it around and the answer can still be small.
 *
 * **And it is deliberately small.** This is not a plugin framework: there is no
 * registry to configure, no per-user arrangement, no layout engine and no
 * persistence. A widget is a service that decides whether it has anything to say
 * and, if so, names a template and hands it data. Everything else — order,
 * discovery, wiring — is Symfony's tagged-iterator machinery doing what it
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
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AutoconfigureTag('app.dashboard_widget')]
interface DashboardWidget
{
    /**
     * What to draw for the person reading this request, or null to draw nothing.
     *
     * **No arguments, on purpose.** The obvious signature takes the request, or
     * the user, or both — and every one of those is a guess about what the
     * widgets after this one will need. A widget that wants the query string
     * injects `RequestStack`; one that wants the reader injects `Security`; and
     * the interface does not have to be reopened when the third one wants
     * something neither of them did.
     */
    public function panel(): ?WidgetPanel;
}

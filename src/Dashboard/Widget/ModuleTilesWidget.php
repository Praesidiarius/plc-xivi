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

use App\Twig\AppChrome;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Xivi\Core\Dashboard\DashboardWidget;
use Xivi\Core\Dashboard\WidgetPanel;

/**
 * A card per module the reader may open, and the two empty states when there are
 * none (XIV-81).
 *
 * **This is the landing page as it already was, made into a widget**, and that
 * conversion is most of what makes the widget concept real. If follow-ups had
 * been bolted into `dashboard/index.html.twig` beside a hard-coded tile grid, the
 * "concept" would have been one interface with one implementation and a template
 * that still knew the answer — which is a special case wearing an abstraction.
 * Two implementations is the smallest number that proves the seam is in the right
 * place, and this is the second one for free.
 *
 * **It is never null**, unlike the follow-up widget beside it, and the reason is
 * the empty states: "nothing is installed" and "nothing is yours" are the two
 * things a dashboard with no modules has to say, and a widget that hid itself
 * when it had no tiles would leave a signed-in person looking at a blank page
 * with no idea why. A widget's null is "this does not apply to you"; not having
 * any modules is emphatically something that applies to you.
 *
 * **The modules come from {@see AppChrome} rather than being resolved again.**
 * Which modules a person has is already a question with one answer and one place
 * that gives it — filtered by `list`, because that is the permission the tile
 * links to — and asking the metadata repository and the permission resolver a
 * second time here would be a second answer to keep in step. It lives under
 * `src/Twig` because it is also a Twig global; that is where it happens to sit
 * rather than what it is.
 *
 * ## Why this stayed in the application when the interface left (XIV-66)
 *
 * The seam is core's now, so that `packages/invoice` can declare a widget. This
 * one did not follow it and could not: it reads the application's own navigation,
 * which is assembled from the tenant's modules crossed with one person's grants,
 * and neither of those is a thing core knows about. **A seam in core does not
 * mean everything using it moves** — the modules that own record-shaped questions
 * ship widgets from down there, and the two widgets that are about the
 * application itself keep implementing the interface from up here.
 *
 * **It draws inline rather than deferring**, which is the one thing XIV-66
 * changed about it. `panel()` costs a per-request cache read and a permission
 * resolution that every page in the application has already done, so a second
 * round trip would buy nothing and cost a visible flash where the navigation
 * belongs — and navigation arriving late is worse than navigation arriving with
 * the page.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsTaggedItem(priority: 0)]
final readonly class ModuleTilesWidget implements DashboardWidget
{
    /** What a saved layout writes down when somebody keeps this card. */
    public const string KEY = 'modules';

    public function __construct(private AppChrome $chrome)
    {
    }

    /**
     * Narrowed to never-null, which is the type saying what the docblock above
     * says: this widget has no case in which it stays off the page.
     */
    public function panel(): WidgetPanel
    {
        return new WidgetPanel(
            key: self::KEY,
            template: 'dashboard/widget/modules.html.twig',
            // Named even though it draws no heading, and XIV-66 is what forced
            // the two apart: a grid of labelled cards under the company name
            // needs no second label saying "Modules", and a checkbox offering it
            // in a picker needs exactly that word. One nullable title could only
            // answer one of those questions.
            nameKey: 'dashboard.widget.modules',
            data: [
                'modules' => $this->chrome->getModules(),
                // The two empty states are told apart by this and by nothing
                // else: an administrator with no modules can act, and somebody
                // with no grants cannot, so telling the second one to run a
                // console command against their employer's database would be the
                // wrong sentence in every respect.
                'anyInstalled' => $this->chrome->isAnyModuleInstalled(),
                'store' => $this->chrome->getStore(),
            ],
            heading: false,
        );
    }
}

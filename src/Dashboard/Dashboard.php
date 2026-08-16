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

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The landing page, as the list of things worth putting on it (XIV-81).
 *
 * Three lines of work and a class of its own, and the reason is that the
 * alternative is those three lines in a controller — where the *next* thing to
 * want a dashboard, a printed summary or an API for it, would have to reach
 * through a controller to get one. What a dashboard consists of is a question
 * with an answer, and a controller is a bad place to keep answers.
 *
 * **Ordering is the tag's priority and not a property of the panel.** Where a
 * widget sits relative to the others is not something a widget can know — it is
 * a fact about the page, and the page is assembled here. Symfony's tagged
 * iterator already sorts by priority, so this holds no comparator and no sort;
 * {@see AutowireIterator} hands them over in the order they belong in.
 *
 * **A widget that throws would take the whole page down, and is allowed to.**
 * The tempting move is a try/catch per widget so that one broken box does not
 * cost the landing page — and it is refused, because a dashboard that silently
 * omits a panel is a dashboard nobody can trust to be complete, which is worse
 * than one that is visibly broken. The follow-up widget in particular is a list
 * of work somebody was given; quietly dropping it is exactly the failure the
 * ticket spends its lower-bound argument on.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Dashboard
{
    /** @param iterable<DashboardWidget> $widgets in the order they should be drawn */
    public function __construct(
        #[AutowireIterator('app.dashboard_widget')]
        private iterable $widgets,
    ) {
    }

    /**
     * Everything this reader should see, top to bottom.
     *
     * The widgets that answered null are gone rather than present-and-empty, so a
     * template never has to ask whether a panel is worth drawing — which is the
     * whole point of letting a widget decide that about itself.
     *
     * @return list<WidgetPanel>
     */
    public function panels(): array
    {
        $panels = [];

        foreach ($this->widgets as $widget) {
            $panel = $widget->panel();

            if ($panel !== null) {
                $panels[] = $panel;
            }
        }

        return $panels;
    }
}

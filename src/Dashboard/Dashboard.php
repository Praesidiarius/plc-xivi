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

use App\Tenancy\TenantContext;
use App\Tenant\Entity\User;
use App\Tenant\Settings\DashboardLayout;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Xivi\Core\Dashboard\DashboardWidget;
use Xivi\Core\Dashboard\WidgetPanel;

/**
 * The landing page, as the list of things worth putting on it (XIV-81), arranged
 * the way its reader asked for (XIV-66).
 *
 * A class of its own rather than three lines in a controller, because the *next*
 * thing to want a dashboard — a printed summary, an API for it — would have to
 * reach through a controller to get one. What a dashboard consists of is a
 * question with an answer, and a controller is a bad place to keep answers.
 *
 * **The application collects; core declares the seam.** {@see DashboardWidget}
 * moved into `packages/core` so that a module could ship one, and this is the
 * other end of that move: the ordering, the reader's layout and the decision that
 * a saved key naming nothing is dropped all live up here, where a user and a
 * tenant are concepts. Core gained a tag name and no knowledge.
 *
 * **Ordering has two sources and they do not compete.** The tag's priority is
 * what the *code* thinks — a fact about the page, which is why it is not a
 * property of the panel — and Symfony's tagged iterator has already applied it by
 * the time {@see AutowireIterator} hands them over, so this holds no comparator
 * and no sort. A reader's own layout overrides it wholesale, because a person who
 * has arranged their page has said something more specific than the priority
 * numbers did.
 *
 * **A widget that throws would take the whole page down, and is allowed to.** The
 * tempting move is a try/catch per widget so that one broken box does not cost the
 * landing page — and it is refused, because a dashboard that silently omits a
 * panel is a dashboard nobody can trust to be complete, which is worse than one
 * that is visibly broken. The follow-up widget in particular is a list of work
 * somebody was given; quietly dropping it is exactly the failure XIV-81 spends its
 * lower-bound argument on.
 *
 * **A saved key that no widget answers to is dropped, and that is not the same
 * thing.** It is not an error at all: a layout is data referring to code, so it
 * outlives an uninstalled module, a renamed widget and a deleted class, and every
 * one of those is a runtime fact about one customer rather than a broken
 * installation — the same treatment and the same argument as a stale `reference`
 * (§7.6). Failing there would mean a module somebody uninstalled taking the
 * landing page down for everybody who had ever ticked its box.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Dashboard
{
    /** @param iterable<DashboardWidget> $widgets in the order the code declares */
    public function __construct(
        #[AutowireIterator(DashboardWidget::TAG)]
        private iterable $widgets,
        private DashboardLayout $layout,
        private Security $security,
        private TenantContext $context,
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
        $available = $this->available();
        $chosen = $this->layout->of($this->reader());

        // Nobody has chosen, at either level: the code's own order, which is what
        // this page did before any of it was arrangeable.
        if ($chosen === null) {
            return array_values($available);
        }

        $panels = [];

        foreach ($chosen as $key) {
            // The degradation, and the whole of it. A key nothing answers to is
            // simply not drawn — see the class docblock for why that is the
            // correct outcome rather than a swallowed error.
            if (isset($available[$key])) {
                $panels[] = $available[$key];
            }
        }

        return $panels;
    }

    /**
     * Every panel this reader could have, keyed, in the order the code declares.
     *
     * This is what the picker draws its list from, and it is deliberately the
     * same call the page makes: **a widget that is on offer is exactly a widget
     * that would draw something**, so a module the customer does not have and a
     * module this person has no grant on are both absent without either question
     * being asked twice. §6.2's rule — a widget for an uninstalled module is not
     * offered — is therefore not enforced here at all; it falls out of the widget
     * returning null, which is the only place that fact is known.
     *
     * @return array<string, WidgetPanel>
     */
    public function available(): array
    {
        // No tenant is the login page and every console command, and no user is
        // nobody to have a dashboard. Asked here rather than in each widget
        // because it is a fact about the *page*: a module widget cannot ask —
        // `TenantContext` is the application's and a package may not see it — and
        // the metadata repository it would otherwise reach for needs a resolved
        // tenant to answer at all.
        if (!$this->context->hasTenant() || !$this->reader() instanceof User) {
            return [];
        }

        $panels = [];

        foreach ($this->widgets as $widget) {
            $panel = $widget->panel();

            if ($panel === null) {
                continue;
            }

            // Two widgets claiming one key would mean one of them silently
            // vanishing from every picker and every saved layout, which is the
            // kind of fault that is found months later by somebody wondering
            // where their card went. Loud, for the same reason a throwing widget
            // is allowed to take the page down.
            if (isset($panels[$panel->key])) {
                throw new \LogicException(sprintf(
                    'Two dashboard widgets claim the key "%s"; a key is what a saved layout writes down, so it has to name one of them.',
                    $panel->key,
                ));
            }

            $panels[$panel->key] = $panel;
        }

        return $panels;
    }

    /**
     * One panel by key, for the request that draws a deferred one.
     *
     * Null for a key nothing answers to, which is the same degradation
     * {@see self::panels()} applies and reached from the other side: the
     * component that fetches a panel is an ordinary request through the same
     * firewall, so a widget that has stopped applying between the page loading
     * and the panel arriving answers nothing rather than throwing.
     */
    public function panelFor(string $key): ?WidgetPanel
    {
        return $this->available()[$key] ?? null;
    }

    private function reader(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}

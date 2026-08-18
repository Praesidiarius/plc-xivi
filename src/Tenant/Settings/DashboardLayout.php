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

namespace App\Tenant\Settings;

use App\Tenancy\TenantContext;
use App\Tenant\Entity\TenantProfile;
use App\Tenant\Entity\User;
use App\Tenant\Repository\TenantProfileRepository;

/**
 * Which widgets a person keeps on their landing page, and in what order
 * (XIV-66).
 *
 * **The fourth setting of the shape §8.4.2 established**, and deliberately the
 * fourth *instance* rather than a fourth variation: {@see FormattingLocale} and
 * {@see DisplayTimezone} settled this already, and the thing worth avoiding here
 * was inventing a new way to say the same sentence. Same two classes injected,
 * same `of()` for the whole chain and `fallbackFor()` for the part of it a
 * settings page has to be able to name out loud, same treatment of a console
 * command that has no tenant, and the same argument for why null is stored rather
 * than a copy of the answer it currently resolves to.
 *
 * The chain, and each link is a different promise:
 *
 * 1. **The person**, if they said. Somebody who does not want what everybody
 *    else is looking at.
 * 2. **The installation** (§8.6), whose landing page an administrator decided.
 * 3. **Nothing**, which means every widget that applies to this reader in the
 *    order the code declares — which is what every installation had before this
 *    existed, and is what the bottom of one of these chains always is.
 *
 * **What differs from the three above, and it is only one thing.** A language, a
 * region and a zone are single values where "unset" and "empty" cannot be told
 * apart, because there is no such thing as an empty timezone. A layout can be
 * genuinely empty: somebody may untick every box, and that is a preference rather
 * than an absence. So null and `[]` are two answers here, and the whole class is
 * careful never to fold one into the other. It is also why {@see self::of()}
 * returns `?array` rather than an array — a caller has to be able to tell "show
 * everything" from "show nothing", and a caller that cannot is a caller that will
 * eventually hand somebody the wrong page.
 *
 * **This resolves an order, never a permission.** The keys it hands back are a
 * *preference*, and the widgets themselves decide what the reader may be told —
 * every one of them under the reader's own grants (§8.4). A layout naming a
 * widget that would show nothing to this person is therefore harmless: the widget
 * returns null, its key never appears, and the layout entry falls through the
 * same gap a deleted widget's does. The one thing this must not become is a place
 * where visibility is decided, because a preference somebody can edit is not a
 * security boundary.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DashboardLayout
{
    public function __construct(
        private TenantContext $context,
        private TenantProfileRepository $profiles,
    ) {
    }

    /**
     * The layout this person's dashboard is drawn in — the whole chain, and the
     * method the page calls.
     *
     * Null is "nobody has chosen", which the dashboard reads as every applicable
     * widget in tag-priority order. It is deliberately not `[]`: see the class
     * docblock, where those being two answers is the one way this differs from
     * the three settings it is modelled on.
     *
     * @param User|null $user whoever is reading, when there is one
     *
     * @return list<string>|null
     */
    public function of(?User $user = null): ?array
    {
        return $user?->getDashboardLayout() ?? $this->fallbackFor();
    }

    /**
     * What somebody who has never chosen gets: the installation's, then nothing.
     *
     * Separate from `of()` for the reason {@see DisplayTimezone::fallbackFor()}
     * is separate from its own — the account page has to be able to say *what you
     * would get* beside the "use the company's" option rather than leaving the
     * reader to guess. Here it does one more job: it is what the reset control
     * restores somebody to, and a page offering "go back to the default" ought to
     * be reading the same answer the dashboard will.
     *
     * @return list<string>|null
     */
    public function fallbackFor(): ?array
    {
        return $this->profile()?->getDashboardLayout();
    }

    /**
     * What the installation says, when there is an installation to ask.
     *
     * Null on the login page in deployments where the tenant is not resolved yet,
     * and null in every console command — normal conditions rather than failures,
     * which is why this returns the profile instead of taking it. The same
     * handling `FormattingLocale::instanceRegion()` demonstrated and
     * `DisplayTimezone` copied.
     */
    private function profile(): ?TenantProfile
    {
        if ($this->context->tryGetTenant() === null) {
            return null;
        }

        return $this->profiles->current();
    }
}

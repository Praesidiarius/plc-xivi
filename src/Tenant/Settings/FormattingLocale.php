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
use App\Tenant\Entity\User;
use App\Tenant\Repository\TenantProfileRepository;

/**
 * The locale a page is *written* in, as opposed to the one it is translated into
 * (XIV-50).
 *
 * **Two questions, and choosing a language was answering both.** Which words
 * somebody reads and which country's conventions they write numbers by are
 * independent: Swiss German and German German share one catalogue and disagree
 * about how to write a figure — `1’234’500.00` against `1.234.500,00`, the
 * decimal separator as well as the grouping one. An English-speaking colleague
 * at a Swiss company wants English words and Swiss numbers, which is an ordinary
 * hire rather than a special case.
 *
 * So the language is chosen from the catalogues that exist, the region from the
 * countries there are, and this puts them back together: `de` and `CH` make
 * `de_CH`. Nothing downstream learns a new concept — every formatter already
 * asks for a locale, and now it is asked a better question.
 *
 * **The translations come along for free.** Symfony falls a locale back to its
 * language, so `de_CH` finds the `de` catalogue with nothing added and nothing
 * duplicated. A region needs no translator work, which is most of why the two
 * are stored apart and joined here rather than being one long list to pick from.
 *
 * The chain, and each step is a different promise:
 *
 * 1. **The person**, if they said. Somebody who is not where the company is.
 * 2. **The installation** (§8.6), whose people are mostly in one country.
 * 3. **Nothing**, which leaves the bare language and lets ICU pick its own
 *    default region — the behaviour every installation had before this existed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class FormattingLocale
{
    public function __construct(
        private TenantContext $context,
        private TenantProfileRepository $profiles,
    ) {
    }

    /**
     * That language, written the way this reader's country writes things.
     *
     * @param string    $language a locale from the enabled set — `de`, `en`
     * @param User|null $user     whoever is reading, when there is one
     */
    public function of(string $language, ?User $user = null): string
    {
        $region = $user?->getRegion() ?? $this->instanceRegion();

        if ($region === null) {
            return $language;
        }

        // A language already carrying a region is left alone: somebody who
        // chose `de_AT` from a future longer list has been specific, and a
        // company default has no business overruling it.
        if (str_contains($language, '_') || str_contains($language, '-')) {
            return $language;
        }

        return $language . '_' . $region;
    }

    /**
     * What the installation writes in, when there is an installation to ask.
     *
     * The login page has no tenant resolved yet in some deployments, and a
     * console command has none at all — neither is a reason to fail, so both
     * fall through to the bare language.
     *
     * **Public since [XIV-114]**, which is the one change that ticket made to
     * this class. A phone number typed without a country code has to be read
     * against a country, and the country it is read against is this one — so
     * `App\Tenant\Settings\ProfileRegion` calls this rather than reaching into
     * the profile repository a second time. The alternative was a fourth
     * variation on the chain §8.4.2 established, which is precisely what that
     * ticket set out not to build. Nothing else about this method changed.
     */
    public function instanceRegion(): ?string
    {
        if ($this->context->tryGetTenant() === null) {
            return null;
        }

        return $this->profiles->current()->getRegion();
    }
}

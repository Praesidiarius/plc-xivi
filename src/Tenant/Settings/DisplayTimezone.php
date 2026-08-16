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
use Symfony\Component\Intl\Exception\MissingResourceException;
use Symfony\Component\Intl\Timezones;

/**
 * The zone a moment is *read* in, as opposed to the one it is stored in
 * (XIV-83).
 *
 * **Storage was never the question.** Every moment this application keeps is
 * absolute: Postgres `timestamptz` normalises to UTC on write and remembers no
 * per-row zone, the engine writes through `Types::DATETIMETZ_IMMUTABLE`, and the
 * process runs with `date.timezone = UTC`. All of that was already right and none
 * of it changes here. What did not exist was the other half — the display layer
 * that turns one absolute instant into the wall clock somebody was looking at —
 * and while the only moments on screen were history timestamps nobody could tell.
 * That stops being true the moment anything groups by day, because a boundary
 * drawn in the wrong zone moves entries between days rather than merely mislabels
 * them by an hour.
 *
 * **The same chain `FormattingLocale` walks, with one extra step.** Each link is
 * a different promise:
 *
 * 1. **The person**, if they said. Somebody who is not where the company is.
 * 2. **The installation** (§8.6), whose people are mostly in one place.
 * 3. **Derived from the effective region**, where that country has exactly one
 *    zone — which is what makes this free for most customers, since they have
 *    already answered it by choosing a country.
 * 4. **UTC**, which is what the application already did everywhere.
 *
 * **Step three is the one with a trap in it, and the trap is taking the first
 * entry of the list.** `Timezones::forCountryCode()` returns them in the order
 * CLDR keeps, which is not "the important one first": Spain's begins
 * `Africa/Ceuta` and America's begins `America/Adak`, so a head-of-list rule
 * would file a Madrid office in North Africa and a New York one in the Aleutians.
 * A wrong zone is worse than an unanswered one precisely because nothing on
 * screen reveals it — a timestamp in the wrong zone still looks like a timestamp
 * — so where a country is ambiguous this derives nothing and falls to UTC, which
 * is at least visibly not local, and the setting becomes one somebody has to
 * answer.
 *
 * Measured against the zones as they stand: CH, AT, FR, IT, GB, NL and IN have
 * one each and derive; DE has two, CN two, ES three, and AU, BR, CA, RU and US
 * have between twelve and twenty-nine. Zones rather than identifiers — see
 * `derivedFromRegion()`, where India turns out to be the case that makes the
 * difference matter.
 *
 * **Germany's two are not special-cased**, and that is deliberate rather than an
 * oversight. `Europe/Busingen` is a German exclave inside Switzerland that keeps
 * Swiss time, which is CET on both sides, so forcing a German customer to choose
 * between two identical clocks is mildly redundant. Collapsing zones that happen
 * to agree today would mean carrying a list of "close enough" pairs that is only
 * true until one of them changes its rules — a maintenance liability bought with
 * one saved click. The rule stays arithmetic: exactly one zone, or nothing.
 *
 * **A console command has no user and may have no tenant**, and neither is an
 * error. `TenantContext::tryGetTenant()` returning null is the ordinary
 * condition on the login page and in `bin/console`, so the chain simply runs out
 * of things to ask and lands on UTC — the same shape `FormattingLocale` handles
 * it with.
 *
 * **Naming zones lives here too**, rather than beside the currency and country
 * pickers in `TenantProfileManager`. Both settings pages need the list and only
 * one of them has that manager, and a class that decides which zone applies is
 * the obvious place to ask what to call one. symfony/intl owns the data either
 * way; nothing here keeps a list.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DisplayTimezone
{
    /**
     * The bottom of the chain, spelled the way PHP spells it.
     *
     * `Etc/UTC` is what CLDR calls the same zone and it is what the picker shows,
     * but what gets stored and compared is this — the identifier every other part
     * of this stack, from `date.timezone` to Postgres, already agrees on.
     */
    private const string UTC = 'UTC';

    public function __construct(
        private TenantContext $context,
        private TenantProfileRepository $profiles,
    ) {
    }

    /**
     * The zone this person reads moments in — the whole chain, and the method
     * almost everything calls.
     *
     * @param User|null $user whoever is reading, when there is one
     */
    public function of(?User $user = null): \DateTimeZone
    {
        return self::zone($user?->getTimezone()) ?? $this->fallbackFor($user);
    }

    /**
     * What somebody who has never chosen one gets: the installation's, then the
     * region's, then UTC.
     *
     * Separate from `of()` so the account page can say *what it would be* beside
     * the empty option, rather than offering "follow the company's" and leaving
     * the reader to guess which clock that is. The ticket's own complaint about
     * a wrong zone is that nothing reveals it; a picker that names the inherited
     * answer is the cheapest place to start revealing it.
     */
    public function fallbackFor(?User $user = null): \DateTimeZone
    {
        $profile = $this->profile();

        return self::zone($profile?->getTimezone())
            ?? $this->derivedFromRegion($user?->getRegion() ?? $profile?->getRegion())
            ?? new \DateTimeZone(self::UTC);
    }

    /**
     * Step three on its own: the zone a country implies, when it implies exactly
     * one.
     *
     * Null for a country with several, for a country with none on file, and for
     * anything that is not a country code at all — `forCountryCode()` throws on
     * an unknown one, and a hand-edited region ending up as an exception on a
     * settings page would be the wrong answer to a wrong input.
     *
     * **Distinct zones, not distinct identifiers**, which is the one place this
     * departs from taking symfony/intl's list at face value. India's reads
     * `Asia/Calcutta, Asia/Kolkata` — two names for one zone, because the first
     * is a backward-compatibility link kept in the tz database after the city was
     * renamed. Counting identifiers would make India ambiguous and put a picker
     * in front of an Indian customer offering them a choice between Kolkata and
     * Calcutta, which is not a choice. The United States and its
     * `America/Indianapolis` are the same story at a scale where nobody notices.
     *
     * **This is not the special-casing the ticket rejected.** Collapsing
     * `Europe/Berlin` and `Europe/Busingen` would be judging that two genuinely
     * different zones happen to agree today, which is a judgement that expires;
     * collapsing `Asia/Calcutta` into `Asia/Kolkata` is recognising that they
     * were never two zones. Telling those apart needs the tz database itself
     * rather than CLDR, and PHP carries it: `DateTimeZone::listIdentifiers()` per
     * country returns the canonical set with the links left out. So symfony/intl
     * still says which zones a country has, and PHP is asked only whether an
     * identifier is a zone or another name for one — the narrowest question that
     * settles it.
     *
     * @param string|null $region an ISO 3166-1 alpha-2 code
     */
    public function derivedFromRegion(?string $region): ?\DateTimeZone
    {
        if ($region === null || $region === '') {
            return null;
        }

        try {
            $zones = Timezones::forCountryCode($region);
        } catch (MissingResourceException) {
            return null;
        }

        $zones = array_values(array_intersect(
            $zones,
            \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $region),
        ));

        // Exactly one, never the first of several. See the class docblock — this
        // single comparison is the whole rule, and it is the reason a Spanish
        // customer is asked rather than guessed at.
        return \count($zones) === 1 ? self::zone($zones[0]) : null;
    }

    /**
     * Every zone there is, named in the language being read.
     *
     * From symfony/intl rather than a list kept here, for exactly the reason the
     * currencies and countries are: a copy of the zone database maintained by
     * hand is a copy that is wrong. Sorted by name, so somebody looks for
     * "Central European Time (Zurich)" rather than for `Europe/Zurich` — which is
     * also why the picker is long and unfiltered. Narrowing it to the effective
     * region would hide the case this column exists for, the person who is not
     * where their company is.
     *
     * @return array<string, string> identifier => what to call it
     */
    public function choices(string $locale): array
    {
        return Timezones::getNames($locale);
    }

    /**
     * What to call one zone, in the language being read.
     *
     * Falls back to the identifier itself when CLDR has no name for it, which is
     * the case for plain `UTC`: it is a valid PHP zone and not one of the 439
     * CLDR lists, since CLDR spells the same thing `Etc/UTC`. Printing `UTC`
     * there is both correct and the most readable thing available, so it is not
     * worth a mapping table.
     */
    public function name(\DateTimeZone $zone, string $locale): string
    {
        try {
            return Timezones::getName($zone->getName(), $locale);
        } catch (MissingResourceException) {
            return $zone->getName();
        }
    }

    /**
     * Whether this is a zone at all, asked before anything is stored.
     *
     * symfony/intl's list rather than PHP's, because it is the list the picker
     * was built from: accepting something the select could never have offered
     * would be accepting a hand-edited request, and the honest answer to one of
     * those is to change nothing.
     */
    public function exists(string $identifier): bool
    {
        return Timezones::exists($identifier);
    }

    /**
     * What the installation says, when there is an installation to ask.
     *
     * Null on the login page in deployments where the tenant is not resolved
     * yet, and null in every console command — normal conditions rather than
     * failures, which is why this returns the profile instead of taking it.
     */
    private function profile(): ?TenantProfile
    {
        if ($this->context->tryGetTenant() === null) {
            return null;
        }

        return $this->profiles->current();
    }

    /**
     * An identifier turned into a zone, or null if it is not one.
     *
     * The guard is not paranoia about the form, which validates: zone
     * identifiers are retired and renamed as countries reorganise, so a value
     * stored perfectly well two years ago can stop being one when the container's
     * tzdata is updated underneath it. Falling through to the next link in the
     * chain is a better outcome than a settings page that throws, and the reader
     * sees the company's zone rather than an error.
     */
    private static function zone(?string $identifier): ?\DateTimeZone
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        try {
            return new \DateTimeZone($identifier);
        } catch (\Exception) {
            return null;
        }
    }
}

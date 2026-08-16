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

namespace App\Tenant\FollowUp;

/**
 * How far ahead the dashboard is looking (XIV-81).
 *
 * Three views of one list, and they are **upper bounds that nest**: everything
 * *today* shows is also in *this week*, and everything *this week* shows is also
 * in *all*. Narrowing therefore only ever removes rows from the bottom of the
 * list, which is what makes the three read as one control with a range rather
 * than as three different questions.
 *
 * **There is no lower bound, and that is the whole design.** An upper bound on
 * its own means a follow-up that was due last Tuesday is still in *today* this
 * morning, still in *this week*, and still in *all* — because it is still, in
 * every sense that matters, due. Adding "and not before today" would produce a
 * list that empties itself as deadlines pass: a follow-up somebody missed would
 * vanish from the dashboard at the exact moment it started to matter. That is the
 * worst behaviour available here, and a lower bound is the only way to get it.
 *
 * **Today means up to the end of today, which is deliberately the opposite of
 * §5.16.** An invoice is overdue *strictly before* today, because telling a
 * customer they are late on the morning their bill falls due is how a dunning
 * list loses its credibility. A follow-up is the other case entirely: it is a
 * note somebody wrote to themselves, and what is due at 16:30 is exactly what
 * belongs on their dashboard at 09:00. The predicate that expresses this lives in
 * {@see \App\Tenant\Repository\FollowUpRepository::openFor()} and carries the
 * same warning, because it is the one somebody would later "fix" into agreeing
 * with the invoice.
 *
 * **Which day the week starts on is a locale question**, answered by ICU rather
 * than by assuming Monday — see {@see self::startOfWeek()}. A reader in the
 * United States starts their week on a Sunday, and a widget that told them
 * Sunday's work was "next week" would be wrong about their calendar rather than
 * about the data.
 *
 * **Not built: a lens for unassigned follow-ups.** The ticket rules it out and it
 * is worth restating where somebody would add it: this widget is *mine*, and a
 * view of work nobody has taken is a different screen with a different question
 * behind it — closer to a queue than to a dashboard.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum FollowUpLens: string
{
    /** Due at any moment up to the end of today, plus everything already overdue. */
    case Today = 'today';

    /** The same, out to the end of the reader's own week. */
    case Week = 'week';

    /** Everything still open, however far off. */
    case All = 'all';

    /**
     * What somebody who has not chosen gets.
     *
     * **This week, rather than today.** Today is the tightest lens and would be
     * empty most mornings, which teaches people to ignore the widget; all is
     * unbounded and turns a dashboard into a backlog. A week is the horizon
     * somebody plans in, and it is the only one of the three whose emptiness is
     * genuinely good news.
     */
    public static function default(): self
    {
        return self::Week;
    }

    /**
     * The lens a query string asked for, or the default.
     *
     * Anything unrecognised falls back rather than raising: this arrives from a
     * URL somebody can edit or a link somebody bookmarked before the names
     * changed, and a 400 on the landing page would be a poor answer to a typo.
     */
    public static function fromInput(?string $value): self
    {
        return $value === null ? self::default() : (self::tryFrom($value) ?? self::default());
    }

    /**
     * The moment this lens stops looking, or null when it does not stop.
     *
     * **Exclusive.** The bound returned is the *start of the day after* the last
     * day included, so the predicate is `due_at < :bound` rather than `<=` on
     * 23:59:59.something. A timestamp compared against the last representable
     * instant of a day is a comparison that depends on how many decimal places
     * the column keeps, and `timestamptz` keeps six — an off-by-one microsecond
     * is not a bug anybody finds twice.
     *
     * **The zone is applied here and only here.** Everything stored is an
     * absolute instant, so the follow-ups themselves need no conversion at all —
     * comparing two moments compares instants rather than wall clocks. What does
     * need a zone is *midnight*: `setTime(0, 0)` means midnight where the object
     * is, so moving `$now` into the reader's zone first is the entire mechanism,
     * exactly as {@see \Xivi\Core\History\HistorySection::of()} does it for the
     * timeline. Drawing these boundaries in UTC would move a follow-up between
     * lenses rather than mislabelling it by an hour (§8.4.4).
     *
     * `+1 day` and `+7 days` rather than adding seconds, because these are
     * calendar boundaries: the day a clock changes is 23 or 25 hours long, and a
     * reader in Zurich in late March would otherwise lose an hour off the end of
     * their week.
     *
     * @param \DateTimeZone           $zone   the reader's, resolved by {@see \App\Tenant\Settings\DisplayTimezone}
     * @param string                  $locale the reader's, composed by {@see \App\Tenant\Settings\FormattingLocale} —
     *                                        it is the region half that decides which day a week starts on
     * @param \DateTimeImmutable|null $now    for tests, which cannot wait for a Sunday
     */
    public function dueBefore(\DateTimeZone $zone, string $locale, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $now ??= new \DateTimeImmutable();

        // Exhaustive rather than a default arm, so that adding a fourth lens is
        // an argument static analysis starts rather than a silent fall-through
        // at runtime.
        return match ($this) {
            self::All => null,
            self::Today => self::startOfDay($now, $zone)->modify('+1 day'),
            self::Week => self::startOfWeek(self::startOfDay($now, $zone), $locale)->modify('+7 days'),
        };
    }

    /** A key in the `messages` domain — the application's word, not a module's. */
    public function labelKey(): string
    {
        return 'follow_up.lens.' . $this->value;
    }

    /** The sentence shown when this particular lens has nothing in it. */
    public function emptyKey(): string
    {
        return 'follow_up.lens_empty.' . $this->value;
    }

    /**
     * Midnight at the start of the day `$now` falls in, *where the reader is*.
     *
     * Two calls and the whole zone mechanism is in them: `setTimezone()` decides
     * whose day this is and `setTime()` draws the boundary there. Nothing else in
     * this file touches a zone, and nothing needs to — the follow-ups being
     * compared against the result are absolute instants, and comparing two
     * moments compares instants rather than wall clocks.
     */
    private static function startOfDay(\DateTimeImmutable $now, \DateTimeZone $zone): \DateTimeImmutable
    {
        return $now->setTimezone($zone)->setTime(0, 0);
    }

    /**
     * Midnight on the first day of the week `$startOfToday` falls in, for this
     * reader.
     *
     * **ICU decides which day that is, and this does the counting.** The
     * locale-shaped half of the question — Sunday in the United States, Monday in
     * Switzerland, Saturday in much of the Gulf — is a fact about CLDR data that
     * nothing in this repository should be keeping a copy of, so `IntlCalendar`
     * is asked. The remaining half is "how many days back is that", which is one
     * subtraction modulo seven and does not become clearer for being expressed
     * through a calendar object's field arithmetic.
     *
     * That split is the departure worth naming: symfony/intl is this codebase's
     * usual door onto CLDR, and it has no opinion about weeks — `Timezones`,
     * `Countries` and `Currencies` are lists of things, and the first day of the
     * week is a rule rather than a list. ICU itself carries it, PHP exposes ICU
     * through ext-intl, and that is the shortest honest route to the answer.
     *
     * The subtraction is done on `\DateTimeImmutable` rather than on the
     * calendar, so the result keeps the reader's zone and the DST behaviour
     * `modify()` already has right.
     */
    private static function startOfWeek(\DateTimeImmutable $startOfToday, string $locale): \DateTimeImmutable
    {
        $calendar = \IntlCalendar::createInstance($startOfToday->getTimezone(), $locale);

        // ICU numbers the days 1..7 from Sunday; PHP's `w` numbers them 0..6 from
        // Sunday, so one subtraction puts them on the same scale.
        //
        // The `?: 2` is Monday, and it is a guard rather than the rule: reaching
        // it needs `createInstance()` to have failed outright, which takes a
        // broken ICU rather than an unusual locale. ISO-8601 is the least
        // surprising thing to be wrong with, and being wrong here is not the
        // hardcoded-Monday the ticket rules out — that would be this line
        // *instead of* asking, rather than after asking and getting nothing.
        $firstDay = ($calendar?->getFirstDayOfWeek() ?: 2) - 1;
        $today = (int) $startOfToday->format('w');
        $daysBack = ($today - $firstDay + 7) % 7;

        return $daysBack === 0 ? $startOfToday : $startOfToday->modify(sprintf('-%d days', $daysBack));
    }
}

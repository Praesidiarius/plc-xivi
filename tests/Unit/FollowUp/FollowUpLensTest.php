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

namespace App\Tests\Unit\FollowUp;

use App\Tenant\FollowUp\FollowUpLens;
use PHPUnit\Framework\TestCase;

/**
 * Where the dashboard's three lenses stop looking (XIV-81).
 *
 * A unit test for the same reason {@see \App\Tests\Unit\History\HistoryGroupingTest}
 * is one: this is arithmetic on calendar boundaries and needs no kernel, no
 * database and no tenant. Which zone and which locale reach it are
 * `TimezoneTest`'s and `LocaleTest`'s business.
 *
 * Two things are being defended here, and both are the kind that get "corrected"
 * later by somebody reading one file:
 *
 * * **the boundaries are drawn in the reader's zone**, in both directions —
 *   west of UTC and east of it — because a boundary in the wrong zone moves a
 *   follow-up between lenses rather than mislabelling it;
 * * **the week starts where the reader's locale says it does**, which for an
 *   American reader is a Sunday, and a hardcoded Monday would silently push
 *   Sunday's work into next week for them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FollowUpLensTest extends TestCase
{
    private const string ZURICH = 'Europe/Zurich';
    private const string TOKYO = 'Asia/Tokyo';
    private const string SWISS = 'de_CH';
    private const string AMERICAN = 'en_US';

    /**
     * Today ends at the reader's midnight, not at UTC's — going west.
     *
     * 23:00 UTC on the 16th is already 01:00 on the 17th in Zurich, so a Zurich
     * reader's "today" runs to midnight on the 18th while UTC's still runs to
     * midnight on the 17th. Twenty-four hours apart, and every follow-up due in
     * between is on the widget for one of them and not for the other.
     */
    public function testTodayEndsAtTheReadersOwnMidnightGoingWest(): void
    {
        $now = new \DateTimeImmutable('2026-08-16 23:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            '2026-08-17 00:00:00 UTC',
            self::describe(FollowUpLens::Today->dueBefore(new \DateTimeZone('UTC'), self::SWISS, $now)),
            'in UTC it is still the 16th, so today ends at the 17th',
        );

        self::assertSame(
            '2026-08-18 00:00:00 Europe/Zurich',
            self::describe(FollowUpLens::Today->dueBefore(new \DateTimeZone(self::ZURICH), self::SWISS, $now)),
            'in Zurich it is already the 17th, so today ends at the 18th',
        );
    }

    /**
     * And going east, so this is not a test that only knows how to move
     * boundaries one way.
     *
     * 16:00 UTC on the 16th is 01:00 on the 17th in Tokyo — the same instant, one
     * calendar day apart, and the *earlier* wall clock this time rather than the
     * later one.
     */
    public function testTodayEndsAtTheReadersOwnMidnightGoingEast(): void
    {
        $now = new \DateTimeImmutable('2026-08-16 16:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            '2026-08-17 00:00:00 UTC',
            self::describe(FollowUpLens::Today->dueBefore(new \DateTimeZone('UTC'), self::SWISS, $now)),
        );

        self::assertSame(
            '2026-08-18 00:00:00 Asia/Tokyo',
            self::describe(FollowUpLens::Today->dueBefore(new \DateTimeZone(self::TOKYO), self::SWISS, $now)),
            'Tokyo turned over an hour ago, so its today ends a day later than UTC calls it',
        );
    }

    /**
     * Which day the week starts on comes from the locale, and the two answers
     * that matter here are a day apart.
     *
     * Sunday 2026-08-16 is the interesting date precisely because the two
     * disagree about which week it belongs to: for an American reader it is the
     * *first* day of a week ending Saturday the 22nd, and for a Swiss one it is
     * the *last* day of a week that ends there and then.
     */
    public function testTheWeekEndsWhereTheReadersLocaleSaysItDoes(): void
    {
        // A Sunday.
        $now = new \DateTimeImmutable('2026-08-16 12:00:00', new \DateTimeZone(self::ZURICH));
        $zone = new \DateTimeZone(self::ZURICH);

        self::assertSame(
            '2026-08-17 00:00:00 Europe/Zurich',
            self::describe(FollowUpLens::Week->dueBefore($zone, self::SWISS, $now)),
            'a Swiss week runs Monday to Sunday, so this Sunday is its last day',
        );

        self::assertSame(
            '2026-08-23 00:00:00 Europe/Zurich',
            self::describe(FollowUpLens::Week->dueBefore($zone, self::AMERICAN, $now)),
            'an American week starts on Sunday, so this one has six days left in it',
        );
    }

    /**
     * The zone applies to the week's boundary as well as to today's, which is the
     * case a fix that only touched `Today` would leave broken.
     */
    public function testTheWeekBoundaryIsDrawnInTheReadersZoneToo(): void
    {
        // Sunday 23:00 UTC, which is already Monday in Zurich — so UTC is at the
        // end of one Swiss week and Zurich is at the start of the next.
        $now = new \DateTimeImmutable('2026-08-16 23:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            '2026-08-17 00:00:00 UTC',
            self::describe(FollowUpLens::Week->dueBefore(new \DateTimeZone('UTC'), self::SWISS, $now)),
        );

        self::assertSame(
            '2026-08-24 00:00:00 Europe/Zurich',
            self::describe(FollowUpLens::Week->dueBefore(new \DateTimeZone(self::ZURICH), self::SWISS, $now)),
            'Zurich has already started the next week, and this one ends seven days later',
        );
    }

    /**
     * The lenses nest, which is what makes them one control rather than three
     * questions.
     *
     * Asserted as an ordering rather than by re-deriving the dates, because the
     * property that matters is that narrowing only ever removes rows from the
     * bottom of the list — whatever the calendar happens to be doing that week.
     */
    public function testTheLensesNest(): void
    {
        $zone = new \DateTimeZone(self::ZURICH);

        foreach (['2026-08-16', '2026-08-17', '2026-08-19', '2026-08-22'] as $day) {
            $now = new \DateTimeImmutable($day . ' 09:00:00', $zone);

            $today = FollowUpLens::Today->dueBefore($zone, self::SWISS, $now);
            $week = FollowUpLens::Week->dueBefore($zone, self::SWISS, $now);

            self::assertNotNull($today);
            self::assertNotNull($week);
            self::assertLessThanOrEqual($week, $today, sprintf('on %s, today has to fit inside this week', $day));
            self::assertNull(
                FollowUpLens::All->dueBefore($zone, self::SWISS, $now),
                'and all has no bound at all, which is what both fit inside',
            );
        }
    }

    /**
     * A week ending across a spring clock change is still seven days long.
     *
     * The reason `modify('+7 days')` is used rather than adding 604800 seconds:
     * Europe/Zurich loses an hour on the last Sunday of March, and a week
     * measured in seconds would end an hour early — cutting an hour off the last
     * evening of somebody's week, silently, once a year.
     */
    public function testAWeekAcrossAClockChangeIsStillSevenDays(): void
    {
        $zone = new \DateTimeZone(self::ZURICH);
        // The Monday before Switzerland goes onto summer time on 2026-03-29.
        $now = new \DateTimeImmutable('2026-03-23 09:00:00', $zone);

        self::assertSame(
            '2026-03-30 00:00:00 Europe/Zurich',
            self::describe(FollowUpLens::Week->dueBefore($zone, self::SWISS, $now)),
        );
    }

    /**
     * There is no lower bound, expressed as the one thing a lower bound would
     * change: something due weeks ago is still under every ceiling.
     */
    public function testAnOverdueFollowUpIsUnderEveryLensCeiling(): void
    {
        $zone = new \DateTimeZone(self::ZURICH);
        $now = new \DateTimeImmutable('2026-08-19 09:00:00', $zone);
        $missed = new \DateTimeImmutable('2026-07-02 16:30:00', $zone);

        foreach ([FollowUpLens::Today, FollowUpLens::Week] as $lens) {
            $bound = $lens->dueBefore($zone, self::SWISS, $now);

            self::assertNotNull($bound);
            self::assertLessThan($bound, $missed, $lens->value . ' has no near end, so a missed follow-up is still in it');
        }
    }

    /**
     * Due at 16:30 is on the dashboard at 09:00 — the §5.16 inversion, stated as
     * arithmetic.
     */
    public function testSomethingDueLaterTodayIsAlreadyInTodaysLens(): void
    {
        $zone = new \DateTimeZone(self::ZURICH);
        $bound = FollowUpLens::Today->dueBefore(
            $zone,
            self::SWISS,
            new \DateTimeImmutable('2026-08-19 09:00:00', $zone),
        );

        self::assertNotNull($bound);
        self::assertLessThan($bound, new \DateTimeImmutable('2026-08-19 16:30:00', $zone));
        self::assertGreaterThan(
            $bound,
            new \DateTimeImmutable('2026-08-20 09:00:00', $zone),
            'and tomorrow morning is not, or the lens would not narrow anything',
        );
    }

    /** An unreadable query parameter is the default rather than an error. */
    public function testAnUnknownLensFallsBackToTheDefault(): void
    {
        self::assertSame(FollowUpLens::Week, FollowUpLens::default());
        self::assertSame(FollowUpLens::Week, FollowUpLens::fromInput(null));
        self::assertSame(FollowUpLens::Week, FollowUpLens::fromInput(''));
        self::assertSame(FollowUpLens::Week, FollowUpLens::fromInput('yesterday'));
        self::assertSame(FollowUpLens::Today, FollowUpLens::fromInput('today'));
        self::assertSame(FollowUpLens::All, FollowUpLens::fromInput('all'));
    }

    /** The moment, and the zone it is being read in, in one comparable string. */
    private static function describe(?\DateTimeImmutable $moment): string
    {
        return $moment === null ? 'unbounded' : $moment->format('Y-m-d H:i:s e');
    }
}

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

namespace App\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Xivi\Core\History\HistoryEntry;
use Xivi\Core\History\HistoryPeriod;
use Xivi\Core\History\HistorySection;
use Xivi\Core\Record\RecordAction;

/**
 * Whose "today" the timeline groups by (XIV-83).
 *
 * The bug this covers was invisible for as long as the only thing on screen was
 * a timestamp: an hour's error in a label is cosmetic, and the same hour crossing
 * a day boundary **moves the entry into a different section**. Somebody in Zurich
 * saving a record at 00:30 saw it filed under "this week" — yesterday's bucket —
 * on a page they had made thirty minutes ago, because midnight was being drawn in
 * UTC.
 *
 * A unit test because the mechanism is arithmetic on calendar boundaries and
 * needs no kernel, no database and no tenant. The chain that decides *which*
 * zone gets here is `TimezoneTest`'s business.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class HistoryGroupingTest extends TestCase
{
    /**
     * 23:30 UTC is half past midnight in Zurich, and belongs to the day the
     * reader is having.
     */
    public function testAMomentAfterLocalMidnightIsTodayForTheReaderWhoseMidnightItIs(): void
    {
        // 01:00 in Zurich on the 17th, which is midnight UTC on the same date.
        $now = new \DateTimeImmutable('2026-08-17 00:00:00', new \DateTimeZone('UTC'));
        // Half an hour before that: still the 16th in UTC, already the 17th in
        // Zurich.
        $when = new \DateTimeImmutable('2026-08-16 23:30:00', new \DateTimeZone('UTC'));

        self::assertSame(
            HistoryPeriod::Week,
            self::sectionOf($when, $now, new \DateTimeZone('UTC')),
            'in UTC it is yesterday, which is what this always did',
        );

        self::assertSame(
            HistoryPeriod::Today,
            self::sectionOf($when, $now, new \DateTimeZone('Europe/Zurich')),
            'in Zurich it is half past midnight today, which is where the reader will look for it',
        );
    }

    /**
     * And the other direction, so this is not a test that only knows how to move
     * entries forwards.
     *
     * 23:30 UTC on a summer evening is already the next morning in Tokyo, so an
     * entry that UTC calls "today" is yesterday's for a reader in Japan.
     */
    public function testAMomentBeforeUtcMidnightIsAlreadyYesterdayFurtherEast(): void
    {
        $now = new \DateTimeImmutable('2026-08-16 23:45:00', new \DateTimeZone('UTC'));
        $when = new \DateTimeImmutable('2026-08-16 14:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            HistoryPeriod::Today,
            self::sectionOf($when, $now, new \DateTimeZone('UTC')),
            'UTC has not turned over yet',
        );

        self::assertSame(
            HistoryPeriod::Week,
            self::sectionOf($when, $now, new \DateTimeZone('Asia/Tokyo')),
            'Tokyo turned over eight hours ago, so that entry is yesterday there',
        );
    }

    /**
     * No zone is UTC, which is what a console command gets and what every caller
     * had before this argument existed.
     */
    public function testNoZoneMeansUtc(): void
    {
        $now = new \DateTimeImmutable('2026-08-17 00:00:00', new \DateTimeZone('UTC'));
        $when = new \DateTimeImmutable('2026-08-16 23:30:00', new \DateTimeZone('UTC'));

        self::assertSame(HistoryPeriod::Week, self::sectionOf($when, $now, null));
    }

    /**
     * The first section on a page always opens, whatever it is — the rule the
     * folding was written under, asserted here because the zone work rearranges
     * which section comes first.
     */
    public function testTheFirstSectionAlwaysOpens(): void
    {
        $now = new \DateTimeImmutable('2026-08-17 12:00:00', new \DateTimeZone('UTC'));
        $sections = HistorySection::of(
            [self::entry(new \DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC')))],
            new \DateTimeZone('Europe/Zurich'),
            $now,
        );

        self::assertCount(1, $sections);
        self::assertSame(HistoryPeriod::Older, $sections[0]->period);
        self::assertFalse($sections[0]->folded, 'a page of nothing but old entries is not a screen of shut boxes');
    }

    private static function sectionOf(
        \DateTimeImmutable $when,
        \DateTimeImmutable $now,
        ?\DateTimeZone $zone,
    ): HistoryPeriod {
        $sections = HistorySection::of([self::entry($when)], $zone, $now);

        self::assertCount(1, $sections);

        return $sections[0]->period;
    }

    private static function entry(\DateTimeImmutable $occurredAt): HistoryEntry
    {
        return new HistoryEntry(1, $occurredAt, RecordAction::Updated, null, null, []);
    }
}

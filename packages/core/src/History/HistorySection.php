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

namespace Xivi\Core\History;

/**
 * One age band of a timeline, with the entries that fall in it (XIV-3).
 *
 * Built by {@see self::of()} rather than assembled in a template: which bucket a
 * date belongs in is a question with an answer, and Twig is a bad place to keep
 * answers.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class HistorySection
{
    /** @param list<HistoryEntry> $entries */
    public function __construct(
        public HistoryPeriod $period,
        public array $entries,
        /**
         * Whether this section renders closed. Not simply the period's own
         * answer: the first section on a page always opens, so a page deep
         * enough to hold nothing but old entries is not a screen of shut boxes.
         */
        public bool $folded,
    ) {
    }

    /**
     * One page of entries, split into the periods they fall in, newest first.
     *
     * Entries are expected in the order the repository returns them — newest
     * first — so the sections come out in order without sorting anything again.
     *
     * **The zone is what "today" means** (XIV-83). Entries are absolute instants
     * and the bands are calendar boundaries, so something has to say whose
     * calendar — and until this took a zone the answer was UTC, which is nobody's
     * for most of the customers this serves. It is applied once, here, by moving
     * `$now` into the reader's zone; `HistoryPeriod` then draws midnight where
     * `$now` is and the entries need no conversion, because comparing two moments
     * compares instants. Null is UTC, which is the behaviour every caller had
     * before this existed and is what a console command correctly gets.
     *
     * Core takes a `\DateTimeZone` rather than asking the application who is
     * reading: the engine does not know what a user is (§5.2), and resolving the
     * chain is the application's job.
     *
     * @param list<HistoryEntry> $entries
     *
     * @return list<self>
     */
    public static function of(array $entries, ?\DateTimeZone $zone = null, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone($zone ?? new \DateTimeZone('UTC'));

        /** @var array<string, list<HistoryEntry>> $grouped */
        $grouped = [];

        foreach ($entries as $entry) {
            $grouped[HistoryPeriod::of($entry->occurredAt, $now)->value][] = $entry;
        }

        $sections = [];

        foreach ($grouped as $period => $inPeriod) {
            $sections[] = new self(
                HistoryPeriod::from($period),
                $inPeriod,
                $sections !== [] && HistoryPeriod::from($period)->isFoldedByDefault(),
            );
        }

        return $sections;
    }
}

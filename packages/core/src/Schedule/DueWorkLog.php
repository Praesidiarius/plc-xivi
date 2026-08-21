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

namespace Xivi\Core\Schedule;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * **What has already happened, and the one statement that decides it** (XIV-155,
 * §6.7).
 *
 * The engine remembers every occurrence it has run, in the tenant's own database,
 * one row per (work, subject, period) with a unique index over exactly those
 * three columns. That index is not bookkeeping hygiene. **It is the whole of the
 * idempotency guarantee**, and everything else in this class is arranged around
 * it.
 *
 * ## One guarded statement, which is the lesson XIV-103 paid for
 *
 * The obvious implementation is a `SELECT` asking whether this occurrence has
 * run and an `INSERT` if it has not, and it is the same defect
 * {@see \Xivi\Core\Numbering\NumberAllocator} and
 * {@see \Xivi\Voucher\Redemption\VoucherRedemptions} both exist to avoid: two
 * processes read "not yet", both find room, both insert, and the customer gets
 * two invoices for August. Under cron that is not a theoretical interleaving. A
 * run that overruns its hour meets the next one; an operator types the command
 * by hand while cron is doing it; a retry after a kill starts while the killed
 * process's transaction is still being rolled back.
 *
 * `INSERT … ON CONFLICT (job, subject, period) DO NOTHING RETURNING id` has no
 * gap in it. The first caller inserts. The second collides with the index, waits
 * on the row lock the first took, and, when the first commits, gets nothing
 * back. **No row back is the refusal**, in one round trip, with nothing in PHP
 * to interpret and no window to interpret it in. If the first *rolls back*
 * instead, the second's insert succeeds, which is the other half of the
 * property: an occurrence whose work failed is not consumed by having been
 * attempted.
 *
 * ## Why it is `DO NOTHING` and not `DO UPDATE … WHERE`
 *
 * The voucher counter needs `DO UPDATE` because it has a number to move and a
 * limit to test. This has neither: the row's existence *is* the fact, and there
 * is nothing about an already-recorded occurrence a second caller should be
 * allowed to change. `DO NOTHING` says that and cannot be talked into anything
 * else.
 *
 * ## Why there is no foreign key to whatever `subject` names
 *
 * There cannot be one, since `subject` is a string the module chooses and the
 * engine never interprets, and there should not be. A customer who deletes a recurring
 * definition must not thereby make last month's invoice due again, which is
 * exactly what a cascading delete would do. The same argument the numbering
 * counters make in §5.10 and `voucher_redemption` makes in §5.19: **the record of
 * what happened is not owned by the thing it happened to.**
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DueWorkLog
{
    /** The work ran, and whatever it produced is in this database. */
    public const string RAN = 'ran';

    /**
     * The work did not run and never will: {@see CatchUp::OnlyTheLatest} decided
     * a newer occurrence superseded it.
     *
     * Recorded rather than skipped because {@see RecurringWork::due()} answers
     * from the module's own records, which have not changed, so an occurrence
     * merely passed over would be offered again on every run for ever.
     */
    public const string PASSED = 'passed';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * **Claim one occurrence**, and say whether this caller is the one that got
     * it.
     *
     * `true` means the row is now this caller's and the work should be done.
     * `false` means somebody already recorded it, a previous run or a concurrent
     * one that committed first, and this caller must do nothing at all.
     *
     * **Call it inside the transaction the work will run in.** That is not a
     * convention, it is the design: see {@see DueWorkRunner}, where the argument
     * for tying the claim and the effect together is written out. Called outside
     * one, this degrades into a promise that the occurrence was *attempted*,
     * which is not what anything here says.
     *
     * @param string $outcome {@see RAN} for work about to be done, {@see PASSED}
     *                        for one being written off
     */
    public function claim(string $job, Occurrence $occurrence, \DateTimeImmutable $at, string $outcome = self::RAN): bool
    {
        $claimed = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO due_work (job, subject, period, outcome, recorded_at)
                VALUES (:job, :subject, :period, :outcome, :at)
                ON CONFLICT (job, subject, period) DO NOTHING
                RETURNING id
                SQL,
            [
                'job' => $job,
                'subject' => $occurrence->subject,
                'period' => $occurrence->period,
                'outcome' => $outcome,
                'at' => $at,
            ],
            [
                'period' => Types::DATETIMETZ_IMMUTABLE,
                'at' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        return $claimed !== false;
    }

    /**
     * Which of these occurrences have no row yet, so the runner can drop the rest
     * before it starts opening transactions.
     *
     * **This is not an optimisation, it is what keeps a long-lived definition
     * affordable.** A module answers {@see RecurringWork::due()} from its own
     * records and is forbidden from keeping its own progress, so a monthly
     * definition made two years ago offers twenty-four occurrences on every run
     * of the clock, for ever. Hourly, that is twenty-four transactions an hour
     * opened only to be told by the unique index that there was nothing to do.
     * One read in front of them turns that into one query and no transactions.
     *
     * It is a plain read and can be raced, which does not matter in the
     * slightest, because nothing depends on its answer being current: what it
     * misses, {@see claim()} refuses a moment later. The two sit side by side
     * deliberately rather than one instead of the other, which is the shape
     * XIV-91 settled on for a column scan in front of a guarded statement.
     *
     * @param list<Occurrence> $occurrences
     *
     * @return list<Occurrence> the ones with no row yet, in the order given
     */
    public function outstanding(string $job, array $occurrences): array
    {
        if ($occurrences === []) {
            return [];
        }

        $subjects = array_values(array_unique(
            array_map(static fn (Occurrence $occurrence): string => $occurrence->subject, $occurrences),
        ));

        // Both columns back rather than a count, because the answer is per
        // occurrence: a definition typically has some periods recorded and some
        // not, and "this subject has rows" is the wrong question.
        $rows = $this->connection->fetchAllNumeric(
            'SELECT subject, period FROM due_work WHERE job = :job AND subject IN (:subjects)',
            ['job' => $job, 'subjects' => $subjects],
            ['subjects' => ArrayParameterType::STRING],
        );

        $recorded = [];
        foreach ($rows as [$subject, $period]) {
            $recorded[self::identity((string) $subject, new \DateTimeImmutable((string) $period))] = true;
        }

        return array_values(array_filter(
            $occurrences,
            static fn (Occurrence $occurrence): bool => !isset(
                $recorded[self::identity($occurrence->subject, $occurrence->period)],
            ),
        ));
    }

    /**
     * One occurrence's identity as an array key, which has to agree with what the
     * unique index thinks two rows are.
     *
     * The Unix timestamp rather than a formatted moment, because the same instant
     * comes back out of Postgres in the session's zone and would compare unequal
     * to the module's own `+02:00` rendering of it while being the same point in
     * time. The column is `TIMESTAMP(0)`, so a period carrying a fraction of a
     * second is stored without it and would miss here. That is a reason for a
     * period to be a boundary rather than a reading of the clock, and is written
     * down in {@see Occurrence}.
     */
    private static function identity(string $subject, \DateTimeImmutable $period): string
    {
        return $subject . "\0" . $period->getTimestamp();
    }
}

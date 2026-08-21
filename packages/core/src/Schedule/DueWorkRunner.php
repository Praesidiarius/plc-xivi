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

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Xivi\Core\Metadata\MetadataRepository;

/**
 * **One tenant's turn of the clock** (XIV-155, docs/architecture/extensibility.md
 * §6.7).
 *
 * The engine asks every declared {@see RecurringWork} what is outstanding in this
 * customer's database, drops what it has already done, applies the declaration's
 * {@see CatchUp}, and runs the rest. The walk across customers is somebody else's
 * job, `tenant:work:run` in the control plane, and the split is deliberate:
 * this class knows nothing about a registry, a switcher or an exit code, and can
 * therefore be pointed at one tenant by a test as easily as by cron.
 *
 * ## The promise, in one sentence, because a clock that implies one is worse than one that states a small one
 *
 * **An occurrence that is outstanding at the moment this runs, in a tenant whose
 * module is installed, either happens exactly once or is reported as failed.**
 *
 * Everything that sentence does not say is deliberate, and the four things it
 * most conspicuously does not say are these.
 *
 * **It does not promise punctuality.** Nothing runs between requests in this
 * deployment (§9.2), so work happens when cron next calls this, which §4.5's
 * list suggests is hourly, and never at the instant it fell due. A module that needs
 * something to happen *at* 09:00 rather than *after* 09:00 cannot have it here,
 * and should not be given a worker to get it.
 *
 * **It does not promise a run at all.** A crontab that was never installed, a
 * machine that is off, an operator who removed the line: the clock is outside
 * this repository and §4.5 is honest that a job nobody configured a check for
 * can stop silently. What this side does is make the *catching up* well defined
 * when it starts again, which is what {@see CatchUp} is.
 *
 * **It does not promise anything about effects outside this database.** See
 * below: the guarantee is manufactured out of a transaction, and a sent mail is
 * not in one.
 *
 * **It does not promise ordering across tenants or across jobs.** Within one
 * subject of one job it does: oldest period first, and a failure stops that
 * subject's later periods for this run. That last rule is not tidiness. It is
 * what stops August's invoice taking July's place in a numbered series (§5.10)
 * because July's generation happened to fail.
 *
 * ## Running twice, and why the answer is a record rather than idempotent work
 *
 * XIV-155 asks for one or the other and for the choice to be deliberate. It is a
 * record, for the plain reason that the alternative cannot be delegated: "make it
 * idempotent" is an instruction to every module author for ever, checked by
 * nobody, and the first one who reads it as "probably fine" issues a second
 * invoice for August. A record is checked by a unique index in the database, once,
 * for all of them.
 *
 * **And the record is written in the same transaction as the work.** That is the
 * whole design, and it is why {@see DueWorkLog::claim()} is called from inside
 * {@see Connection::transactional()} here rather than before it:
 *
 * - the work commits, and the record commits with it. The occurrence is done and
 *   can never be offered again;
 * - the work throws, or the process is killed, and the record goes with it. The
 *   occurrence is outstanding again and the next run picks it up. **An attempt is
 *   not a run**, which is the property a claim-then-execute design gives away and
 *   then has to buy back with lease timeouts and a reaper;
 * - two processes reach the same occurrence together, and one of them waits on
 *   the other's row lock and is then told there was nothing to insert. It does not
 *   run the work. See {@see DueWorkLog} for why that is one statement.
 *
 * The cost is stated rather than hidden: **this makes at-most-once true for
 * effects that live in the tenant's database, and says nothing about anything
 * else.** A `run()` that sends mail may send it twice, because a mail cannot be
 * rolled back, and neither of the two consumers this was built for sends
 * anything ([XIV-156] produces a draft invoice, [XIV-157] extends a term), so the
 * seam is not being sized for a case nobody has. When one arrives it will want
 * the two-phase shape §5.15 already uses for the invoice mail, not a change here.
 *
 * ## What a tenant that has moved on looks like from here
 *
 * §6.1 is the rule: once a module is installed the customer's own definitions are
 * the truth, and the blueprint in the build is only a seed. So two things that
 * look like errors are ordinary and are handled rather than reported.
 *
 * **The module is not installed in this tenant.** Every bundle is loaded for
 * every customer, so a declaration exists in tenants that never bought it. It is
 * skipped on {@see RecurringWork::module()} before anything is asked, which
 * costs one cached metadata lookup.
 *
 * **The customer changed the shape the declaration reads.** A field renamed in
 * the editor, a collection removed, a choice value that no longer exists: the
 * module's query throws, and that is a report about one job in one tenant, not
 * the end of the run. The other jobs run, the other tenants run, and the failure
 * is named with both the tenant and the job so somebody can go and look.
 *
 * **The honest limit in that recovery**: a failure that closes the tenant's
 * entity manager, a flush that violated a constraint, leaves the rest of that
 * tenant's occurrences failing too, and they are reported as such. Reopening it
 * would mean this class knowing what a manager registry is and what the tenant
 * manager is called, which is application knowledge core deliberately does not
 * have; the walk resets it at the next tenant anyway ({@see \App\Tenancy\TenantSwitcher}),
 * and the occurrences are outstanding rather than lost.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DueWorkRunner
{
    /**
     * @param iterable<RecurringWork> $work every declaration in the build, from
     *                                      the container's tag. Empty is a
     *                                      perfectly good build
     */
    public function __construct(
        #[AutowireIterator(RecurringWork::TAG)]
        private iterable $work,
        private MetadataRepository $modules,
        private DueWorkLog $log,
        private Connection $connection,
    ) {
    }

    /**
     * Run everything this tenant has outstanding.
     *
     * @param \DateTimeImmutable $now  the instant the whole walk is about, taken
     *                                 once by the caller so that fifty customers
     *                                 are asked about the same moment and a walk
     *                                 that takes twenty minutes cannot have one
     *                                 tenant on either side of a boundary
     * @param \DateTimeZone      $zone this tenant's clock (§8.4.4 with nobody
     *                                 reading), handed to every declaration
     * @param string|null        $only one work's {@see RecurringWork::key()},
     *                                 for retrying one thing without running the
     *                                 rest
     */
    public function run(\DateTimeImmutable $now, \DateTimeZone $zone, ?string $only = null): WorkReport
    {
        $ran = [];
        $passed = [];
        $failures = [];

        foreach ($this->work as $work) {
            $job = $work->key();

            if ($only !== null && $only !== $job) {
                continue;
            }

            // Before anything is asked, and before the module's own code runs at
            // all: a customer who never bought this module has no shape for it to
            // read and no business being asked about it (§6.1).
            if (!$this->modules->isInstalled($work->module())) {
                continue;
            }

            try {
                $due = $this->outstanding($work, $now, $zone);
            } catch (\Throwable $e) {
                $failures[] = WorkFailure::asking($job, $e);

                continue;
            }

            if ($due === []) {
                continue;
            }

            if ($work->catchUp() === CatchUp::OnlyTheLatest) {
                // Everything but the newest occurrence of each subject is written
                // off here, one row each, so that it is not offered again on
                // every run from now until somebody deletes the definition. See
                // CatchUp::OnlyTheLatest for why a skip has to be written down.
                [$due, $superseded] = self::keepOnlyTheLatest($due);

                foreach ($superseded as $occurrence) {
                    try {
                        if ($this->log->claim($job, $occurrence, $now, DueWorkLog::PASSED)) {
                            $passed[] = sprintf('%s: %s', $job, $occurrence->describe());
                        }
                    } catch (\Throwable $e) {
                        $failures[] = WorkFailure::running($job, $occurrence, $e);
                    }
                }
            }

            $stalled = [];

            foreach ($due as $occurrence) {
                // One subject's periods are a sequence, and running the later one
                // after the earlier one failed puts a document out of order in a
                // numbered series it can never be moved back into (§5.10). So a
                // failed subject stops here for this run and resumes, in order,
                // on the next one. Other subjects are unaffected, which is the
                // same rule tenant:migrate applies to customers.
                if (isset($stalled[$occurrence->subject])) {
                    continue;
                }

                try {
                    $done = $this->connection->transactional(
                        function () use ($work, $job, $occurrence, $now): bool {
                            // Inside the transaction, and the first thing in it.
                            // See the class docblock: this is what makes an
                            // attempt not count as a run.
                            if (!$this->log->claim($job, $occurrence, $now)) {
                                return false;
                            }

                            $work->run($occurrence);

                            return true;
                        },
                    );

                    if ($done) {
                        $ran[] = sprintf('%s: %s', $job, $occurrence->describe());
                    }
                } catch (\Throwable $e) {
                    $failures[] = WorkFailure::running($job, $occurrence, $e);
                    $stalled[$occurrence->subject] = true;
                }
            }
        }

        return new WorkReport($ran, $passed, $failures);
    }

    /**
     * What this work has outstanding, oldest first.
     *
     * The sort is the engine's rather than the declaration's because the order is
     * a guarantee this class makes and would otherwise be re-implemented, and
     * occasionally forgotten, once per module.
     *
     * @return list<Occurrence>
     */
    private function outstanding(RecurringWork $work, \DateTimeImmutable $now, \DateTimeZone $zone): array
    {
        $due = iterator_to_array($work->due($now, $zone), false);

        usort(
            $due,
            static fn (Occurrence $a, Occurrence $b): int => $a->period <=> $b->period,
        );

        return $this->log->outstanding($work->key(), $due);
    }

    /**
     * Split a backlog into the newest occurrence of each subject and the rest.
     *
     * Per subject rather than per job, which is the detail that decides whether
     * this is right: a work kind recurring once per record has many subjects, and
     * keeping only the newest occurrence *overall* would write off every other
     * record's outstanding work as though a different record's newer period had
     * covered it.
     *
     * @param list<Occurrence> $due oldest first
     *
     * @return array{list<Occurrence>, list<Occurrence>} the ones to run, and the
     *                                                   ones to write off
     */
    private static function keepOnlyTheLatest(array $due): array
    {
        $latest = [];
        foreach ($due as $occurrence) {
            $latest[$occurrence->subject] = $occurrence;
        }

        // Sorted again, because `$latest` came out keyed by subject in the order
        // each subject was *first* seen, which is by its oldest occurrence, not
        // by the one being kept. Without this the survivors run in an order that
        // depends on how far back each definition's backlog reaches, which is
        // both arbitrary and, being arbitrary, the kind of thing a test pins by
        // accident.
        $run = array_values($latest);
        usort($run, static fn (Occurrence $a, Occurrence $b): int => $a->period <=> $b->period);

        $superseded = array_values(array_filter(
            $due,
            static fn (Occurrence $occurrence): bool => $latest[$occurrence->subject] !== $occurrence,
        ));

        return [$run, $superseded];
    }
}

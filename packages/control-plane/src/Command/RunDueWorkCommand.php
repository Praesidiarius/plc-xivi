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

namespace Xivi\ControlPlane\Command;

use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Settings\DisplayTimezone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\Core\Schedule\DueWorkRunner;
use Xivi\Core\Schedule\WorkFailure;
use Xivi\Core\Schedule\WorkReport;

/**
 * **The outside half of the engine's clock** (XIV-155,
 * docs/architecture/extensibility.md §6.7, docs/architecture/deployment.md §4.5).
 *
 * Cron calls this; it walks every customer, and inside each one
 * {@see DueWorkRunner} asks the modules what is outstanding and does it. There is
 * no worker and no consumer to put this on. §9.2 decided classic-mode FrankenPHP
 * and §4.5 rejected `symfony/scheduler` for wanting exactly the process this
 * runtime does not have, so "on a schedule" means what it has meant on a server
 * for forty years: a line in a crontab, printed by `deploy:crontab` out of
 * {@see \App\Monitoring\ScheduledJobs}, watched by the absence of a ping.
 *
 * ## Why the walk is here rather than in the engine
 *
 * The engine has no idea what a tenant is, on purpose (§3), and the runner it
 * owns handles exactly one customer's database. Everything this class adds is
 * the part that is *about* customers: which ones there are, how to get into one,
 * what to say when a customer's own database is unreachable, and what number to
 * exit with so a script can tell "nothing to do" from "one of your fifty is
 * broken". That is `tenant:migrate`'s shape, reused rather than reinvented, down
 * to the exit codes.
 *
 * ## The three exit codes, which are §4.2's and are a contract
 *
 *   0  {@see Command::SUCCESS}      every customer asked was walked. Whether
 *                                   anything was due is not this number's business
 *   1  {@see Command::FAILURE}      the run could not happen, because the slug
 *                                   named nothing. Nothing was attempted
 *   3  {@see self::TENANT_FAILED}   the run happened, and at least one customer
 *                                   had something fail. The rest are fine
 *
 * ## An empty registry is 0 here, and 1 in `tenant:migrate`
 *
 * That difference is deliberate and it is the one place this command departs from
 * the shape it copied. `tenant:migrate` exits 1 on an empty registry because it
 * runs inside `bin/deploy`, once per release, where "we appear to have no
 * customers" is either a catastrophe or a fact somebody should state out loud
 * with `--allow-empty`; stopping a deploy to ask is cheap and right.
 *
 * **This runs every hour, unattended, for ever.** An installation waiting for its
 * first self-service signup (§8.14) is a real state that lasts as long as it
 * takes somebody to fill in a form, and a clock that mailed the operator a
 * failure every hour throughout it would train them to filter its mail, which is
 * the same failure §4.5 is about, arriving through the channel built to prevent
 * it. Nothing here changes anything, nothing downstream depends on it having run
 * over a customer, and the catastrophic reading of an empty registry is already
 * caught once per release by the deploy. So: nothing to do, said in a sentence,
 * exit 0.
 *
 * **`--slug` is not covered by that**, for `tenant:migrate`'s reason: a slug
 * nothing answers to is a typo or a customer who has gone missing, never an
 * installation that is empty on purpose.
 *
 * ## What "now" is, and what zone the schedule is read in
 *
 * The instant is taken **once**, before the walk, and every customer is asked
 * about that same instant. A walk over fifty databases takes as long as it takes,
 * and a run that read the clock per tenant could put the first customer on one
 * side of a month boundary and the last on the other: an invoice dated the 31st
 * for one and the 1st for the next, from a single run, for no reason anybody
 * could ever reconstruct.
 *
 * The **zone** is resolved per customer, and it is the customer's own: §8.4.4's
 * chain with nobody reading, which is {@see DisplayTimezone::fallbackFor()}:
 * the installation's setting (§8.6), then what its country implies where that is
 * unambiguous, then UTC. Deliberately *not* `of()`: that chain starts with a
 * person's own preference, and a scheduled boundary must not depend on who
 * happened to run the command. Deliberately not the server's zone either, because
 * "the first of the month" is a fact about the customer's calendar and a Zurich
 * business billing on the 1st at UTC midnight bills on the 31st.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:work:run',
    description: 'Run the recurring work each tenant has outstanding',
)]
final readonly class RunDueWorkCommand
{
    /**
     * At least one customer had something fail, and the rest did not.
     *
     * The same 3, chosen for the same reason `tenant:migrate` chose it: 2 is
     * {@see Command::INVALID} everywhere in Symfony and means "you typed the
     * command wrong", which is not what a customer's failed invoice generation
     * is.
     */
    public const int TENANT_FAILED = 3;

    public function __construct(
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
        private DueWorkRunner $runner,
        private DisplayTimezone $timezones,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Run only this tenant')]
        ?string $slug = null,
        #[Option(description: 'Run only this work, by the key its module declared')]
        ?string $job = null,
    ): int {
        $tenants = $slug !== null
            ? array_values(array_filter([$this->tenants->findOneBySlug($slug)]))
            : $this->tenants->findAllOrdered();

        if ($tenants === []) {
            if ($slug !== null) {
                $io->error(sprintf('No tenant with slug "%s".', $slug));

                return Command::FAILURE;
            }

            // See the class docblock: this is the one exit code that is not
            // tenant:migrate's, and the argument is that this runs hourly and
            // unattended.
            $io->success('No tenants yet, so nothing is due. Nothing to run.');

            return Command::SUCCESS;
        }

        // Once, for the whole walk. See the class docblock.
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $failed = [];
        $ran = 0;
        $skipped = 0;

        foreach ($tenants as $tenant) {
            // **A customer who is not being served is not being billed either.**
            // Two states and two different reasons, both ending here.
            // `provisioning` is a database that may not exist yet or may be
            // half-migrated (§4.1), and asking it hourly what is due would turn
            // wreckage from a died run into a failure mail every hour until
            // somebody clears it. `suspended` is a deliberate decision that this
            // customer's instance does nothing, unpaid or disputed or on hold,
            // and a clock that went on raising their invoices and renewing their
            // memberships while their own staff cannot sign in would be that
            // decision quietly not taken. §4.6 points the same way: what a lapsed
            // customer loses is writing.
            //
            // They are counted rather than listed. A suspended customer is not a
            // problem to be reported every hour; it is a state somebody chose,
            // and the tenant list is where it is looked at.
            if (!$tenant->getStatus()->servesRequests()) {
                ++$skipped;

                continue;
            }

            try {
                $report = $this->switcher->runFor(
                    $tenant,
                    // The zone is read *inside* the tenant, because that is the
                    // only place the customer's own profile is readable at all.
                    fn (): WorkReport => $this->runner->run($now, $this->timezones->fallbackFor(), $job),
                );
            } catch (\Throwable $e) {
                // The customer's database could not be reached, or the switch
                // itself failed. One unreachable database must not cost the other
                // forty-nine their invoices, which is `tenant:migrate`'s rule and
                // the reason this catches at all.
                $failed[$tenant->getSlug()] = [$e->getMessage()];
                $io->writeln(sprintf('<error>%s</error>: %s', $tenant->getSlug(), $e->getMessage()));

                continue;
            }

            $ran += \count($report->ran);

            // Most customers on most hours have nothing due, and fifty lines
            // saying so every hour is a report whose real lines nobody finds.
            if (!$report->isQuiet()) {
                self::describe($io, $tenant->getSlug(), $report);
            }

            if ($report->failed()) {
                $failed[$tenant->getSlug()] = array_map(
                    static fn (WorkFailure $failure): string => $failure->describe(),
                    $report->failures,
                );
            }
        }

        $walked = \count($tenants) - $skipped;

        if ($failed !== []) {
            return $this->reportFailures($io, $failed, $walked, $ran, $job);
        }

        $io->success(sprintf(
            '%d tenant(s) walked, %d occurrence(s) run.%s',
            $walked,
            $ran,
            $skipped === 0 ? '' : sprintf(' %d not serving requests and skipped.', $skipped),
        ));

        return Command::SUCCESS;
    }

    /**
     * One customer's lines, when there are any.
     *
     * Every line names the occurrence in full, including the offset its period
     * was computed at, because the one thing somebody reading this output is
     * usually checking is whether a boundary landed on the customer's clock or on
     * the server's.
     */
    private static function describe(SymfonyStyle $io, string $slug, WorkReport $report): void
    {
        foreach ($report->ran as $line) {
            $io->writeln(sprintf('<info>%s</info>: %s', $slug, $line));
        }

        foreach ($report->passed as $line) {
            $io->writeln(sprintf('<comment>%s</comment>: passed over, %s', $slug, $line));
        }
    }

    /**
     * What a run with failures in it says, and why it says so much.
     *
     * This is the message that lands in an operator's mail via cron and is read
     * an hour later by somebody with no context, so it carries both halves of the
     * count. That is `tenant:migrate`'s lesson: "3 of 50 failed" without "47 are
     * fine" reads as a run that did nothing, and the difference decides whether the
     * answer is to panic or to fix three databases.
     *
     * **Nothing here is lost.** A failed occurrence rolled back with its record,
     * so it is outstanding again and the next hour's run offers it; the retry
     * lines are for when somebody wants it now, or wants to watch it fail with
     * their own eyes.
     *
     * @param array<string, list<string>> $failed slug to what went wrong in it
     */
    private function reportFailures(SymfonyStyle $io, array $failed, int $total, int $ran, ?string $job): int
    {
        $io->section('Could not be run');

        foreach ($failed as $slug => $reasons) {
            foreach ($reasons as $reason) {
                $io->text(sprintf(' <error>%s</error>: %s', $slug, $reason));
            }
        }

        $healthy = $total - \count($failed);

        $io->newLine();
        $io->error(sprintf(
            '%d of %d tenant(s) had work fail; the other %d %s fine, and %d occurrence(s) ran.',
            \count($failed),
            $total,
            $healthy,
            $healthy === 1 ? 'is' : 'are',
            $ran,
        ));

        $io->writeln('Nothing is lost: what failed is outstanding again and the next run picks it up.');
        $io->writeln('To try one now:');

        foreach (array_keys($failed) as $slug) {
            $io->writeln(sprintf(
                '    bin/console tenant:work:run --slug=%s%s',
                $slug,
                $job !== null ? sprintf(' --job=%s', $job) : '',
            ));
        }

        $io->newLine();
        $io->writeln(sprintf(
            'Exit code %d means a tenant failed. That is deliberately not the %d this command '
            . 'exits with when it could not run at all.',
            self::TENANT_FAILED,
            Command::FAILURE,
        ));

        return self::TENANT_FAILED;
    }
}

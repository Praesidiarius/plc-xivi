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

namespace App\Command;

use App\Monitoring\PingTargets;
use App\Monitoring\ScheduledJob;
use App\Monitoring\ScheduledJobs;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The crontab this build needs, and which of its jobs anything is watching
 * (XIV-126, docs/architecture.md §4.5).
 *
 * ## Why the crontab is printed rather than described
 *
 * §4.2 already made this argument about `bin/deploy` and it transfers whole: a
 * runbook lives somewhere else and is edited by somebody who is not looking at
 * this branch, which is how a deploy comes to run last month's steps against
 * this month's migrations. A *crontab* is the same object one step further away
 * — it lives on a machine, it was pasted out of a documentation page at some
 * release or other, and nothing ever revisits it.
 *
 * That had already cost something by the time this was written. The
 * documentation site said "the two cron entries an installation needs" while
 * there were three, and the missing one was `tenant:purchase:collect`: a
 * customer pressing *ask about this module* inside their own installation, and
 * an operator screen that would never show it. Nothing was broken, nothing was
 * logged, and there was no state anywhere that differed from a healthy
 * installation with no requests in it.
 *
 * So the list is {@see ScheduledJobs}, in the build, and this prints it. Adding
 * a scheduled command is adding an entry there, and every installation's next
 * `deploy:crontab` says so — including the ones whose operator does not read
 * release notes.
 *
 * ## Everything on stdout is a crontab
 *
 * No banners, no boxes, no colour: the diagnostics are `#` comments beside the
 * lines they are about, so the whole output can be redirected into
 * `/etc/cron.d/xivi` and stay readable a year later. A report that has to be
 * retyped to be used is a report somebody retypes wrongly.
 *
 * **The ping URLs are deliberately not among those comments.** A crontab is
 * world-readable on most machines, and a ping URL is a bearer token in URL
 * form — anybody holding one can report that a job succeeded, which is precisely
 * how you would silence this. The comment says *watched* or *not watched* and
 * nothing more.
 *
 * ## What it asks the console, and why
 *
 * `packages/control-plane` is absent from the customer-facing image (§4.4) and
 * all three of today's scheduled jobs are its commands, so on that image none of
 * them exist. Rather than print lines that would fail, this asks the command
 * loader which of them are actually present and says so about the rest. The
 * customer-facing image therefore prints a crontab with nothing in it, which is
 * the truth: it has no scheduled jobs, and `bin/deploy` refuses to run there for
 * the same reason.
 *
 * ## The exit codes
 *
 * Borrowed from `tenant:migrate` and `deploy:check-hosts` (§4.2), so a deploy
 * script reads all three the same way:
 *
 * | code | meaning |
 * | --- | --- |
 * | 0 | every job this image has is watched — or nothing is, which is monitoring switched off rather than misconfigured |
 * | 3 | some are watched and some are not, or something watched is not a command here |
 *
 * **Nothing watched at all exits 0**, and that is a decision rather than an
 * oversight. Empty configuration is the shipped default and a legitimate choice
 * (§4.5); a command that failed on it would be a command every fresh
 * installation sees fail, which is how a check comes to be run with `|| true`.
 * What 3 reports is the *inconsistent* state — an operator who set this up and
 * missed one — because that is the state where somebody believes they are
 * covered.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'deploy:crontab',
    description: 'Print the cron entries this installation needs, and say which of them are monitored',
)]
final readonly class PrintCrontabCommand
{
    /**
     * Some jobs are watched and some are not, or a watched name is not a command
     * here.
     *
     * Three for the reason `tenant:migrate` gives: `Command::INVALID` is 2 and
     * means "you typed the command wrong" everywhere else in Symfony.
     */
    public const int INCOMPLETE = 3;

    public function __construct(
        private ScheduledJobs $jobs,
        private PingTargets $targets,
        #[Autowire(service: 'console.command_loader')]
        private CommandLoaderInterface $commands,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The directory the cron entries should cd into, if not this one')]
        ?string $directory = null,
    ): int {
        $directory ??= $this->projectDir;

        $present = [];
        $missing = [];

        foreach ($this->jobs->all() as $job) {
            if ($this->commands->has($job->command)) {
                $present[] = $job;
            } else {
                $missing[] = $job;
            }
        }

        $watched = array_values(array_filter(
            $present,
            fn (ScheduledJob $job): bool => $this->targets->for($job->command) !== null,
        ));

        // Anything configured that this image cannot run. Almost always a typo
        // or a command renamed a release ago — and the reason it is worth
        // saying out loud is that its symptom is *silence at the monitoring
        // service*, which looks exactly like a job that stopped. Somebody would
        // spend an afternoon on the cron before suspecting the spelling.
        $unknown = array_values(array_filter(
            $this->targets->commands(),
            fn (string $command): bool => !$this->commands->has($command),
        ));

        $this->header($io, \count($present), \count($watched));

        foreach ($present as $job) {
            $io->writeln('');
            $io->writeln('# ' . $job->stale);
            $io->writeln($this->targets->for($job->command) !== null
                ? '# Watched: a monitoring service is told when this runs, and will raise an alert when it stops.'
                : sprintf(
                    '# NOT WATCHED: nothing will notice if this stops. Add "%s=<ping url>" to %s.',
                    $job->command,
                    PingTargets::VARIABLE,
                ));
            $io->writeln(sprintf(
                '%s cd %s && bin/console %s',
                $job->schedule,
                $directory,
                $job->command,
            ));
        }

        $this->footer($io, $missing, $unknown);

        return $unknown !== [] || (\count($watched) > 0 && \count($watched) < \count($present))
            ? self::INCOMPLETE
            : Command::SUCCESS;
    }

    /**
     * The two sentences somebody reads before the lines: what this is, and how
     * much of it anything is watching.
     */
    private function header(SymfonyStyle $io, int $jobs, int $watched): void
    {
        $io->writeln('# The cron entries this build of Xivi needs (docs/architecture.md §4.5).');
        $io->writeln('# Printed by `bin/console deploy:crontab`, from the list that ships with the');
        $io->writeln('# release, so it is what this version needs rather than what a runbook remembers.');
        $io->writeln('#');
        $io->writeln('# The cadences below are suggestions and nothing enforces them: every one of');
        $io->writeln('# these jobs stamps what it writes with when it ran, so an installation that');
        $io->writeln('# runs one hourly and one that runs it weekly both tell the truth about');
        $io->writeln('# themselves.');
        $io->writeln('#');

        if ($jobs === 0) {
            // The customer-facing image, or a build without the administration
            // surface for some other reason. Said plainly rather than as an
            // empty output, which reads as a bug.
            $io->writeln('# This image has none of them — see the note at the end.');

            return;
        }

        if ($watched === 0) {
            $io->writeln(sprintf(
                '# Monitoring is OFF: none of these %d job(s) is watched. Nothing in Xivi notices',
                $jobs,
            ));
            $io->writeln('# when one stops running, and the screens built on them go stale quietly.');
            $io->writeln(sprintf('# Switch it on with %s — see .env for how.', PingTargets::VARIABLE));

            return;
        }

        $io->writeln(sprintf('# Monitoring: %d of %d job(s) watched.', $watched, $jobs));
    }

    /**
     * The two things that are wrong rather than merely unset, printed after the
     * lines so that the pasteable part is not interrupted by them.
     *
     * @param list<ScheduledJob> $missing jobs this image does not contain
     * @param list<string>       $unknown watched names that are not commands here
     */
    private function footer(SymfonyStyle $io, array $missing, array $unknown): void
    {
        if ($missing !== []) {
            $io->writeln('');
            $io->writeln('# Not in this image, so there is nothing to schedule here:');

            foreach ($missing as $job) {
                $io->writeln(sprintf('#   %s', $job->command));
            }

            $io->writeln('#');
            $io->writeln('# That is expected on the customer-facing build, which is compiled without');
            $io->writeln('# the administration surface (§4.4). Schedule these out of the internal');
            $io->writeln('# image, the one `bin/deploy` also runs from.');
        }

        if ($unknown !== []) {
            $io->writeln('');
            $io->writeln(sprintf(
                '# %s names something this image has no command for:',
                PingTargets::VARIABLE,
            ));

            foreach ($unknown as $command) {
                $io->writeln(sprintf('#   %s', $command));
            }

            $io->writeln('#');
            $io->writeln('# Nothing will ever ping those URLs, so the checks behind them will alert');
            $io->writeln('# for a job that was never going to report — which looks exactly like a job');
            $io->writeln('# that stopped. Fix the spelling, or delete the entry and its check.');
        }
    }
}

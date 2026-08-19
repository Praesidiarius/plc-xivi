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

namespace App\Tests\Functional\Monitoring;

use App\Command\PrintCrontabCommand;
use App\Monitoring\EventListener\JobMonitorSubscriber;
use App\Monitoring\ScheduledJobs;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * That the list of scheduled jobs is a list of real commands, and that the
 * report about them is honest (XIV-126, docs/architecture.md §4.5).
 *
 * ## Why the list needs a test at all
 *
 * {@see ScheduledJobs} is three strings, and a list of strings is exactly the
 * kind of thing that goes quietly wrong. Rename a command and the entry here
 * still parses, `deploy:crontab` still prints a line, an operator still pastes
 * it into a crontab — and the job silently never runs, because `bin/console`
 * exits 1 on an unknown command every five minutes into a mailbox nobody reads.
 * The monitoring built on top would then report the *absence* correctly and
 * point at a job that no longer exists under that name.
 *
 * That is a failure mode with no symptom until somebody looks at a screen, which
 * is the sentence this whole ticket exists to stop being true.
 *
 * ## And why the subscriber's registration does
 *
 * The other half is one line of wiring: a console listener that is not attached
 * makes no requests, breaks no test that does not look for it, and produces an
 * installation whose monitoring configuration is read, validated, and never
 * acted on. `deptrac` was green for four months for the same reason, and §4.2's
 * additive-migration rule was written down four times and checked zero — so an
 * assertion that the listener is *actually on the dispatcher* is the cheap half
 * of a lesson this project has already paid for twice.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ScheduledJobsAreWatchableTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        // The parameter is `%env(csv:XIVI_MONITOR_PINGS)%` and env placeholders
        // are resolved from the superglobals at run time rather than baked into
        // the compiled container, which is what lets a test configure one — and
        // is also what obliges it to put the variable back, since the process is
        // shared with every other test in this worker.
        unset($_SERVER['XIVI_MONITOR_PINGS'], $_ENV['XIVI_MONITOR_PINGS']);
        $_ENV['XIVI_MONITOR_PINGS'] = '';

        parent::tearDown();
    }

    public function testEveryScheduledJobIsACommandThatExists(): void
    {
        $application = new Application(self::bootKernel());

        foreach ((new ScheduledJobs())->all() as $job) {
            self::assertTrue(
                $application->has($job->command),
                sprintf(
                    'ScheduledJobs names "%s", which is not a command. An operator would paste '
                    . 'that into a crontab and it would fail every run.',
                    $job->command,
                ),
            );
        }
    }

    /**
     * The one thing that makes this feature "in one place" rather than "in three
     * commands somebody remembered".
     */
    public function testTheConsoleListenerIsAttached(): void
    {
        self::bootKernel();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        foreach ([ConsoleEvents::COMMAND, ConsoleEvents::TERMINATE] as $event) {
            $attached = false;

            foreach ($dispatcher->getListeners($event) as $listener) {
                // A method listener is `[$service, 'method']` once the
                // dispatcher has resolved it, which is what a listener declared
                // with `#[AsEventListener]` on a method becomes.
                if (\is_array($listener) && $listener[0] instanceof JobMonitorSubscriber) {
                    $attached = true;
                }
            }

            self::assertTrue(
                $attached,
                sprintf('Nothing is listening for %s, so no job is ever monitored.', $event),
            );
        }
    }

    /**
     * The shipped default. Nothing is watched, which is a choice rather than a
     * misconfiguration, so it is not reported as a failure — see the command's
     * docblock for why a check that fails on a fresh installation is a check
     * that ends up being run with `|| true`.
     */
    public function testWithNothingConfiguredItPrintsEveryJobAndSaysMonitoringIsOff(): void
    {
        $tester = $this->crontab();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $output = $tester->getDisplay();
        self::assertStringContainsString('Monitoring is OFF', $output);

        foreach ((new ScheduledJobs())->all() as $job) {
            self::assertStringContainsString($job->command, $output);
            self::assertStringContainsString($job->schedule, $output);
            self::assertStringContainsString($job->stale, $output);
        }
    }

    /**
     * **The gap this ticket is about, made visible.** Watching two of three jobs
     * is the state an operator lands in by setting monitoring up once and then
     * shipping a fourth scheduled command, and it is the state that looks
     * exactly like being covered — every check green, and the one that is not
     * there cannot be missing.
     */
    public function testWatchingSomeJobsAndNotOthersIsReportedAsIncomplete(): void
    {
        $tester = $this->crontab('signup:provision=https://hc-ping.example/aaaa');

        self::assertSame(PrintCrontabCommand::INCOMPLETE, $tester->getStatusCode());

        $output = $tester->getDisplay();
        // The total comes from the list rather than from a literal: a ticket that
        // adds a scheduled command should not have to remember to edit a number
        // in a test whose subject is that nobody remembers things (XIV-123 added
        // the fourth).
        self::assertStringContainsString(
            sprintf('1 of %d job(s) watched', \count((new ScheduledJobs())->all())),
            $output,
        );
        self::assertStringContainsString('NOT WATCHED', $output);
    }

    public function testWatchingEveryJobIsReportedAsComplete(): void
    {
        $tester = $this->crontab(implode(',', array_map(
            static fn (string $command): string => $command . '=https://hc-ping.example/' . md5($command),
            (new ScheduledJobs())->commands(),
        )));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $count = \count((new ScheduledJobs())->all());

        self::assertStringContainsString(
            sprintf('%1$d of %1$d job(s) watched', $count),
            $tester->getDisplay(),
        );
        self::assertStringNotContainsString('NOT WATCHED', $tester->getDisplay());
    }

    /**
     * A watched name that is not a command is the failure that looks most like a
     * success: the check is created, the URL is configured, and the service
     * alerts for ever about a job that was never going to report.
     */
    public function testAWatchedNameThatIsNotACommandIsNamed(): void
    {
        $tester = $this->crontab('tenant:usage:colect=https://hc-ping.example/aaaa');

        self::assertSame(PrintCrontabCommand::INCOMPLETE, $tester->getStatusCode());
        self::assertStringContainsString('tenant:usage:colect', $tester->getDisplay());
        self::assertStringContainsString('no command for', $tester->getDisplay());
    }

    /**
     * A ping URL is a bearer token in URL form: anybody holding one can report
     * that the job succeeded, which is exactly how somebody would silence this.
     * A crontab is world-readable on most machines, so the report says *watched*
     * and never says *watched, at this address*.
     */
    public function testTheCrontabNeverPrintsAPingUrl(): void
    {
        $tester = $this->crontab('signup:provision=https://hc-ping.example/s3cr3t-uuid');

        self::assertStringNotContainsString('s3cr3t-uuid', $tester->getDisplay());
        self::assertStringNotContainsString('hc-ping.example', $tester->getDisplay());
    }

    /**
     * Runs `deploy:crontab` against a freshly booted kernel with this value of
     * `XIVI_MONITOR_PINGS`.
     */
    private function crontab(string $pings = ''): CommandTester
    {
        self::ensureKernelShutdown();
        // Both superglobals, and `$_ENV` is the one that matters: Dotenv has
        // already populated it from the committed `.env`, and Symfony's env-var
        // processor reads `$_ENV` before `$_SERVER`, so setting only the latter
        // would be shadowed by the empty default and every assertion below would
        // pass for the wrong reason.
        $_ENV['XIVI_MONITOR_PINGS'] = $pings;
        $_SERVER['XIVI_MONITOR_PINGS'] = $pings;

        $tester = new CommandTester((new Application(self::bootKernel()))->find('deploy:crontab'));
        $tester->execute(['--directory' => '/srv/xivi']);

        return $tester;
    }
}

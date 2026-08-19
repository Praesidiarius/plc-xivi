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

namespace App\Monitoring;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tells an external monitor that a scheduled job started, and how it ended
 * (XIV-126, docs/architecture/deployment.md §4.5).
 *
 * ## The thing watching is not the thing being watched
 *
 * The previous generation of this product had a `BatchChecker`: every job wrote
 * a `<name>_lastrun` setting, and a daily task mailed the administrator about
 * any that had not run in twenty-four hours. **That shape is rejected here and
 * §4.5 records why**, because the flaw is the shape and not the implementation —
 * the checker is itself a scheduled job, so the failure it exists to catch is
 * the failure that stops it. A dead man's switch that dies with the patient
 * reports nothing, and reports it silently.
 *
 * This inverts it. The job pings a URL when it runs; the *service* alerts when a
 * ping does not arrive. Nothing on this machine has to survive for the alarm to
 * go off — the alarm is the absence of us, which is the one signal a stopped
 * cron, a full disk, a dead container and an unplugged server all produce
 * identically.
 *
 * ## What a ping contains
 *
 * The fact that it happened, and the exit code. That is the whole of it:
 *
 * - **A `GET`, with no body and no query string.**
 * - **No tenant slug, no customer name, no email address, no counts, no
 *   hostname.** A ping URL goes to a third party — possibly a hosted one — and
 *   §8.11's line about counts, not contents, is drawn a great deal further back
 *   here: *"the job ran"* is the entire payload. `tenant:usage:collect` knows how
 *   many customers this installation has and how many records are in each, and
 *   none of that leaves this method.
 * - **No version string.** The `User-Agent` is the bare word `Xivi`, which is
 *   there so an operator reading the request log of their *own* self-hosted
 *   Healthchecks can tell what pinged it. A version would turn every ping into a
 *   report of which release this installation is behind on, to whoever runs the
 *   monitor.
 *
 * What cannot be hidden is the source address, since a monitor by construction
 * receives a request from you. An installation for which that matters
 * self-hosts, which is the first reason §4.5 recommends the one service that can
 * be self-hosted.
 *
 * ## How an exit code is reported, and why [XIV-61]'s 3 survives it
 *
 * The protocol is Healthchecks': `<url>/start` when a job begins, and
 * `<url>/<exit-code>` when it ends, where the service reads 0 as success and
 * every other value in 0–255 as a failure **while recording the number itself**.
 * Better Stack's heartbeats speak it byte for byte.
 *
 * That last property is why the code is sent rather than a `/fail`, and it is
 * the whole of this ticket's exit-code requirement. `tenant:migrate` publishes
 * three codes on purpose (§4.2): 0 is every tenant current, 1 is a run that
 * could not happen at all, and 3 is a run that happened in which some tenants
 * failed and the rest are fine. A monitor told only "failed" would flatten the
 * second and third into each other; a monitor told "3" shows *3* in its event
 * log, so the person woken by it knows before they open a terminal whether they
 * are looking at a deploy that did nothing or at four customers on last week's
 * schema. The collectors publish 0 and 1 today and the same reading applies.
 *
 * And there is a fourth state, which is the one the whole arrangement is for:
 * **no ping at all**. A job that was never scheduled, whose cron died, whose
 * container was not replaced or whose machine is off sends nothing, and the
 * service raises that after the grace period. Silence is the alert.
 *
 * ## Why a failed ping is swallowed
 *
 * A ping that cannot be sent is logged at warning level and changes nothing —
 * not the exit code, not the output, not whether the job is considered to have
 * worked. Swallowing an error is usually the wrong instinct in this codebase
 * ([XIV-37] is emphatic that a failed send is never swallowed), and it is right
 * here for a reason specific to this shape: **the consequence of a lost ping is
 * that the monitor reports a missing ping**, which is exactly what it is for. The
 * failure announces itself at the far end. Contrast a mail, where swallowing
 * leaves nobody anywhere knowing.
 *
 * The opposite policy is the harmful one. Failing the job because its monitor was
 * unreachable would turn a five-second network problem at a third party into
 * `bin/deploy` exiting non-zero, or into a provisioning run that reports failure
 * for customers it provisioned correctly — a monitoring feature that can take the
 * product down is a monitoring feature somebody removes.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class JobMonitor
{
    /**
     * Seconds a ping is given, both to connect and in total.
     *
     * Short on purpose, and the number is a judgement about what is being
     * protected rather than about what is fast. This runs inside a cron job that
     * has just finished real work; a monitoring host that accepts the connection
     * and then never answers would otherwise hold that process open for the
     * client's default, and the next tick of the same cron entry would start
     * beside it. Five seconds is longer than any of these services takes to
     * answer a request whose entire semantics is "I received this", and short
     * enough that a hung one costs a job ten seconds a run rather than a
     * pile-up.
     */
    private const int TIMEOUT_SECONDS = 5;

    public function __construct(
        private PingTargets $targets,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * A watched job is about to run.
     *
     * The start ping is not required by any of these services and is sent
     * anyway, because it buys two things the completion ping alone cannot. It
     * gives the monitor the run's *duration*, so "the collection now takes
     * eleven minutes" is visible before it becomes "the collection did not
     * finish"; and it distinguishes a job that started and was killed — an OOM,
     * a machine that went away, a `tenant:migrate` sitting on a database that
     * will not answer — from one that never started, because the first leaves a
     * start with no end and the second leaves nothing. Those have different
     * causes and the monitor can only tell them apart if it is told.
     */
    public function started(string $command): void
    {
        $this->ping($command, 'start');
    }

    /**
     * A watched job has ended, with this exit code.
     *
     * Clamped into 0–255 because that is the range a process exit status has and
     * the range these services accept. Symfony clamps the same way before
     * returning to the shell, so a command that returned 300 exits 255 there and
     * is reported as 255 here; letting a raw value through would produce a ping
     * to a path the service answers 400 to, and a 400 to a *failure* ping is the
     * one response that must not be possible.
     */
    public function finished(string $command, int $exitCode): void
    {
        $this->ping($command, (string) max(0, min(255, $exitCode)));
    }

    /**
     * One request, or nothing at all when this command is not watched.
     *
     * The unwatched case is the shipped default and costs one array lookup, which
     * is what makes "an installation configuring nothing behaves exactly as
     * today" a property rather than a claim: with no configuration this class
     * never constructs a URL, never touches the HTTP client and never opens a
     * socket.
     */
    private function ping(string $command, string $suffix): void
    {
        $base = $this->targets->for($command);

        if ($base === null) {
            return;
        }

        $url = $base . '/' . $suffix;

        try {
            // `getStatusCode()` rather than firing and forgetting. Symfony's
            // HTTP client is lazy: the request is started by `request()` but the
            // response is only completed when something asks it a question, and
            // in a console command the thing that would eventually ask is the
            // destructor at the end of the process. Asking here is what makes
            // the ping ordered with respect to the job's own output, and it is
            // also the only way the failure below can be reported rather than
            // thrown from a destructor into nobody's hands.
            $status = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'headers' => ['User-Agent' => 'Xivi'],
            ])->getStatusCode();

            if ($status >= 400) {
                // A 404 here is the interesting one and is worth its own
                // sentence in the log rather than being folded into the
                // transport failure below: it almost always means the check was
                // deleted at the monitoring service while the URL stayed in
                // XIVI_MONITOR_PINGS, which is an installation that believes it
                // is watched and is not.
                $this->logger->warning(
                    'The monitoring ping for {command} was refused with HTTP {status}. '
                    . 'The job itself is unaffected; check that the URL in {variable} still '
                    . 'names a check that exists.',
                    ['command' => $command, 'status' => $status, 'variable' => PingTargets::VARIABLE],
                );
            }
        } catch (\Throwable $e) {
            // Deliberately everything, including the transport exceptions the
            // contracts declare. Whatever went wrong between here and the
            // monitor, the answer is the same: say so locally, change nothing,
            // and let the missing ping be the alert it is meant to be.
            $this->logger->warning(
                'The monitoring ping for {command} could not be sent: {reason}. The job itself '
                . 'is unaffected, and the monitor will report the missing ping.',
                ['command' => $command, 'reason' => $e->getMessage()],
            );
        }
    }
}

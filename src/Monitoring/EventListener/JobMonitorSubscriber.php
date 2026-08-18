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

namespace App\Monitoring\EventListener;

use App\Monitoring\JobMonitor;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * **The one place a job is monitored from** (XIV-126, docs/architecture.md
 * §4.5).
 *
 * ## Why this is a listener rather than three lines in three commands
 *
 * The obvious implementation is a ping at the top and bottom of each scheduled
 * command, and it is wrong for a reason this ticket is itself an example of:
 * **the fourth scheduled command would not have them, and nothing would say
 * so.** A monitoring feature whose coverage depends on the author of the next
 * command remembering has the same defect as the thing it is monitoring — it
 * fails by omission, quietly, and the way you find out is the incident it was
 * supposed to prevent. It had already happened once here: `tenant:purchase:collect`
 * shipped in [XIV-102] and reached neither the documentation's list of cron
 * entries nor anybody's crontab.
 *
 * So the console tells us instead. Every command that runs passes through
 * {@see ConsoleEvents::COMMAND} and {@see ConsoleEvents::TERMINATE}, this asks
 * whether that one is configured to be watched, and adding the ninth watched
 * command is adding an entry to `XIVI_MONITOR_PINGS`. Nothing is edited, nothing
 * is remembered, and a command that is not watched costs an array lookup.
 *
 * ## Terminate rather than the exit code the command returned
 *
 * {@see ConsoleTerminateEvent} fires for every ending a command has, which is
 * more endings than a return statement covers: a returned code, an uncaught
 * throwable — which reaches {@see ConsoleEvents::ERROR} first and arrives here
 * afterwards carrying the code the application decided on — and a command an
 * earlier listener disabled, which arrives as 113. All three are outcomes a
 * monitor should hear about, and reading the return value inside each command
 * would have seen only the first.
 *
 * The one ending it does *not* fire for is the process being killed outright:
 * `SIGKILL`, the OOM killer, the machine losing power. That is deliberate and it
 * is the good case. No terminate means no completion ping, the monitoring
 * service sees a start with no end, and it raises exactly the alert it should.
 * **The absence of a signal is the signal**, which is the property the rejected
 * in-house checker never had.
 *
 * ## Every command, not only the scheduled ones
 *
 * This listens for whatever is configured rather than only for
 * {@see \App\Monitoring\ScheduledJobs}'s three, and the difference is worth a
 * sentence. `tenant:migrate` is not a cron entry — `bin/deploy` runs it once per
 * release (§4.2) — and it is exactly the sort of thing an operator may want a
 * check on, because a deploy that quietly stopped being run is the same class of
 * silence. Restricting the map to the scheduled list would have refused that for
 * a tidiness nobody asked for. What the scheduled list *is* for is
 * `deploy:crontab`, which reports which of the jobs this build needs are being
 * watched and which are not.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class JobMonitorSubscriber
{
    public function __construct(
        private JobMonitor $monitor,
    ) {
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand()?->getName();

        if ($command !== null) {
            $this->monitor->started($command);
        }
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $command = $event->getCommand()?->getName();

        if ($command !== null) {
            // The exit code is read and never written. `setExitCode()` exists on
            // this event and calling it would let a monitoring concern change
            // what a deploy script sees, which is the one thing this feature must
            // never do — §4.2's three codes are a published contract and a
            // listener is not a party to it.
            $this->monitor->finished($command, $event->getExitCode());
        }
    }
}

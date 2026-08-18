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

/**
 * One command this build expects to find in a crontab (XIV-126,
 * docs/architecture.md §4.5).
 *
 * Three strings, and each of them is here because something outside this
 * repository has to be told it.
 *
 * `$command` is what goes in the crontab and is also the key
 * {@see PingTargets} is configured against, so the two cannot be spelled
 * differently. `$schedule` is a *suggestion* and nothing enforces it — every one
 * of these jobs is stamped with when it ran and the screens built on them say
 * how old what they show is (§8.11), so an installation that runs one hourly and
 * one that runs it weekly both tell the truth about themselves. What the
 * suggestion buys is that `deploy:crontab` prints something you can paste rather
 * than something you have to finish.
 *
 * `$stale` is the sentence an operator needs and the one a bare list of command
 * names cannot say: **what is wrong with this installation while the job is not
 * running.** It is written from the reader's side — what somebody sees on a
 * screen, or what a customer is waiting for — rather than from the code's, because
 * "the collector has not run" is a fact about us and "every usage figure reads
 * *not collected yet*" is a fact about them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ScheduledJob
{
    public function __construct(
        public string $command,
        public string $schedule,
        public string $stale,
    ) {
    }
}

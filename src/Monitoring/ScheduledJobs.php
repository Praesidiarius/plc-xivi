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
 * **What this build expects to be on a schedule** (XIV-126,
 * docs/architecture.md §4.5).
 *
 * There is no worker process in this deployment and no message consumer — the
 * constraint that made mail synchronous in [XIV-37], usage collection a cron job
 * in [XIV-59], purchase collection one in [XIV-102] and provisioning one in
 * [XIV-98]. So "periodically" is a line in somebody's crontab, and until this
 * class existed the set of lines that had to be there was written down in three
 * command docblocks, one `.env` comment and a documentation page, and agreed
 * with none of the others: the page said *"the two cron entries an installation
 * needs"* while there were three, and the third — `tenant:purchase:collect`,
 * which is a customer waiting to be sold something — was in no list at all.
 *
 * **That is this ticket's own failure mode, and it had already happened.** A job
 * nobody scheduled cannot stop running, because it never started; the monitoring
 * this class feeds would have reported nothing about it, because nothing would
 * have been configured to watch it. So the list comes first and the monitoring
 * hangs off it.
 *
 * ## Why the list is in code rather than in configuration
 *
 * Every other deployment fact in this application is an environment variable —
 * hostnames, trusted domains, the price currency — and this one deliberately is
 * not. **Which jobs exist is a property of the build, not of the deployment.**
 * A deployment decides *how often* to run them and *whether* to watch them; it
 * does not get to decide that `signup:provision` is optional, because a
 * confirmed signup then sits in a table for ever and the person who made it
 * waits for a mail that never comes. Putting the list in `.env` would make
 * forgetting an entry a supported configuration.
 *
 * It is the same argument §4.2 makes for `bin/deploy` being a file in this
 * repository rather than lines in a runbook: the sequence a release needs ships
 * *with* that release, so it can never be a version behind the code it is
 * running. Add a scheduled command, add it here, and `deploy:crontab` starts
 * printing it on the next deploy — including on installations whose operator
 * never read the release notes.
 *
 * ## Why the entries are strings and not class names
 *
 * All three commands live in `packages/control-plane`, and §4.4 forbids the
 * application from naming a class of that package: `docker build --target
 * frankenphp_public` builds an image without it, and a reference it cannot
 * resolve is a container that will not compile. A command *name* is a string —
 * it is the published identifier, it is what goes in a crontab, and resolving it
 * is the console's job at run time rather than the container's at build time. So
 * this list costs the customer-facing image nothing, and `deploy:crontab` asks
 * the console which of these the image it is running in actually has.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ScheduledJobs
{
    /**
     * In the order an operator meets them, which is the order of how much a
     * customer notices them stopping.
     *
     * @return list<ScheduledJob>
     */
    public function all(): array
    {
        return [
            // The one with somebody sitting on the other end of it. A confirmed
            // signup is a person who has clicked a link and been told their
            // installation is being made; §8.14 says the provisioning happens
            // here and nowhere else, so between the click and this run they have
            // nothing at all. Five minutes is the cadence the page already
            // suggests and the command's own docblock argues for.
            new ScheduledJob(
                'signup:provision',
                '*/5 * * * *',
                'a customer who confirmed their signup is waiting for an invitation that is never sent',
            ),

            // Also a customer waiting, one step further along: they have pressed
            // "ask about this module" inside their own installation and the
            // request is sitting in their database, where §4.4's grant obliges
            // it to be written and where no operator screen can see it. Ten
            // minutes rather than five because nobody was promised a mail by it,
            // and rather than nightly because [XIV-102] is explicit that this is
            // somebody waiting for an answer rather than a background fact.
            new ScheduledJob(
                'tenant:purchase:collect',
                '*/10 * * * *',
                'a customer\'s request to buy a module never reaches the operator screen',
            ),

            // The other one with somebody sitting on the other end, and the most
            // impatient of the three: they have just typed out what is wrong with
            // their business software and their own screen says, honestly, that
            // nobody has it yet. §4.4's grant means a customer's request cannot
            // write to the control plane, so this walk is the *only* thing that
            // puts a ticket in front of an operator (XIV-123, §8.17).
            //
            // Five minutes rather than the purchase collector's ten, and it is
            // `signup:provision`'s cadence for `signup:provision`'s reason:
            // somebody is waiting rather than something is being counted. The
            // reply travels the other way with no interval at all — it is a
            // control-plane row the customer reads directly — so this is the only
            // leg of the conversation a cadence can slow down.
            new ScheduledJob(
                'tenant:support:collect',
                '*/5 * * * *',
                'a customer\'s question to whoever runs this installation reaches nobody',
            ),

            // The housekeeping one. Nobody is waiting, and the page is honest
            // about it either way — every row reads "not collected yet" until
            // this has run, and carries its own timestamp afterwards (§8.11) —
            // so the cost of it stopping is an operator making a decision on
            // figures older than they look. Nightly, at an odd minute past an
            // odd hour, so that fifty installations of Xivi do not all wake up
            // on the hour.
            new ScheduledJob(
                'tenant:usage:collect',
                '17 3 * * *',
                'the tenant list shows figures from whenever it last ran, or none at all',
            ),
        ];
    }

    /**
     * The command names, for the places that only need to know whether something
     * is a scheduled job.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return array_map(static fn (ScheduledJob $job): string => $job->command, $this->all());
    }
}

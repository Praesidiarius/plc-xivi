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

/**
 * **What a clock that was off for two days owes** (XIV-155, §6.7).
 *
 * Cron stops. A machine is rebuilt, a crontab is lost with the server it was
 * typed on, an upgrade takes the instance down for a morning. Whatever the cause,
 * the clock comes back and four occurrences are outstanding that should have
 * happened one at a time. There are exactly two defensible things to do with
 * them and no way to pick between them without knowing what the work is:
 *
 * - a **monthly invoice** wants the months it missed. Not billing February is
 *   not a tidy outcome, it is money the customer is owed and a gap in a numbered
 *   series somebody has to explain to an auditor;
 * - a **sweep** wants to happen once. That is the grace clock §4.6 describes, a
 *   dunning pass, anything whose job is to bring the world to a state rather
 *   than to add to it, and four copies of it are three copies of the same
 *   reminder in somebody's inbox.
 *
 * So the answer is declared per work kind, on {@see RecurringWork::catchUp()},
 * and the engine applies it. Not a global setting, because the two jobs will run
 * in the same tenant on the same morning; not left to the module's
 * {@see RecurringWork::due()} either, because a module that filtered its own
 * backlog would be deciding the question silently, in code nobody reads until
 * the morning after an outage.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum CatchUp
{
    /**
     * Every outstanding occurrence runs, oldest first.
     *
     * The answer for anything that produces a thing per period. [XIV-156] and
     * [XIV-157] both take it: a period that was missed is still a period that has
     * to be billed, and running them oldest first is what keeps a numbered series
     * in the order the periods were in.
     */
    case EveryMissedPeriod;

    /**
     * Only the newest outstanding occurrence runs; the older ones are written
     * down as passed and never come back.
     *
     * "Written down" rather than "ignored" is the part that matters. The engine
     * asks the module what is outstanding and the module answers from its own
     * records, which have not changed, so an occurrence merely skipped would be
     * offered again on the next run, and on every run after that, for ever. The
     * row is what makes skipping a decision rather than a loop.
     */
    case OnlyTheLatest;
}

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

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * **One kind of work a module wants done on a clock inside a tenant** (XIV-155,
 * docs/architecture/extensibility.md §6.7).
 *
 * §1 says the engine may not grow a feature no module needs, and gained a second
 * half on 2026-08-20: once a *second* module needs something, it is the
 * engine's. Recurrence is the case that half was written for. [XIV-156] wants an
 * invoice raised every month from a definition a customer typed; [XIV-157] wants
 * a membership term renewed when it runs out. Both are "do this, for this
 * record, for this period, once". If each module answered it for itself, the
 * answer would be two cron entries, two claim tables, two opinions about what
 * happens when the clock was off for two days, and five of each by the third
 * vertical.
 *
 * So the module declares, and the engine executes. This is the declaration, and
 * it is a tagged service for the reason §2 gives about hook registries: a module
 * that must implement something implements an interface, checked when the
 * container compiles, rather than filling in a config key that is right until
 * somebody misspells it.
 *
 * ## The three methods that carry the design
 *
 * **{@see due()} is a question, never an action.** The engine asks what is
 * outstanding; the module reads its own records and answers. It must be safe to
 * ask twice, safe to ask about a tenant whose customer has renamed the field it
 * reads, and safe to ask about a period that has already been done. The engine
 * filters those out, because it is the engine that remembers what ran, and that
 * division is what stops every module writing its own bookkeeping table.
 *
 * **{@see run()} is handed one occurrence and does it once.** It runs inside a
 * transaction that also carries the engine's record that the occurrence
 * happened, so the two cannot disagree: see {@see DueWorkRunner}, which is where
 * that argument is written out in full. What follows from it for an implementer
 * is short and absolute: **put the effect in the tenant's database, and do not
 * put it anywhere else.** Sending a mail from here is not covered by anything
 * this seam promises.
 *
 * **{@see module()} is what makes an uninstalled module harmless.** Every bundle
 * in the build is loaded for every tenant, so this service exists for customers
 * who never bought the module it belongs to. The engine asks the tenant's own
 * metadata whether that module is installed *there* and skips the work if it is
 * not, which is §6.1's rule applied to a clock: the customer's definitions are
 * the truth, and the blueprint in the build is not.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AutoconfigureTag(self::TAG)]
interface RecurringWork
{
    public const string TAG = 'xivi.recurring_work';

    /**
     * What this work is called, stored on every occurrence the engine records.
     *
     * It goes in a database column and in an operator's terminal, so it is a
     * short stable string in the shape `module.thing`, like `invoice.recurring`
     * or `membership.renewal`. **Renaming one makes every occurrence it already
     * recorded invisible**, and invisible means due again, so a rename is a
     * migration rather than an edit. Dotted rather than free text because two
     * modules will eventually pick the same noun, and the module part is what
     * keeps them apart.
     */
    public function key(): string;

    /**
     * The module this work belongs to, by the key its blueprint uses.
     *
     * A tenant that has not installed it never runs this work and is never asked
     * about it. See the class docblock: this is the whole of what makes a
     * declaration harmless in the forty-nine customers who do not have the
     * module.
     */
    public function module(): string;

    /**
     * What a clock that has been off for two days should do about it.
     *
     * Declared per work kind rather than decided once for the engine, because
     * the two answers are both right for different jobs: a monthly invoice wants
     * the month it missed, and a sweep that tidies something up wants to happen
     * once and not four times. {@see CatchUp} carries the argument.
     */
    public function catchUp(): CatchUp;

    /**
     * **What is outstanding right now**, from this module's own records in this
     * tenant.
     *
     * Called after the engine has switched into the tenant, so ordinary
     * repositories read the right database. Answer with every occurrence that
     * *ought* to have happened by `$now`, including ones that already did: the
     * engine drops the ones it has a record of, and a module that tried to
     * filter them itself would be keeping a second copy of the bookkeeping this
     * seam exists to own.
     *
     * **Answering nothing is a legitimate answer and the common one.** A
     * customer with no recurring definitions, or one who deleted the field this
     * reads, produces an empty list rather than an error.
     *
     * @param \DateTimeImmutable $now  the instant this run is about, in UTC, and
     *                                 the same instant for every tenant in one
     *                                 run so that a walk cannot straddle a
     *                                 boundary
     * @param \DateTimeZone      $zone this tenant's own clock (§8.4.4 with
     *                                 nobody reading), which is what "the first
     *                                 of the month" has to be built in. Never
     *                                 the server's, and never a user's
     *
     * @return iterable<Occurrence> in any order; the engine sorts them
     */
    public function due(\DateTimeImmutable $now, \DateTimeZone $zone): iterable;

    /**
     * Do this one occurrence, exactly once.
     *
     * Throwing is how the work says it failed. The engine rolls the occurrence
     * back, including its record that it happened, names it in the run's report
     * with the tenant it belongs to, and leaves it outstanding, so the
     * next run of the clock offers it again. Nothing here should catch its own
     * failure and return quietly, because a quiet failure is one the engine
     * writes down as done.
     */
    public function run(Occurrence $occurrence): void;
}

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

namespace Xivi\ControlPlane\Provisioning;

use Xivi\ControlPlane\Entity\SignupRequest;

/**
 * Where turning a confirmed signup into a customer stopped (XIV-98).
 *
 * ### What is stored is a stage, and deliberately not a message
 *
 * The obvious column is the exception's own words, and it is the wrong one.
 * [XIV-59] settled the same question one table along and settled it the other
 * way for the same reason: a driver exception names the host, the port and the
 * role, which is fine in the terminal of somebody who already holds the DSN and
 * is not fine on a row that anything might later draw on a page. `TenantUsage`
 * stores "could not be read" and `tenant:usage:collect` prints the driver's own
 * words; this stores a stage and `signup:provision` prints the driver's own
 * words. The rule is the same rule, applied twice, rather than a new one.
 *
 * What a stage buys that a message would not is that it is a **decision
 * procedure**. Reading one tells an operator which of two entirely different
 * situations they are in — a run that will fix itself the next time the cron
 * fires, or a name somebody else now owns, which no number of retries will ever
 * resolve — and that is the only question the stored value has to answer. The
 * detail belongs in the run's output, where somebody is reading it beside the
 * exception that produced it.
 *
 * ### The order of the cases is the order the work happens in
 *
 * Which makes the enum readable as the procedure it describes, and makes
 * "stopped at {@see FirstUser}" mean "the tenant stands" without a table of
 * consequences beside it.
 *
 * @see SignupRequest::recordProvisioningFailure()
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum SignupProvisioningStage: string
{
    /**
     * The name or the hostname is not available, or has no legal translation.
     *
     * **The one stage a retry cannot get past**, and the reason it is a stage of
     * its own rather than being folded into {@see Tenant}. Everything below is a
     * machine failing at something it was entitled to do; this is the machine
     * refusing, correctly, and refusing again every time it is asked. Somebody
     * has to rename a customer or release a hostname before this signup can
     * proceed, and until they do it will appear in every run's report — which is
     * the pressure it should exert rather than a defect of the loop.
     *
     * §8.12's intake checks exist to make this stage unreachable in ordinary
     * operation: the name is checked against the registry in its *translated*
     * form, and the hostname it would take is checked too, at the moment
     * somebody asks for it. What survives those is the genuine race — an
     * operator provisioning that exact name by hand between a confirmation and
     * the next cron run — which is rare, real, and correctly a refusal rather
     * than a scramble for a second name nobody asked for.
     */
    case Preflight = 'preflight';

    /**
     * The registry row, the Postgres role, the database or the schema.
     *
     * `provision()` persists its registry row **before** it creates anything in
     * the cluster, on purpose (§4.1), so a failure at this stage leaves a tenant
     * in {@see \App\Registry\Entity\TenantStatus::Provisioning} — which sorts to
     * the top of [XIV-58]'s page and is named in its banner. The wreckage is
     * visible to an operator who never reads a cron mail, and the next run
     * clears it and starts over.
     */
    case Tenant = 'tenant';

    /**
     * The tenant stands and the first administrator could not be created.
     *
     * A rarer failure than it looks like it should be, because by this point the
     * customer's database exists and has been migrated — so what is left to fail
     * is the connection dying between two statements. It is kept apart from
     * {@see Tenant} anyway, because the *consequence* is different: there is a
     * working, empty, serving installation out there that nobody can sign in to,
     * and it will not be torn down by a retry.
     */
    case FirstUser = 'first_user';

    /**
     * Everything exists and the invitation did not go out.
     *
     * The commonest of the four in practice, because it is the only one that
     * depends on a machine this installation does not own. Nothing is lost: the
     * account is there with no password, which is exactly the state [XIV-1]
     * calls "awaiting an invitation", and the next run sends another link. §8.8
     * already established that sending a second invitation retires the first, so
     * a retry after a partial send cannot leave two live links.
     */
    case Invitation = 'invitation';

    /**
     * Clearing a previous run's wreckage failed, so this one did not start.
     *
     * Distinct from {@see Tenant} because it says something an operator needs:
     * the half-made tenant is still there and the cluster refused to give it up.
     * §4.1's removal order means a failed removal leaves a registry row pointing
     * at whatever survived rather than an orphan nothing knows about, so the
     * same run repeats safely — but if it keeps repeating, the thing to look at
     * is the cluster rather than the signup.
     */
    case Cleanup = 'cleanup';

    /**
     * Whether trying again, unaided, could ever succeed.
     *
     * The one question the stored stage exists to answer. False for
     * {@see Preflight} and true for everything else — see that case for why a
     * refusal that repeats for ever is the correct behaviour rather than a loop
     * to break.
     */
    public function isWorthRetrying(): bool
    {
        return $this !== self::Preflight;
    }
}

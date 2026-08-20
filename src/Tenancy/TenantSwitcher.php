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

namespace App\Tenancy;

use App\Registry\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
use Xivi\Core\Metadata\MetadataCache;

/**
 * The only supported way to enter (or leave) a tenant.
 *
 * Switching tenants means more than swapping a DSN: the open connection points
 * at the previous tenant's database and the entity manager's identity map is
 * full of the previous tenant's objects. Under a long-running worker both
 * outlive the request unless they are dropped explicitly (docs/architecture/open-questions.md §7.4), so
 * every switch drops both, unconditionally — closing a connection that was never
 * opened is free, and "was it the same tenant?" is not a question worth being
 * wrong about.
 *
 * ## Leaving a tenant also forgets what was said about it (XIV-162)
 *
 * The three resources above are the ones a switch was originally written to
 * drop, and dropping them is what made a fleet walk *correct*. It did not make
 * one *finite*. A debug build keeps a process-wide record of every statement
 * anybody runs: `doctrine.debug_data_holder`, which in this application is
 * Doctrine's `BacktraceDebugDataHolder`, storing each statement with its bound
 * parameters and a full backtrace, because `config/packages/doctrine.yaml` turns
 * `profiling_collect_backtrace` on with debug. Nothing in a console process
 * empties it, because the thing that empties it is a request ending, and a
 * command that walks the registry is one request that never ends.
 *
 * So the walk grew by roughly a tenant's worth of statements per tenant, for
 * ever. Measured over 300 rehearsal tenants (`bin/rehearse-fleet migrate`, one
 * real additive migration applied to every one of them), the walk process went
 * from 88 MB to 120 MB resident against the image's 256 MB, and with the two
 * lines below it runs the same 300 tenants at 74 MB from the first sample to
 * the last. Per tenant, taken with `tenant:migrate --slug` in one long-lived
 * process so the curve can be read rather than bounded: 32 MB to 68 MB of PHP
 * heap, about 120 kB each, against 32 MB to 34 MB after.
 *
 * **That is the whole of the growth, which is what convicts the query log
 * rather than merely implicating it.** With this reset removed again and only
 * Monolog's log emptied in its place, the same 300 tenants reproduce the
 * original curve to the megabyte: 32 MB to 68 MB. The same curve, from the same
 * cause, was costing `tenant:inspect`, `tenant:schema:validate` and the three
 * nightly collectors, all of which walk through here. `tenant:usage:collect`
 * over the same fleet went from 82 MB climbing to 94 MB, to 80 MB flat.
 *
 * **Only a walk with debug on was ever in danger.** `bin/deploy` runs out of
 * the production image, where `APP_ENV=prod` leaves debug off, neither service
 * exists and the walk was already flat: measured with debug off across the same
 * 300 tenants it holds 24 MB from the first tenant to the last, before this
 * change and after it. What was actually dying was the rehearsal itself,
 * `tenant:inspect` over a developer's fleet, and any future walk written
 * against a debug build. That is worth fixing and is not the deploy emergency
 * the ticket opened with, which is worth saying out loud so that nobody
 * re-derives the alarm from the changelog entry.
 *
 * **Here rather than in each command, which is the part worth arguing.** XIV-74
 * found this class of bug first, in `tenant:reset`, and fixed it where it found
 * it: {@see \Xivi\ControlPlane\Command\ResetTenantCommand::forgetQueries()} empties
 * both logs at every seam of that one command. That was right there, because
 * the seams of a reset are a reset's own business: after a provision, after a
 * user, after every generated batch. It would be wrong here. Six places walk the
 * fleet today and the number only goes up, and a rule that has to be remembered
 * six times is a rule that is remembered five. The switch *is* the seam: it is
 * already the one moment this codebase agrees a tenant has been finished with,
 * and the log of that tenant's statements is one more thing that belongs to it.
 *
 * **Only when a tenant is actually being left, which is what keeps the profiler
 * useful.** A web request enters a tenant once, from nothing, and what the log
 * holds at that moment is the boot and the registry lookup that resolved the
 * hostname: real answers to real questions somebody has the profiler open to
 * ask. Entering from nothing therefore empties nothing. Moving from one tenant
 * to the next, or leaving one for no tenant at all, is a walk step, and there
 * the previous tenant's statements are dead weight the process will never look
 * at again.
 *
 * **Monolog's debug processor is emptied too, and it is not carrying its
 * weight.** It keeps every record it sees for the profiler's log panel, and
 * Doctrine logs a record per statement, so it is the same accumulator with the
 * same shape; the measurement above says it contributes nothing worth naming to
 * a migration walk, where a tenant costs a handful of statements and no record
 * carries a backtrace. It is the greedier of the two whenever a walk does real
 * work per tenant: XIV-74 found the query log
 * first and, having emptied it, ran out of memory inside `Monolog::jsonEncode`
 * at half the limit. One method call is not a price worth arguing over to leave
 * that waiting for whoever writes the next walk. Both are null when debug is
 * off, where neither service exists and there is nothing to do.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantSwitcher
{
    public const string CONNECTION = 'tenant';
    public const string ENTITY_MANAGER = 'tenant';

    public function __construct(
        private TenantContext $context,
        private ManagerRegistry $registry,
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private Connection $tenantConnection,
        private MetadataCache $metadata,
        /**
         * Doctrine's development query log, if this build has one.
         *
         * Nullable and defaulted, which is not decoration: the service only
         * exists in a debug build, and the parameter being nullable is what
         * makes Symfony wire it as a null-on-invalid reference rather than
         * failing to compile the production container, where this class very
         * much still ships.
         *
         * @see releaseTenantResources() for what it is doing here
         */
        #[Autowire(service: 'doctrine.debug_data_holder')]
        private ?DebugDataHolder $queryLog = null,
        /**
         * And the other thing that remembers every query, which is the half of
         * XIV-74 that only showed up once the first half was fixed.
         *
         * Taken as the interface rather than as `DebugProcessor`, since
         * `clear()` is exactly what is wanted here and it is the interface's
         * whole third method.
         *
         * @see releaseTenantResources()
         */
        #[Autowire(service: 'debug.log_processor')]
        private ?DebugLoggerInterface $logRecords = null,
    ) {
    }

    public function switchTo(Tenant $tenant): void
    {
        $this->releaseTenantResources();
        $this->context->setTenant($tenant);
    }

    /**
     * Leave the tenant context entirely. Any subsequent use of the tenant
     * connection fails loudly rather than reaching the previous tenant.
     */
    public function clear(): void
    {
        $this->releaseTenantResources();
        $this->context->reset();
    }

    /**
     * Run a callback in the context of $tenant, restoring the previous tenant
     * (if any) afterwards. Used by console commands that iterate over tenants.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function runFor(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->context->tryGetTenant();
        $this->switchTo($tenant);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->clear() : $this->switchTo($previous);
        }
    }

    private function releaseTenantResources(): void
    {
        // Definitions belong to whichever tenant was current, and the objects are
        // bound to the connection about to be closed (XIV-53). Emptying the cache
        // here rather than keying it by tenant is the point: a console command
        // walking every customer is the one place a stale shape would be handed
        // to the next one, and that failure would look like the wrong labels
        // rather than like an error (§7.4).
        $this->metadata->clear();

        // Drops the identity map, and replaces the manager if it was closed by a
        // previous failure. Cheap while the manager is still an uninitialised proxy.
        $this->registry->resetManager(self::ENTITY_MANAGER);

        // Forces the next query to reconnect, which re-runs the connection
        // parameters through TenantConnectionMiddleware with the new tenant.
        $this->tenantConnection->close();

        // **Only when there is a tenant to leave** (XIV-162). See the class
        // docblock: entering a tenant from nothing is what a web request does,
        // and the two logs are then holding the boot and the hostname lookup
        // that resolved the customer, which is what somebody opens the profiler
        // to read. Every other call to this method is a walk step, where the
        // statements still in them belong to a customer this process has
        // finished with and will never look at again.
        //
        // The question is still answerable here because nothing above touches
        // the context: `switchTo()` and `clear()` both move it *after* this
        // method returns. Last rather than first so that whatever the three
        // releases above put on the way out, most plausibly a rollback on the
        // connection being closed, goes with the rest instead of becoming the
        // one thing a walk carries per tenant.
        if ($this->context->tryGetTenant() !== null) {
            $this->queryLog?->reset();
            $this->logRecords?->clear();
        }
    }
}

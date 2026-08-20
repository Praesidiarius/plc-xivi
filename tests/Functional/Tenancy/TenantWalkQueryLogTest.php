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

namespace App\Tests\Functional\Tenancy;

use App\Registry\Entity\Tenant;
use App\Tenancy\Migration\TenantMigrator;
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\SharesATenant;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A walk over the fleet costs one tenant's worth of debug log, not the fleet's
 * (XIV-162).
 *
 * ## The bug this stands in for
 *
 * `tenant:migrate` walks every customer in one process, and a debug build keeps
 * a process-wide record of every statement anybody runs: `doctrine.debug_data_holder`,
 * which in this application is `BacktraceDebugDataHolder`, so each entry carries
 * the statement, its bound parameters and a full backtrace. Nothing empties it
 * in a console process, because the thing that empties it is a request ending.
 * Measured over 300 rehearsal tenants (`bin/rehearse-fleet`), the walk grew from
 * 32 MB to 68 MB of PHP heap, about 120 kB per tenant against the image's 256 MB
 * limit, which put the ceiling somewhere short of two thousand customers.
 * {@see TenantSwitcher} now empties both debug logs whenever a tenant is left,
 * and the same walk holds 34 MB from the fiftieth tenant to the three hundredth.
 *
 * ## Why this asserts on a count and not on memory
 *
 * The acceptance criterion is "memory does not grow across a walk", and the
 * obvious way to write that is `memory_get_usage()` either side of a loop with a
 * tolerance in megabytes. That test would be a bad one here for reasons that
 * have nothing to do with this ticket: PHP grows its heap in chunks rather than
 * per allocation, the garbage collector runs when it feels like it, the suite
 * runs eight paratest workers on one machine, and the number would therefore
 * have to carry a tolerance wide enough that a genuine leak of a few hundred
 * kilobytes per tenant fits inside it. A test whose failure depends on when the
 * collector ran is a test somebody eventually marks skipped, which is XIV-74's
 * own reasoning where it made the same choice.
 *
 * What is asserted instead is the *cause*, and it is an integer: how many
 * statements the log is still holding. It comes out of the same code path every
 * time, there is no clock in it, no memory measurement and no collector, and it
 * is exactly the quantity the megabytes are a function of. If the reset comes
 * back out of `TenantSwitcher`, this number goes from a tenant's worth to
 * {@see WALK} tenants' worth and the assertion fails on every machine at every
 * speed.
 *
 * ## Why the bound is measured rather than typed
 *
 * There is no magic number below. The walk is compared against a single step
 * taken moments earlier in the same process against the same tenant, so the
 * comparison survives somebody changing how many statements a step costs, which
 * is the ordinary way a hard-coded bound rots into either a false green or a
 * false red. Eight steps is enough: the broken behaviour is eight times the
 * bound rather than a percentage over it, so there is no width of tolerance
 * that could hide it.
 *
 * **And the guard against passing for the wrong reason.** Everything here reads
 * zero when the log is not recording at all, which is what a build with debug
 * off looks like and would be a test that proves nothing while staying green
 * for ever. So the first assertion is that a step does put statements in the
 * log, taken from inside the switch where they are still there. That one fails
 * loudly if the suite ever stops running in debug.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TenantWalkQueryLogTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_xiv162_walk';
    private const string HOST = 'xiv162-walk.test';

    /**
     * How many tenants the walk stands for.
     *
     * One switch per step and a migration status read inside it, so eight is a
     * fraction of a second. The number only has to be big enough that
     * accumulating would be unmistakable, and eight times the bound is
     * unmistakable.
     */
    private const int WALK = 8;

    private Tenant $tenant;
    private TenantSwitcher $switcher;
    private TenantMigrator $migrator;
    private DebugDataHolder $queryLog;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);
        $this->switcher = self::sharedService(TenantSwitcher::class);
        $this->migrator = self::sharedService(TenantMigrator::class);

        $queryLog = self::getContainer()->get('doctrine.debug_data_holder');
        \assert($queryLog instanceof DebugDataHolder, 'the suite runs in debug, so the query log exists');
        $this->queryLog = $queryLog;
    }

    public function testWalkingTheFleetLeavesOneTenantsStatementsBehindRatherThanEveryTenants(): void
    {
        $this->queryLog->reset();

        // Read from inside the switch, which is both halves of what this line
        // is for: it is the vacuity guard described in the class docblock, and
        // it is the only place the number "what one tenant costs" is visible,
        // since leaving the tenant is precisely what takes it away again.
        $duringOneStep = 0;
        $this->step(function () use (&$duringOneStep): void {
            $duringOneStep = $this->heldStatements();
        });

        self::assertGreaterThan(
            0,
            $duringOneStep,
            'the query log recorded nothing at all, so this test cannot tell a fixed walk from a leaking one',
        );

        $afterOneStep = $this->heldStatements();

        $this->queryLog->reset();

        for ($i = 0; $i < self::WALK; ++$i) {
            $this->step();
        }

        self::assertLessThanOrEqual(
            $afterOneStep,
            $this->heldStatements(),
            sprintf(
                'a walk of %d tenants left more in the query log than a walk of one did, so the switch has '
                . 'stopped emptying it and the walk is growing by about %d statements per tenant again (XIV-162)',
                self::WALK,
                $duringOneStep,
            ),
        );
    }

    /**
     * One step of a fleet walk: enter a tenant, do a tenant-sized piece of work,
     * leave again.
     *
     * `TenantMigrator::status()` rather than a bare `SELECT 1`, because what is
     * being stood in for is `tenant:migrate`, and the statements a real step
     * issues are the ones a real step's log entries are made of. The same tenant
     * every time is deliberate: what accumulates is a function of the number of
     * switches, not of how many databases there are, and provisioning eight
     * tenants to prove that would cost the suite seconds for nothing.
     *
     * @param (callable():void)|null $inside runs while the tenant is still current
     */
    private function step(?callable $inside = null): void
    {
        $this->switcher->runFor($this->tenant, function () use ($inside): void {
            $this->migrator->status();

            if ($inside !== null) {
                $inside();
            }
        });
    }

    /** Every connection's entries together, since a walk touches more than one. */
    private function heldStatements(): int
    {
        return array_sum(array_map(\count(...), $this->queryLog->getData()));
    }
}

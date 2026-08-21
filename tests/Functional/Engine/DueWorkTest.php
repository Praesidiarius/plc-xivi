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

namespace App\Tests\Functional\Engine;

use App\Registry\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\Module\JobModule;
use App\Tests\Support\Schedule\RehearsedWork;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Numbering\NumberAllocator;
use Xivi\Core\Schedule\CatchUp;
use Xivi\Core\Schedule\DueWorkLog;
use Xivi\Core\Schedule\DueWorkRunner;
use Xivi\Core\Schedule\Occurrence;
use Xivi\Core\Schedule\WorkReport;

/**
 * One tenant's turn of the engine's clock (XIV-155, §6.7).
 *
 * The behaviour under test is a short list and every item on it is something that
 * goes wrong in production rather than something that works in a demo: the same
 * occurrence offered twice, a backlog after an outage, a module the customer
 * never installed, a module whose question no longer matches the customer's
 * shape, and work that fails halfway through changing the database.
 *
 * **The effect is a document number**, allocated by {@see RehearsedWork} through
 * the real {@see NumberAllocator}. That is deliberate: a test whose only evidence
 * is a counter the double incremented proves that PHP can add, while a counter in
 * the customer's own table at 3 when it should be at 2 is the bug this feature
 * exists to prevent, in the form somebody would actually meet it.
 *
 * The race between two *committed* connections cannot be shown from inside one
 * transaction and is {@see DueWorkClaimRaceTest}'s, which is why nothing here
 * claims to have proved concurrency.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DueWorkTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_due_work';
    private const string HOST = 'duework.localhost';

    /** A definition the work recurs for. Any string; the engine never reads it. */
    private const string SUBJECT = '42';

    private Tenant $tenant;
    private RehearsedWork $work;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);
        $this->work = self::service(RehearsedWork::class);
        $this->work->reset();

        $this->inTenant(function (): void {
            if (!self::modules()->isInstalled(JobModule::KEY)) {
                self::install(JobModule::KEY);
            }
        });
    }

    /**
     * The plainest statement of the whole feature: it happens, and then it does
     * not happen again.
     *
     * Two runs of the same clock over the same unchanged customer data, which is
     * exactly what an operator typing the command while cron is running produces.
     */
    public function testAnOccurrenceRunsOnceHoweverOftenTheClockTurns(): void
    {
        $this->work->offer = [$this->occurrence('2026-08-01')];

        $first = $this->turn();
        $second = $this->turn();

        self::assertCount(1, $first->ran);
        self::assertSame([], $second->ran, 'The second turn found nothing outstanding.');

        self::assertCount(1, $this->work->ran, 'run() was called exactly once.');
        self::assertSame([1], $this->work->numbers);
        self::assertSame(2, $this->counter(), 'One number was drawn, so the next one is 2.');
        self::assertSame(1, $this->rowsFor(DueWorkLog::RAN));
    }

    /**
     * A clock that was off for three months, against a declaration that wants
     * every period it missed ([XIV-156]'s answer, and [XIV-157]'s).
     *
     * Offered newest first on purpose: the order the module answers in must not
     * be the order the work happens in, because a numbered series produced out of
     * order can never be put back (§5.10).
     */
    public function testEveryMissedPeriodRunsOldestFirst(): void
    {
        $this->work->catchUp = CatchUp::EveryMissedPeriod;
        $this->work->offer = [
            $this->occurrence('2026-08-01'),
            $this->occurrence('2026-06-01'),
            $this->occurrence('2026-07-01'),
        ];

        $report = $this->turn();

        self::assertCount(3, $report->ran);
        self::assertSame(
            ['2026-06-01', '2026-07-01', '2026-08-01'],
            array_map(
                static fn (Occurrence $occurrence): string => $occurrence->period->format('Y-m-d'),
                $this->work->ran,
            ),
        );
        self::assertSame([1, 2, 3], $this->work->numbers, 'June took the first number.');
    }

    /**
     * The same outage against a declaration that wants to happen once: the newest
     * runs and the rest are **written down** as passed over.
     *
     * The second turn is the assertion that matters. A skip that was not recorded
     * would be offered again by the module, which answers from its own records,
     * and those have not changed, on this turn and on every turn after it, for
     * ever.
     */
    public function testOnlyTheLatestRunsAndTheRestAreWrittenOff(): void
    {
        $this->work->catchUp = CatchUp::OnlyTheLatest;
        $this->work->offer = [
            $this->occurrence('2026-06-01'),
            $this->occurrence('2026-07-01'),
            $this->occurrence('2026-08-01'),
        ];

        $first = $this->turn();

        self::assertCount(1, $first->ran);
        self::assertCount(2, $first->passed);
        self::assertSame('2026-08-01', $this->work->ran[0]->period->format('Y-m-d'));
        self::assertSame(2, $this->rowsFor(DueWorkLog::PASSED));

        $second = $this->turn();

        self::assertTrue($second->isQuiet(), 'Nothing is offered a second time, including what was passed over.');
        self::assertCount(1, $this->work->ran);
    }

    /**
     * Two definitions, one backlog each, and {@see CatchUp::OnlyTheLatest}: each
     * subject keeps its own newest.
     *
     * The bug this refuses is collapsing the backlog by job instead of by
     * subject, which would write off every other definition's outstanding work
     * because *some* definition had a newer period.
     */
    public function testTheLatestIsPerSubjectAndNotPerJob(): void
    {
        $this->work->catchUp = CatchUp::OnlyTheLatest;
        $this->work->offer = [
            new Occurrence('a', $this->moment('2026-06-01')),
            new Occurrence('a', $this->moment('2026-07-01')),
            new Occurrence('b', $this->moment('2026-05-01')),
        ];

        $report = $this->turn();

        self::assertCount(2, $report->ran, 'One for each definition.');

        // Still oldest first across what survived, which is what keeps the order
        // a property of the periods rather than of how far back each
        // definition's backlog happened to reach.
        self::assertSame(
            ['b@2026-05-01', 'a@2026-07-01'],
            array_map(
                static fn (Occurrence $o): string => $o->subject . '@' . $o->period->format('Y-m-d'),
                $this->work->ran,
            ),
        );
    }

    /**
     * A customer who never bought the module is never even asked (§6.1).
     *
     * Every bundle in the build is loaded for every tenant, so the declaration
     * exists here regardless; what must not happen is its query running against a
     * database with no shape for it.
     */
    public function testWorkOfAModuleThisCustomerDoesNotHaveIsNeverAsked(): void
    {
        $this->work->module = 'a_module_nobody_installed';
        $this->work->offer = [$this->occurrence('2026-08-01')];

        $report = $this->turn();

        self::assertTrue($report->isQuiet());
        self::assertNull($this->work->askedAt, 'due() was not called at all.');
    }

    /**
     * A customer who renamed the field the declaration reads (§6.1 again, the
     * half that bites).
     *
     * The module cannot answer, that is a report about one job in one tenant, and
     * it is a failure rather than silence, because a clock that swallowed this
     * would go on producing nothing for that customer and saying it was fine.
     */
    public function testAModuleThatCannotSayWhatIsDueIsReportedRatherThanSwallowed(): void
    {
        $this->work->cannotSay = new \RuntimeException('column "billed_from" does not exist');

        $report = $this->turn();

        self::assertTrue($report->failed());
        self::assertCount(1, $report->failures);
        self::assertNull($report->failures[0]->occurrence, 'Nothing was attempted, so nothing is named.');
        self::assertStringContainsString('billed_from', $report->failures[0]->describe());
    }

    /**
     * Work that fails after changing the database leaves **nothing** behind, and
     * comes back.
     *
     * This is the property the whole design is arranged around: the record that
     * an occurrence happened is written in the same transaction as the work, so
     * an attempt is not a run. The counter is the evidence: the double allocates
     * a number and *then* throws, so a design that recorded the occurrence
     * outside the transaction would leave the number drawn and the period marked
     * done.
     */
    public function testWorkThatFailsRollsBackItsOwnRecordAndIsOfferedAgain(): void
    {
        $this->work->offer = [$this->occurrence('2026-08-01')];
        $this->work->cannotRun = [self::SUBJECT];

        $failed = $this->turn();

        self::assertTrue($failed->failed());
        self::assertSame([], $failed->ran);
        self::assertSame(0, $this->rowsFor(DueWorkLog::RAN), 'The claim went back with the work.');
        self::assertSame(1, $this->counter(), 'And so did the number it had drawn.');
        self::assertNotNull($failed->failures[0]->occurrence);

        $this->work->cannotRun = [];
        $retried = $this->turn();

        self::assertCount(1, $retried->ran, 'The next turn of the clock picks it up.');
        self::assertSame(1, $this->rowsFor(DueWorkLog::RAN));
    }

    /**
     * A failed period stops its own definition and nobody else's.
     *
     * Both halves are the point. Carrying on into August after July failed would
     * hand August July's place in the sequence; stopping the *other* definition
     * as well would let one broken record cost a customer everything else that
     * was due.
     */
    public function testAFailedPeriodStopsItsOwnSubjectAndOnlyThat(): void
    {
        $this->work->offer = [
            new Occurrence('broken', $this->moment('2026-06-01')),
            new Occurrence('broken', $this->moment('2026-07-01')),
            new Occurrence('fine', $this->moment('2026-06-01')),
        ];
        $this->work->cannotRun = ['broken'];

        $report = $this->turn();

        self::assertCount(1, $report->failures, 'July was never attempted, so it is not a second failure.');
        self::assertCount(1, $report->ran);
        self::assertSame(
            ['broken', 'fine'],
            array_map(static fn (Occurrence $o): string => $o->subject, $this->work->ran),
            'The other definition ran; the broken one stopped at its first period.',
        );
    }

    /**
     * The instant and the zone a declaration is asked in are the engine's, and
     * the zone is the **customer's** rather than the server's or a reader's
     * (§8.4.4).
     *
     * A schedule read in UTC for a business in Zurich puts the first of the month
     * on the thirty-first of the last one, twice a year at a different hour, and
     * nothing on any screen reveals it.
     */
    public function testTheDeclarationIsAskedInTheTenantsOwnClock(): void
    {
        $now = new \DateTimeImmutable('2026-08-20 12:00:00', new \DateTimeZone('UTC'));
        $zone = new \DateTimeZone('Europe/Zurich');

        $this->inTenant(fn (): WorkReport => self::service(DueWorkRunner::class)->run($now, $zone));

        self::assertEquals($now, $this->work->askedAt);
        self::assertEquals($zone, $this->work->askedIn);
    }

    /** `--job` on the command, which is how one thing is retried without the rest. */
    public function testOnlyTheNamedWorkRuns(): void
    {
        $this->work->offer = [$this->occurrence('2026-08-01')];

        $report = $this->inTenant(fn (): WorkReport => self::service(DueWorkRunner::class)->run(
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC'),
            'some.other.work',
        ));

        self::assertTrue($report->isQuiet());
        self::assertNull($this->work->askedAt);
    }

    /** One turn of the clock in this tenant, at this moment, in UTC. */
    private function turn(): WorkReport
    {
        return $this->inTenant(fn (): WorkReport => self::service(DueWorkRunner::class)->run(
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC'),
        ));
    }

    private function occurrence(string $day): Occurrence
    {
        return new Occurrence(self::SUBJECT, $this->moment($day));
    }

    private function moment(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone('UTC'));
    }

    /** How many occurrences this tenant has recorded with the given outcome. */
    private function rowsFor(string $outcome): int
    {
        return $this->inTenant(fn (): int => (int) self::tenantConnection()->fetchOne(
            'SELECT COUNT(*) FROM due_work WHERE job = :job AND outcome = :outcome',
            ['job' => RehearsedWork::KEY, 'outcome' => $outcome],
        ));
    }

    /** What the rehearsal's counter will give out next; 1 means nothing was drawn. */
    private function counter(): int
    {
        return $this->inTenant(fn (): int => self::service(NumberAllocator::class)
            ->peek(RehearsedWork::SHAPE, RehearsedWork::FIELD, ''));
    }

    /**
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    private function inTenant(callable $work): mixed
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, $work);
    }

    private static function modules(): MetadataRepository
    {
        return self::service(MetadataRepository::class);
    }

    private static function install(string $moduleKey): void
    {
        self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get($moduleKey),
        );
    }

    private static function tenantConnection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}

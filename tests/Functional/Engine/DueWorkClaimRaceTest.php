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
use App\Registry\Repository\TenantRepository;
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenancy\Security\TenantSecretCipher;
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\Schedule\RehearsedWork;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\Core\Schedule\DueWorkLog;
use Xivi\Core\Schedule\Occurrence;

/**
 * Two clocks reaching the same occurrence at the same moment (XIV-155, §6.7).
 *
 * ### Why this is not a theoretical interleaving
 *
 * `tenant:work:run` is a cron entry, and cron entries meet each other. A run that
 * overruns its hour is still walking customer forty when the next one starts at
 * customer one; an operator retries a tenant by hand while the scheduled run is
 * in it; a killed process's transaction is still being rolled back when the
 * replacement starts. In every one of those, two processes ask the same customer
 * what is due, get the same answer, and try to do it. **Both doing it means two
 * invoices for August**, which is not a bug an apology fixes.
 *
 * ### Why this class is not like {@see DueWorkTest}
 *
 * Two connections that are really one connection cannot conflict, and two writes
 * that are never committed cannot be seen by anybody, so DAMA's transaction is
 * off, the customer is this class's own, and it is taken away at the end. The
 * shape is {@see VoucherRedemptionRaceTest}'s, reused rather than reinvented
 * because it is the same guarantee bought with the same mechanism.
 *
 * **Every statement goes through {@see DueWorkLog} itself.** A race test whose
 * SQL is its own copy of the production SQL proves that a string works.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class DueWorkClaimRaceTest extends KernelTestCase
{
    private const string SLUG = 'test_due_work_race';

    /**
     * How long the losing side sits in the lock queue before it says so.
     *
     * A timeout is what turns "this blocks for ever" into an assertion. The same
     * value {@see VoucherRedemptionRaceTest} settled on, for the same reasons.
     */
    private const string LOCK_TIMEOUT = '750ms';

    private Tenant $tenant;
    private Occurrence $august;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->tenant = $this->tenantOfItsOwn();
        $this->august = new Occurrence('7', new \DateTimeImmutable('2026-08-01 00:00:00', new \DateTimeZone('UTC')));

        // Nothing is rolled back in this class, so each test starts by emptying
        // the log rather than by trusting the one before it.
        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => $this->tenantConnection()->executeStatement('DELETE FROM due_work'),
        );
    }

    /**
     * The promise, performed: one occurrence, two runs of the clock in flight,
     * one of them told to do nothing.
     *
     * The interleaving is the one that happens in production, one statement at a
     * time:
     *
     *  1. both sides ask the naive question a PHP implementation would have asked
     *     (has this period been done?) and **both are told no**, because a race
     *     whose first half does not happen is not the race;
     *  2. the first claims, and does not commit;
     *  3. the second claims, and **waits** on the first's row lock, proved rather
     *     than assumed with `lock_timeout`, which is the only way one process can
     *     observe a wait without waiting for ever;
     *  4. the first commits, and the second is now refused by the index rather
     *     than by anything in PHP.
     *
     * Step 3 is what would not happen without `uniq_due_work_occurrence`: two
     * inserts into a table with no unique index do not interact at all, and the
     * claim would be a formality both sides completed.
     */
    public function testTwoClocksReachingOneOccurrenceProduceOneClaim(): void
    {
        $first = $this->aConnectionOfItsOwn();
        $second = $this->aConnectionOfItsOwn();

        $first->beginTransaction();
        $second->beginTransaction();

        self::assertSame([$this->august], $this->log($first)->outstanding(RehearsedWork::KEY, [$this->august]));
        self::assertSame([$this->august], $this->log($second)->outstanding(RehearsedWork::KEY, [$this->august]));

        self::assertTrue($this->claimOn($first), 'the first run takes the occurrence');

        $second->executeStatement(sprintf("SET LOCAL lock_timeout = '%s'", self::LOCK_TIMEOUT));

        try {
            $this->claimOn($second);
            self::fail('the second run was not made to wait for the first');
        } catch (DriverException $waited) {
            // On the SQLSTATE rather than on a DBAL exception class: DBAL has no
            // mapping for 55P03 and hands back a plain DriverException, so
            // matching on the class alone would let a typo in the SQL pass as
            // proof of a lock.
            self::assertSame('55P03', $waited->getSQLState(), 'it queued behind the first claim');
        }

        // A failed statement poisons a Postgres transaction, so the loser starts
        // again, which is what the next process would do anyway.
        $second->rollBack();
        $first->commit();

        $second->beginTransaction();
        self::assertFalse($this->claimOn($second), 'and is told there is nothing to do');
        $second->rollBack();

        self::assertSame(1, $this->committedRows(), 'exactly one record of the occurrence stands');

        $first->close();
        $second->close();
    }

    /**
     * The other ending: the run that claimed it **failed**, so the occurrence is
     * outstanding again.
     *
     * This is the half that stops the design being "an occurrence nobody may ever
     * do". An attempt is not a run: the claim lives in the same transaction as
     * the work, so work that threw takes its own record down with it and the next
     * turn of the clock offers the period again. Nothing anywhere has to notice
     * or clean up, because nothing was committed.
     */
    public function testAClaimThatRollsBackLeavesTheOccurrenceOutstanding(): void
    {
        $first = $this->aConnectionOfItsOwn();

        $first->beginTransaction();
        self::assertTrue($this->claimOn($first));
        $first->rollBack();

        $second = $this->aConnectionOfItsOwn();
        $second->beginTransaction();
        self::assertTrue($this->claimOn($second), 'the period is free again');
        $second->commit();

        self::assertSame(1, $this->committedRows());

        $first->close();
        $second->close();
    }

    /**
     * An occurrence written off by {@see \Xivi\Core\Schedule\CatchUp::OnlyTheLatest}
     * occupies the same slot as one that ran.
     *
     * Which is the whole point of writing it down: `passed` and `ran` are two
     * different things to have decided about a period, and neither may be decided
     * twice.
     */
    public function testAnOccurrenceWrittenOffCannotThenBeRun(): void
    {
        $connection = $this->aConnectionOfItsOwn();

        self::assertTrue($this->claimOn($connection, DueWorkLog::PASSED));
        self::assertFalse($this->claimOn($connection), 'the slot is taken, whatever was written in it');

        self::assertSame(1, $this->committedRows());

        $connection->close();
    }

    /**
     * Two different periods of the same definition are two different slots, and
     * so are the same period of two definitions.
     *
     * The unremarkable path, worth asserting because everything above is about
     * the remarkable one: a claim that refused correctly under a race and also
     * refused September because August had happened would pass every other test
     * in this file and be useless.
     */
    public function testDifferentOccurrencesDoNotCollide(): void
    {
        $connection = $this->aConnectionOfItsOwn();

        $september = new Occurrence(
            $this->august->subject,
            new \DateTimeImmutable('2026-09-01 00:00:00', new \DateTimeZone('UTC')),
        );
        $somebodyElse = new Occurrence('8', $this->august->period);

        self::assertTrue($this->claimOn($connection));
        self::assertTrue($this->log($connection)->claim(RehearsedWork::KEY, $september, $this->august->period));
        self::assertTrue($this->log($connection)->claim(RehearsedWork::KEY, $somebodyElse, $this->august->period));
        self::assertTrue($this->log($connection)->claim('another.work', $this->august, $this->august->period));

        self::assertSame(4, $this->committedRows());

        $connection->close();
    }

    /**
     * **One statement, and this is what counts them.**.
     *
     * The claim the design rests on is not "the guard is correct" but "the guard
     * is *inside* the statement that takes the row lock". Every other test in this
     * class would pass against a version that read the table in PHP, found
     * nothing and inserted, because a single-threaded test cannot get between
     * two statements another process is running, and that version's window is
     * between two statements of its own. That is a real limit of testing a race
     * from one process, and it is worth saying rather than papering over.
     *
     * What can be checked exactly is that there is no second statement for a
     * window to be between, and that the one statement carries the rule.
     */
    public function testAClaimIsExactlyOneStatement(): void
    {
        $log = new class extends AbstractLogger {
            /** @var list<string> */
            public array $statements = [];

            /** @param array<string, mixed> $context */
            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql']) && \is_string($context['sql'])) {
                    $this->statements[] = $context['sql'];
                }
            }
        };

        $connection = $this->aConnectionOfItsOwn([new Middleware($log)]);

        self::assertTrue($this->claimOn($connection));

        self::assertCount(1, $log->statements, 'one round trip, so there is no gap to race in');
        self::assertStringContainsString('ON CONFLICT', $log->statements[0]);
        self::assertStringContainsString('DO NOTHING', $log->statements[0], 'and the refusal is inside it');

        $connection->close();
    }

    /**
     * The index exists, and it is the one the claim needs.
     *
     * A cheap test guarding an expensive assumption. Everything above rests on
     * `due_work` carrying a unique index over exactly `(job, subject, period)`,
     * because that is what `ON CONFLICT` names and what makes the second of two
     * simultaneous inserts wait rather than succeed. Without it every race above
     * still *passes*, since the database would simply be enforcing nothing at
     * the moment that matters, so it is read out of `pg_indexes` rather than
     * inferred from behaviour.
     */
    public function testTheIndexTheClaimDependsOnIsThere(): void
    {
        $definition = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => $this->tenantConnection()->fetchOne(
                "SELECT indexdef FROM pg_indexes WHERE indexname = 'uniq_due_work_occurrence'",
            ),
        );

        self::assertIsString($definition, 'there is an index called uniq_due_work_occurrence');
        self::assertStringContainsString('UNIQUE', $definition);
        self::assertStringContainsString('job', $definition);
        self::assertStringContainsString('subject', $definition);
        self::assertStringContainsString('period', $definition);
    }

    // -- plumbing -----------------------------------------------------------

    /** The production class, on the connection this side of the race owns. */
    private function log(Connection $connection): DueWorkLog
    {
        return new DueWorkLog($connection);
    }

    private function claimOn(Connection $connection, string $outcome = DueWorkLog::RAN): bool
    {
        return $this->log($connection)->claim(
            RehearsedWork::KEY,
            $this->august,
            $this->august->period,
            $outcome,
        );
    }

    /** What anybody else would see: read outside every transaction here. */
    private function committedRows(): int
    {
        $connection = $this->aConnectionOfItsOwn();
        $rows = (int) $connection->fetchOne('SELECT COUNT(*) FROM due_work');
        $connection->close();

        return $rows;
    }

    /**
     * A connection to this customer's database that belongs to nobody else.
     *
     * Built from the tenant's own DSN rather than taken from the container,
     * because the container has exactly one tenant connection and would hand the
     * same object to both sides of a race.
     *
     * @param list<\Doctrine\DBAL\Driver\Middleware> $middlewares
     */
    private function aConnectionOfItsOwn(array $middlewares = []): Connection
    {
        $parameters = self::service(TenantDsnParser::class)->parse($this->tenant->getDatabaseDsn());
        $ciphertext = $this->tenant->getEncryptedDatabasePassword();
        self::assertIsString($ciphertext, 'the tenant has a stored password');

        $configuration = new Configuration();
        $configuration->setMiddlewares($middlewares);

        return DriverManager::getConnection(
            [...$parameters, 'password' => self::service(TenantSecretCipher::class)->decrypt($ciphertext)],
            $configuration,
        );
    }

    /**
     * The connection a request to this customer would be served on.
     *
     * By service id rather than by class, because `Connection::class` is the
     * control plane's.
     */
    private function tenantConnection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /** A customer this class owns outright, made once and taken away at the end. */
    private function tenantOfItsOwn(): Tenant
    {
        $tenants = self::service(TenantRepository::class);
        $existing = $tenants->findOneBySlug(self::SLUG);

        if ($existing instanceof Tenant) {
            return $existing;
        }

        return self::service(TenantProvisioner::class)->provision(self::SLUG, 'Clock race', ['clock-race.localhost']);
    }

    public static function tearDownAfterClass(): void
    {
        self::bootKernel();

        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);
        $provisioner = self::getContainer()->get(TenantProvisioner::class);
        \assert($provisioner instanceof TenantProvisioner);

        $tenant = $tenants->findOneBySlug(self::SLUG);

        if ($tenant instanceof Tenant) {
            $provisioner->deprovision($tenant);
        }

        parent::tearDownAfterClass();
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

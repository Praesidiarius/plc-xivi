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
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Logging\Middleware;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;
use Xivi\Voucher\Redemption\VoucherExhausted;
use Xivi\Voucher\Redemption\VoucherRedemptions;
use Xivi\Voucher\VoucherModule;

/**
 * Two orders taking the last use of one voucher, at the same moment (XIV-103).
 *
 * ### Why this class is not like the others
 *
 * Every other test of the engine runs inside DAMA's transaction and is rolled
 * back afterwards, which is what makes them fast and independent. **A race
 * cannot be tested that way.** Two connections that are really the same
 * connection cannot conflict, and two writes that are never committed cannot be
 * seen by anybody. So this class carries `#[SkipDatabaseRollback]`, makes a
 * customer of its own, commits what it writes, empties the counter table before
 * each test by hand and takes the customer away at the end — the shape
 * {@see UniqueValueRaceTest} established for [XIV-109], reused rather than
 * reinvented because the two tickets are the same bug in two places.
 *
 * The connections are opened straight from the tenant's own DSN rather than
 * taken from the container, for the same reason: the container has one tenant
 * connection and would hand the same object to both halves of the race.
 *
 * ### What is actually being proved
 *
 * The defect is a read followed by a write with nothing across the gap. Under
 * READ COMMITTED two checkouts both read "4 of 5 used", both find room, and both
 * write 5 — a voucher good for five orders redeemed six times, and the sixth is
 * money given away. Arguing about it is easy and proves nothing, so the
 * interleaving is *performed*, in the order it happens in production, one
 * statement at a time:
 *
 *  1. both connections open a transaction and both run the naive check — the
 *     `SELECT` a PHP implementation would have made — and the test asserts that
 *     **both are told there is room**, because a race whose first half does not
 *     happen is not the race;
 *  2. the first redeems, and does **not** commit;
 *  3. the second redeems, and blocks — proved rather than assumed, with
 *     `lock_timeout`, which is the only way a single-process test can observe a
 *     wait without waiting for ever;
 *  4. the first commits, and the second is now refused by the `WHERE` inside the
 *     statement rather than by anything in PHP.
 *
 * Step 3 is the part that would not happen without the unique index: two inserts
 * into a table without one do not interact at all, so the guard would be a
 * `WHERE` that never sees the other redemption. With it, the second waits on the
 * first's transaction and how it ends is decided by how the first ends. Both
 * endings are checked — the winner commits and the loser is refused, and, in the
 * second test, the winner rolls back and the loser is allowed through, because a
 * checkout that never happened must not consume a redemption.
 *
 * **Every statement here goes through {@see VoucherRedemptions} itself** rather
 * than through SQL copied into the test. A race test whose SQL is its own copy
 * of the production SQL proves that a string works, not that the application
 * does.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class VoucherRedemptionRaceTest extends KernelTestCase
{
    private const string SLUG = 'test_voucher_race';

    /**
     * How long the losing side is allowed to sit in the lock queue before it
     * gives up and says so.
     *
     * A timeout is what turns "this blocks for ever" into an assertion. Long
     * enough that a busy machine does not produce it spuriously, short enough
     * that a broken build does not take a minute to find out.
     */
    private const string LOCK_TIMEOUT = '750ms';

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    /** The voucher every test below fights over: a real record, with a real id. */
    private int $voucher;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->tenantOfItsOwn();

        $this->switcher->runFor($this->tenant, function (): void {
            $metadata = self::service(MetadataRepository::class);

            if ($metadata->find(VoucherModule::KEY) === null) {
                self::service(ModuleInstaller::class)->install(
                    self::service(ModuleRegistry::class)->get(VoucherModule::KEY),
                );
            }

            // Nothing is rolled back in this class, so each test starts by
            // emptying the counter rather than by trusting the one before it.
            $this->tenantConnection()->executeStatement('DELETE FROM voucher_redemption');
            $this->tenantConnection()->executeStatement('DELETE FROM voucher');

            $saved = self::service(RecordWriter::class)->save(
                $metadata->get(VoucherModule::KEY),
                new Record([
                    VoucherModule::CODE => 'LAST-ONE',
                    VoucherModule::KIND => VoucherModule::RELATIVE,
                    VoucherModule::PERCENTAGE => '10.00',
                    VoucherModule::MAX_REDEMPTIONS => 1,
                ]),
            );

            $this->voucher = (int) $saved->id;
        });
    }

    /**
     * The promise, kept by the statement: two redemptions in flight, one use
     * left, one survivor.
     */
    public function testTheLastUseCannotBeTakenTwice(): void
    {
        $first = $this->aConnectionOfItsOwn();
        $second = $this->aConnectionOfItsOwn();

        $first->beginTransaction();
        $second->beginTransaction();

        // The read half of the race, performed rather than described. This is
        // the check a PHP implementation would make, and both sides are told the
        // same thing: nobody has used this voucher. That answer is the last word
        // in the version of this feature that does not exist, and both checkouts
        // go through.
        self::assertSame(0, $this->countOn($first), 'the first checkout is told there is room');
        self::assertSame(0, $this->countOn($second), 'and so is the second, in the same moment');

        self::assertSame(1, $this->redemptions($first)->redeem($this->voucher, 1), 'the first takes the last use');

        // The write half. The second connection is now inserting a key the first
        // has claimed and not yet committed, so Postgres makes it wait on that
        // transaction — which is exactly the guarantee that would be missing
        // without the unique index, and is observable here only because it
        // eventually gives up.
        $second->executeStatement(sprintf("SET LOCAL lock_timeout = '%s'", self::LOCK_TIMEOUT));

        try {
            $this->redemptions($second)->redeem($this->voucher, 1);
            self::fail('the second checkout was not made to wait for the first');
        } catch (DriverException $waited) {
            // What was wanted: queued behind the first rather than racing past
            // it. Asserted on the SQLSTATE rather than on a DBAL exception class,
            // because DBAL has no mapping for 55P03 and hands back a plain
            // `DriverException` — matching on the class alone would let a syntax
            // error in the statement above pass as proof of a lock.
            self::assertSame('55P03', $waited->getSQLState(), 'it waited on the first redemption and timed out');
        }

        // A failed statement poisons a Postgres transaction, so the loser has to
        // start again — which is what a second request would do anyway.
        $second->rollBack();
        $first->commit();

        $second->beginTransaction();

        try {
            $this->redemptions($second)->redeem($this->voucher, 1);
            self::fail('a voucher good for one use was redeemed twice');
        } catch (VoucherExhausted $refused) {
            self::assertSame($this->voucher, $refused->voucherId);
            self::assertSame(1, $refused->limit, 'and it says what the limit was');
        }

        $second->rollBack();

        self::assertSame(1, $this->committedCount(), 'exactly one redemption stands');

        $first->close();
        $second->close();
    }

    /**
     * And the other ending: the checkout that claimed it did not happen, so the
     * use is free again.
     *
     * The half that stops the fix from being "a voucher nobody may ever redeem".
     * An order that failed, or a browser that was closed, must leave the count
     * exactly as it found it — the lock is held by a transaction and released
     * with it, and there is no bookkeeping anywhere that could get this wrong.
     */
    public function testARedemptionThatRollsBackIsGivenBack(): void
    {
        $first = $this->aConnectionOfItsOwn();
        $second = $this->aConnectionOfItsOwn();

        $first->beginTransaction();
        self::assertSame(1, $this->redemptions($first)->redeem($this->voucher, 1));
        $first->rollBack();

        $second->beginTransaction();
        self::assertSame(1, $this->redemptions($second)->redeem($this->voucher, 1), 'the use is free again');
        $second->commit();

        self::assertSame(1, $this->committedCount());

        $first->close();
        $second->close();
    }

    /**
     * A voucher good for five, redeemed five times and refused the sixth —
     * without any two of them overlapping.
     *
     * The unremarkable path, which is worth an assertion because everything
     * above is about the remarkable one: a guard that refuses correctly under a
     * race and also refuses the second legitimate redemption would pass every
     * test in this file and be useless.
     */
    public function testAVoucherGoodForFiveIsGoodExactlyFiveTimes(): void
    {
        $connection = $this->aConnectionOfItsOwn();
        $redemptions = $this->redemptions($connection);

        foreach (range(1, 5) as $expected) {
            self::assertSame($expected, $redemptions->redeem($this->voucher, 5));
        }

        self::assertFalse($redemptions->hasRoom($this->voucher, 5));

        $this->expectException(VoucherExhausted::class);

        try {
            $redemptions->redeem($this->voucher, 5);
        } finally {
            $connection->close();
        }
    }

    /**
     * **Unlimited is never refused**, and it is not refused because the statement
     * branches on `IS NULL` rather than comparing against a large number.
     *
     * Two hundred redemptions is not a stress test; it is well past any sentinel
     * anybody would have been tempted to pick and comfortably short of a number
     * that would make this slow.
     */
    public function testAnUnlimitedVoucherIsNeverRefused(): void
    {
        $connection = $this->aConnectionOfItsOwn();
        $redemptions = $this->redemptions($connection);

        foreach (range(1, 200) as $expected) {
            self::assertSame($expected, $redemptions->redeem($this->voucher, VoucherRedemptions::UNLIMITED));
        }

        self::assertTrue($redemptions->hasRoom($this->voucher, VoucherRedemptions::UNLIMITED));

        $connection->close();
    }

    /**
     * A voucher good for nothing takes nothing, **including the first time**.
     *
     * This is the case that catches the version of the statement written with
     * `VALUES` instead of `SELECT … WHERE`: there the insert branch is
     * unguarded, so a limit of zero is refused on every redemption except the
     * one that creates the counter row. One statement, one rule, and this is the
     * assertion that says so.
     */
    public function testAVoucherWithNoUsesLeftAtAllTakesNothingOnTheFirstTry(): void
    {
        $connection = $this->aConnectionOfItsOwn();

        try {
            $this->redemptions($connection)->redeem($this->voucher, 0);
            self::fail('a voucher good for no redemptions was redeemed');
        } catch (VoucherExhausted $refused) {
            self::assertSame(0, $refused->redeemed);
        }

        self::assertSame(0, $this->committedCount(), 'and no counter row was left behind');

        $connection->close();
    }

    /**
     * **A caller who was told there was room a moment ago is refused anyway.**.
     *
     * This is the shape the bug actually takes in an application. [XIV-104] will
     * read a voucher, look at its count while it decides whether the code is
     * good, and redeem some milliseconds later; between those two moments
     * somebody else's checkout lands. A guard that trusted what the caller had
     * already read would give the discount away, and it would do it on the
     * busiest day of the promotion.
     *
     * So the stale read is *performed*: this connection asks and is told yes,
     * another connection redeems and commits on a connection of its own — the
     * millisecond nothing in the request can see — and the redemption is then
     * refused by the statement rather than by anything that remembers the earlier
     * answer.
     */
    public function testARedemptionDecidedOnAStaleCountIsStillRefused(): void
    {
        $mine = $this->aConnectionOfItsOwn();

        self::assertTrue($this->redemptions($mine)->hasRoom($this->voucher, 1), 'told there is room');

        $other = $this->aConnectionOfItsOwn();
        $other->beginTransaction();
        self::assertSame(1, $this->redemptions($other)->redeem($this->voucher, 1));
        $other->commit();
        $other->close();

        try {
            $this->redemptions($mine)->redeem($this->voucher, 1);
            self::fail('a stale reading was allowed to decide');
        } catch (VoucherExhausted $refused) {
            self::assertSame(1, $refused->redeemed);
        }

        self::assertSame(1, $this->committedCount());

        $mine->close();
    }

    /**
     * **One statement, and this is what counts them.**.
     *
     * The claim the whole design rests on is not "the guard is correct" but "the
     * guard is *inside* the statement that takes the row lock". Every other test
     * in this class would pass just as well against a version that read the count
     * in PHP, compared it and wrote it back — because a single-threaded test
     * cannot get between two statements another process is running, and that
     * version's window is between two statements of its own. That is a real limit
     * of testing a race from one process, and it is worth stating rather than
     * papering over.
     *
     * What *can* be checked, exactly and cheaply, is that there is no second
     * statement for a window to be between. DBAL's own logging middleware records
     * every statement the driver executes; a redemption produces one, and the SQL
     * carries both halves of the rule — the `ON CONFLICT` that turns a collision
     * into an update, and the `WHERE` that decides whether that update happens at
     * all.
     *
     * A read-then-write implementation of this method passes everything above and
     * fails only here, which is exactly why this test is here. It was checked that
     * way round: the guard was temporarily rewritten as a `SELECT`, a comparison
     * in PHP and an `UPDATE`, and this is the only test in the file that noticed.
     */
    public function testARedemptionIsExactlyOneStatement(): void
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

        self::assertSame(1, $this->redemptions($connection)->redeem($this->voucher, 5));

        self::assertCount(1, $log->statements, 'one round trip, so there is no gap to race in');
        self::assertStringContainsString('ON CONFLICT', $log->statements[0]);
        self::assertStringContainsString('redeemed_count <', $log->statements[0], 'and the limit is inside it');

        $connection->close();
    }

    /**
     * The index exists, and it is the one the guard needs.
     *
     * A cheap test guarding an expensive assumption: everything above rests on
     * `voucher_redemption` having a unique index on `voucher_id`, because that is
     * what `ON CONFLICT` targets and what makes the second of two simultaneous
     * inserts wait rather than succeed. Without it the races above still *pass* —
     * the database would simply be enforcing nothing at the moment that matters —
     * so the index is checked against `pg_indexes` rather than inferred from
     * behaviour.
     */
    public function testTheIndexTheGuardDependsOnIsThere(): void
    {
        $definition = $this->switcher->runFor($this->tenant, fn () => $this->tenantConnection()->fetchOne(
            "SELECT indexdef FROM pg_indexes WHERE indexname = 'uniq_voucher_redemption'",
        ));

        self::assertIsString($definition, 'there is an index called uniq_voucher_redemption');
        self::assertStringContainsString('UNIQUE', $definition);
        self::assertStringContainsString('voucher_id', $definition);
    }

    // -- plumbing -----------------------------------------------------------

    /** The production class, on the connection this side of the race owns. */
    private function redemptions(Connection $connection): VoucherRedemptions
    {
        return new VoucherRedemptions($connection);
    }

    /** The naive check a PHP implementation would have made, on one connection. */
    private function countOn(Connection $connection): int
    {
        return $this->redemptions($connection)->countFor($this->voucher);
    }

    /** What anybody else would see: read on a connection of its own, outside every transaction. */
    private function committedCount(): int
    {
        $connection = $this->aConnectionOfItsOwn();
        $count = $this->redemptions($connection)->countFor($this->voucher);
        $connection->close();

        return $count;
    }

    /**
     * A connection to this customer's database that belongs to nobody else.
     *
     * Built from the tenant's own DSN and its decrypted password rather than
     * taken from the container, because the container has exactly one tenant
     * connection and would hand the same object to both sides of a race. The
     * services doing the reading are the application's own, so this cannot drift
     * away from how a request reaches the same database.
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
     * control plane's — the tenant one is a second connection the application
     * points at whoever is being served, and asking for the class would quietly
     * read the wrong database.
     */
    private function tenantConnection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * A customer this class owns outright, made once and taken away at the end.
     *
     * Not {@see \App\Tests\Support\SharesATenant}: that trait's whole design is a
     * database kept between tests because DAMA rolls each one back, and this
     * class has turned DAMA off. What is left over from a run that died half way
     * is deprovisioned first, which is the same self-healing the trait has.
     */
    private function tenantOfItsOwn(): Tenant
    {
        $tenants = self::service(TenantRepository::class);
        $existing = $tenants->findOneBySlug(self::SLUG);

        if ($existing instanceof Tenant) {
            return $existing;
        }

        return self::service(TenantProvisioner::class)->provision(self::SLUG, 'Race', ['voucher-race.localhost']);
    }

    public static function tearDownAfterClass(): void
    {
        // Ends the way it started: nothing of this class's is left standing in
        // the cluster, because nothing rolled any of it back.
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

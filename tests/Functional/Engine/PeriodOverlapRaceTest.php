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
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\Core\Field\Type\DateRangeFieldType;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Period\ExclusiveWithin;
use Xivi\Core\Record\OverlapExclusion;
use Xivi\Core\Record\OverlappingPeriod;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Validation\RecordValidator;

/**
 * Two bookings for one room, at the same moment (XIV-136).
 *
 * ### This is [XIV-109]'s test, one level harder
 *
 * `UniqueValueRaceTest` is the class this is modelled on, line for line, and the
 * reasons it is unlike every other test here are its reasons: a race cannot be
 * tested inside DAMA's transaction, because two connections that are really the
 * same connection cannot conflict and two writes that are never committed cannot
 * be seen by anybody. So this class carries `#[SkipDatabaseRollback]`, makes a
 * customer of its own, commits what it writes and takes the customer away at the
 * end.
 *
 * ### What is actually being proved, and why arguing about it proves nothing
 *
 * "Is room 3 free next week" is a read. The booking that follows it is a write.
 * Between them is the millisecond in which somebody else books, and under
 * READ COMMITTED — the default, and what this application runs on — neither
 * reader can see the other's uncommitted row. **There is no way to close that in
 * PHP**, which is why this engine has no application-level check for overlap at
 * all: the constraint is not a second opinion behind a validator, it is the only
 * opinion.
 *
 * So the interleaving is *performed*, in the order it happens in production, one
 * statement at a time:
 *
 *  1. both connections open a transaction and both ask whether the room is free
 *     for those days — the question a booking screen asks — and the test asserts
 *     that **both are told yes**, because a race whose first half does not happen
 *     is not the race;
 *  2. the first books, and does **not** commit;
 *  3. the second books, and blocks — proved rather than assumed, with
 *     `lock_timeout`, which is the only way a single-process test can observe a
 *     wait without waiting for ever;
 *  4. the first commits and the second is refused outright.
 *
 * Step 3 is the part that could not be arranged without the constraint. Two
 * inserts into a table with no exclusion constraint do not interact at all; with
 * one, the second waits on the first's transaction, and how it ends is then
 * decided by how the first ends. Both endings are checked.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class PeriodOverlapRaceTest extends KernelTestCase
{
    private const string SLUG = 'test_overlap_race';

    /**
     * How long the losing side sits in the lock queue before it gives up and says
     * so — long enough that a busy machine does not produce it spuriously, short
     * enough that a broken build does not take a minute to find out.
     */
    private const string LOCK_TIMEOUT = '750ms';

    private const string ROOM = '3';
    private const string STAY = '2026-08-01/2026-08-05';

    /** Overlapping the one above by a day, which is what makes it a conflict. */
    private const string CLASHING = '2026-08-04/2026-08-06';

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->tenantOfItsOwn();

        $this->switcher->runFor($this->tenant, function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            );

            $metadata = self::service(MetadataRepository::class);
            $editor = self::service(MetadataEditor::class);
            $module = $metadata->get(ContactModule::KEY);

            // The care home, in two fields and no code: a room, and a stay that
            // may not overlap another stay in the same room.
            if ($module->getField('room') === null) {
                $editor->addField(shape: $module, key: 'room', label: 'Room', type: 'text');
                $editor->addField(
                    shape: $metadata->get(ContactModule::KEY),
                    key: 'stay',
                    label: 'Stay',
                    type: DateRangeFieldType::KEY,
                    options: [ExclusiveWithin::OPTION => 'room'],
                );
            }

            // Nothing is rolled back in this class, so each test starts by
            // emptying the table rather than by trusting the one before it. Hard
            // delete: a soft-deleted row is outside the constraint's predicate
            // and would leave the next test's race arranged differently from the
            // one it thinks it is running.
            $this->tenantConnection()->executeStatement('DELETE FROM contact');
        });
    }

    /**
     * The engine's promise, kept by the database: two bookings in flight, one
     * room, one survivor.
     */
    public function testTwoBookingsInFlightCannotBothTakeTheSameRoom(): void
    {
        $first = $this->aConnectionOfItsOwn();
        $second = $this->aConnectionOfItsOwn();

        $first->beginTransaction();
        $second->beginTransaction();

        // The read half of the race, performed rather than described. This is the
        // question a booking screen asks — the same `&&` the filter compiles —
        // and both sides are told the same thing: the room is free.
        self::assertFalse($this->roomIsTaken($first), 'the first booking is told the room is free');
        self::assertFalse($this->roomIsTaken($second), 'and so is the second, in the same moment');

        $this->book($first, 'Ada', self::STAY);

        // The write half. The second connection is now claiming days the first
        // has claimed and not yet committed, so Postgres makes it wait on that
        // transaction — which is exactly the guarantee that was missing, and is
        // observable here only because it eventually gives up.
        $second->executeStatement(sprintf("SET LOCAL lock_timeout = '%s'", self::LOCK_TIMEOUT));

        try {
            $this->book($second, 'Grace', self::CLASHING);
            self::fail('the second booking was not made to wait for the first');
        } catch (DriverException $waited) {
            // Asserted on the SQLSTATE rather than on a DBAL exception class,
            // because DBAL has no mapping for 55P03 and hands back a plain
            // `DriverException` — matching on the class alone would let a syntax
            // error in the INSERT above pass as proof of a lock.
            self::assertSame('55P03', $waited->getSQLState(), 'it waited on the first booking and timed out');
        }

        // A failed statement poisons a Postgres transaction, so the loser has to
        // start again — which is what a second request would do anyway.
        $second->rollBack();
        $first->commit();

        $second->beginTransaction();

        try {
            $this->book($second, 'Grace', self::CLASHING);
            self::fail('two overlapping stays were written for one room');
        } catch (DriverException $refused) {
            self::assertSame('23P01', $refused->getSQLState(), 'the exclusion constraint refused it');
            $second->rollBack();
        }

        self::assertSame(1, $this->staysInTheRoom(), 'exactly one booking holds the room');

        $first->close();
        $second->close();
    }

    /**
     * And the other ending: the winner rolls back, so the loser may have the
     * room.
     *
     * The half that stops the fix from being "nobody may ever book this room
     * again". A booking that failed, or a browser that was closed, must leave the
     * days exactly as free as it found them — the conflict is held by a
     * transaction and released with it, and there is no bookkeeping anywhere that
     * could get this wrong.
     */
    public function testTheRoomIsFreeAgainWhenTheBookingThatClaimedItIsNot(): void
    {
        $first = $this->aConnectionOfItsOwn();
        $second = $this->aConnectionOfItsOwn();

        $first->beginTransaction();
        $this->book($first, 'Ada', self::STAY);
        $first->rollBack();

        $second->beginTransaction();
        $this->book($second, 'Grace', self::CLASHING);
        $second->commit();

        self::assertSame(1, $this->staysInTheRoom());

        $first->close();
        $second->close();
    }

    /**
     * **The same race through the engine, with nothing in PHP even trying.**.
     *
     * The one above proves the database refuses. This proves the refusal is
     * *handled*, and it proves the thing [XIV-109]'s equivalent could only
     * gesture at: there is no application-level check to disable here, because
     * this engine never had one for overlap. The validator is run — the real one,
     * the one the record form runs — and it finds nothing to say, because whether
     * a room is free is not a question about the record being saved.
     *
     * Then somebody else's booking lands and commits, on a connection of its own:
     * the millisecond nothing in the request can see. The engine writes what it
     * validated, and what comes back is {@see OverlappingPeriod} naming the
     * field, not a driver exception on its way to becoming a 500.
     */
    public function testTheRaceComesBackAsARefusalThatNamesTheField(): void
    {
        $module = $this->switcher->runFor($this->tenant, fn () => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY));

        $values = [
            'kind' => ContactModule::PERSON,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'room' => self::ROOM,
            'stay' => self::CLASHING,
        ];

        $violations = $this->switcher->runFor($this->tenant, fn () => self::service(RecordValidator::class)
            ->validate($module, $values));

        self::assertCount(0, $violations, 'the engine has nothing to object to: the record itself is fine');

        $other = $this->aConnectionOfItsOwn();
        $other->beginTransaction();
        $this->book($other, 'Grace', self::STAY);
        $other->commit();
        $other->close();

        try {
            $this->switcher->runFor($this->tenant, fn () => self::service(RecordWriter::class)
                ->save($module, new Record($values)));

            self::fail('the engine wrote a stay overlapping one that was already there');
        } catch (OverlappingPeriod $refused) {
            self::assertSame('stay', $refused->fieldKey, 'and it says which field');
            self::assertSame(ContactModule::KEY, $refused->moduleKey);
        }

        self::assertSame(1, $this->staysInTheRoom(), 'and nothing was written');
    }

    /**
     * The constraint exists because a definition says so, and it is the one the
     * engine expects.
     *
     * A cheap test guarding an expensive assumption: everything above rests on
     * the editor having built a constraint under the name
     * {@see OverlapExclusion::nameFor()} computes. If those two ever drift the
     * races above still pass — the database would simply be enforcing nothing —
     * so the name is checked against `pg_constraint` rather than inferred from
     * behaviour.
     */
    public function testTheConstraintIsThereUnderTheNameTheEngineComputes(): void
    {
        $expected = OverlapExclusion::nameFor('contact', 'stay');

        $definition = $this->switcher->runFor($this->tenant, fn () => $this->tenantConnection()
            ->fetchOne('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = :name', ['name' => $expected]));

        self::assertIsString($definition, sprintf('there is a constraint called %s', $expected));
        self::assertStringContainsString('EXCLUDE USING gist', $definition);
        // The two halves of the rule: the same room, and days that meet.
        self::assertStringContainsString('=', $definition);
        self::assertStringContainsString('&&', $definition);
        // And the predicate, without which a cancelled booking would hold a room
        // for ever and a record with no room would conflict with every other one.
        self::assertStringContainsString('deleted_at IS NULL', $definition);
    }

    /**
     * A connection to this customer's database that belongs to nobody else.
     *
     * Built from the tenant's own DSN and its decrypted password rather than
     * taken from the container, because the container has exactly one tenant
     * connection and would hand the same object to both sides of a race.
     */
    private function aConnectionOfItsOwn(): Connection
    {
        $parameters = self::service(TenantDsnParser::class)->parse($this->tenant->getDatabaseDsn());
        $ciphertext = $this->tenant->getEncryptedDatabasePassword();
        self::assertIsString($ciphertext, 'the tenant has a stored password');

        return DriverManager::getConnection([
            ...$parameters,
            'password' => self::service(TenantSecretCipher::class)->decrypt($ciphertext),
        ]);
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

    /**
     * The question a booking screen asks, on one connection.
     *
     * Deliberately the *same* expression the filter compiles and the constraint
     * indexes — `xivi_date_range(…) && …` — so that "both were told the room was
     * free" is a statement about the real question rather than about a
     * convenience query written for this test.
     */
    private function roomIsTaken(Connection $connection): bool
    {
        return $connection->fetchOne(
            "SELECT 1 FROM contact
             WHERE data->>'room' = :room
               AND xivi_date_range(data->>'stay') && xivi_date_range(:stay)
               AND deleted_at IS NULL
             LIMIT 1",
            ['room' => self::ROOM, 'stay' => self::CLASHING],
        ) !== false;
    }

    /**
     * A row written the way the repository writes one, on the connection given.
     *
     * Straight SQL rather than the repository, because the repository is bound to
     * the container's single tenant connection and the whole point here is that
     * these two writes are on different ones.
     */
    private function book(Connection $connection, string $firstName, string $stay): void
    {
        $connection->executeStatement(
            'INSERT INTO contact (created_at, updated_at, data) VALUES (NOW(), NOW(), CAST(:data AS jsonb))',
            ['data' => json_encode([
                'kind' => ContactModule::PERSON,
                'first_name' => $firstName,
                'last_name' => 'Lovelace',
                'room' => self::ROOM,
                'stay' => $stay,
            ], \JSON_THROW_ON_ERROR)],
        );
    }

    private function staysInTheRoom(): int
    {
        return (int) $this->switcher->runFor($this->tenant, fn () => $this->tenantConnection()->fetchOne(
            "SELECT COUNT(*) FROM contact WHERE data->>'room' = :room AND deleted_at IS NULL",
            ['room' => self::ROOM],
        ));
    }

    /**
     * A customer this class owns outright, made once and taken away at the end.
     *
     * Not `SharesATenant`: that trait's whole design is a database kept between
     * tests because DAMA rolls each one back, and this class has turned DAMA off.
     */
    private function tenantOfItsOwn(): Tenant
    {
        $tenants = self::service(TenantRepository::class);
        $existing = $tenants->findOneBySlug(self::SLUG);

        if ($existing instanceof Tenant) {
            return $existing;
        }

        return self::service(TenantProvisioner::class)->provision(self::SLUG, 'Race', ['overlap-race.localhost']);
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

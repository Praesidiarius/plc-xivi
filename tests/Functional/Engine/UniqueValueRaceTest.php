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
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\DuplicateValue;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Record\UniqueIndex;
use Xivi\Core\Validation\RecordValidator;

/**
 * Two saves of the same value, at the same moment (XIV-109).
 *
 * ### Why this class is not like the others
 *
 * Every other test of the engine runs inside DAMA's transaction and is rolled
 * back afterwards, which is what makes them fast and independent. A race cannot
 * be tested that way. Two connections that are really the same connection cannot
 * conflict; two writes that are never committed cannot be seen by anybody. So
 * this class carries `#[SkipDatabaseRollback]`, makes a customer of its own,
 * commits what it writes, cleans up after each test by hand and takes the
 * customer away at the end.
 *
 * The connections are opened straight from the tenant's own DSN rather than
 * taken from the container, for the same reason: the container has one tenant
 * connection and would hand the same object to both halves of the race.
 *
 * ### What is actually being proved
 *
 * The defect XIV-109 closes is a read followed by a write with nothing across
 * the gap. Under READ COMMITTED, two saves that both check "is this email
 * taken?" both get "no", and both then insert. Arguing about it is easy and
 * proves nothing, so the interleaving is *performed*, in the order it happens
 * in production, one statement at a time:
 *
 *  1. both connections open a transaction and both run the validator's own
 *     query — and the test asserts that **both are told the value is free**,
 *     because a race whose first half does not happen is not the race;
 *  2. the first inserts, and does **not** commit;
 *  3. the second inserts, and blocks — proved rather than assumed, with
 *     `lock_timeout`, which is the only way a single-process test can observe a
 *     wait without waiting for ever;
 *  4. the first commits and the second is refused outright.
 *
 * Step 3 is the part that could not have been arranged before this ticket. Two
 * `INSERT`s into a table with no unique index do not interact at all; with one,
 * the second waits on the first's transaction, and how it ends is then decided
 * by how the first ends. Both endings are checked: the winner commits and the
 * loser is refused, and — in the second test — the winner rolls back and the
 * loser is allowed through, because a save that never happened must not reserve
 * a value.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class UniqueValueRaceTest extends KernelTestCase
{
    private const string SLUG = 'test_race';

    /**
     * How long the losing side is allowed to sit in the lock queue before it
     * gives up and says so.
     *
     * A timeout is what turns "this blocks for ever" into an assertion. Long
     * enough that a busy machine does not produce it spuriously, short enough
     * that a broken build does not take a minute to find out.
     */
    private const string LOCK_TIMEOUT = '750ms';

    private const string TAKEN = 'ada@example.com';

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

            // Nothing is rolled back in this class, so each test starts by
            // emptying the table rather than by trusting the one before it.
            // Hard delete: a soft-deleted row is outside the partial index and
            // would leave the next test's race arranged differently from the one
            // it thinks it is running.
            $this->tenantConnection()->executeStatement('DELETE FROM contact');
        });
    }

    /**
     * The engine's promise, kept by the database: two writes in flight, one
     * value, one survivor.
     */
    public function testTwoSavesInFlightCannotBothWriteTheSameValue(): void
    {
        $first = $this->aConnectionOfItsOwn();
        $second = $this->aConnectionOfItsOwn();

        $first->beginTransaction();
        $second->beginTransaction();

        // The read half of the race, performed rather than described. This is
        // the query the unique-field validator makes, and both sides are told
        // the same thing: nobody has this email. Before XIV-109 that answer was
        // the last word and both saves went through.
        self::assertFalse($this->emailIsTaken($first), 'the first save is told the value is free');
        self::assertFalse($this->emailIsTaken($second), 'and so is the second, in the same moment');

        $this->insertContact($first, 'Ada');

        // The write half. The second connection is now inserting a key the first
        // has claimed and not yet committed, so Postgres makes it wait on that
        // transaction — which is exactly the guarantee that was missing, and is
        // observable here only because it eventually gives up.
        $second->executeStatement(sprintf("SET LOCAL lock_timeout = '%s'", self::LOCK_TIMEOUT));

        try {
            $this->insertContact($second, 'Grace');
            self::fail('the second save was not made to wait for the first');
        } catch (DriverException $waited) {
            // What was wanted: queued behind the first rather than racing past
            // it. Asserted on the SQLSTATE rather than on a DBAL exception
            // class, because DBAL has no mapping for 55P03 and hands back a
            // plain `DriverException` — matching on the class alone would let a
            // syntax error in the INSERT above pass as proof of a lock.
            self::assertSame('55P03', $waited->getSQLState(), 'it waited on the first save and timed out');
        }

        // A failed statement poisons a Postgres transaction, so the loser has to
        // start again — which is what a second request would do anyway.
        $second->rollBack();
        $first->commit();

        $second->beginTransaction();

        try {
            $this->insertContact($second, 'Grace');
            self::fail('two records were written with the same value in a unique field');
        } catch (UniqueConstraintViolationException) {
            $second->rollBack();
        }

        self::assertSame(1, $this->contactsWithTheEmail(), 'exactly one record carries it');

        $first->close();
        $second->close();
    }

    /**
     * And the other ending: the winner rolls back, so the loser may have it.
     *
     * The half that stops the fix from being "nobody may ever write this value
     * again". A save that failed, or a browser that was closed, must leave the
     * value exactly as free as it found it — the lock is held by a transaction
     * and released with it, and there is no bookkeeping anywhere that could get
     * this wrong.
     */
    public function testAValueIsFreeAgainWhenTheSaveThatClaimedItDoesNot(): void
    {
        $first = $this->aConnectionOfItsOwn();
        $second = $this->aConnectionOfItsOwn();

        $first->beginTransaction();
        $this->insertContact($first, 'Ada');
        $first->rollBack();

        $second->beginTransaction();
        $this->insertContact($second, 'Grace');
        $second->commit();

        self::assertSame(1, $this->contactsWithTheEmail());

        $first->close();
        $second->close();
    }

    /**
     * The same race through the engine, and what a caller gets out of it.
     *
     * The one above proves the database refuses. This proves the refusal is
     * *handled*: the interleaving runs through the real validator and the real
     * writer, so what comes back is {@see DuplicateValue} naming the field, and
     * not a `UniqueConstraintViolationException` on its way to becoming a 500.
     *
     * The order is the production one, statement for statement. The engine
     * validates, and is told the value is free. Somebody else's save lands and
     * commits, on a connection of its own — the millisecond nothing in the
     * request can see. The engine then writes what it validated.
     */
    public function testTheRaceComesBackAsARefusalThatNamesTheField(): void
    {
        $module = $this->switcher->runFor($this->tenant, fn () => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY));

        $values = ['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => self::TAKEN];

        $violations = $this->switcher->runFor($this->tenant, fn () => self::service(RecordValidator::class)
            ->validate($module, $values));

        self::assertCount(0, $violations, 'the engine is told the value is free');

        $other = $this->aConnectionOfItsOwn();
        $other->beginTransaction();
        $this->insertContact($other, 'Grace');
        $other->commit();
        $other->close();

        try {
            $this->switcher->runFor($this->tenant, fn () => self::service(RecordWriter::class)
                ->save($module, new Record($values)));

            self::fail('the engine wrote a duplicate into a unique field');
        } catch (DuplicateValue $refused) {
            self::assertSame('email', $refused->fieldKey, 'and it says which field');
            self::assertSame(ContactModule::KEY, $refused->moduleKey);
        }

        self::assertSame(1, $this->contactsWithTheEmail(), 'and nothing was written');
    }

    /**
     * The index exists because a definition says so, and it is the one the
     * engine expects.
     *
     * A cheap test guarding an expensive assumption: everything above rests on
     * the module installer having built an index for the email field the contact
     * blueprint marks unique, under the name {@see UniqueIndex::nameFor()}
     * computes. If those two ever drift the races above still pass — the
     * database would simply be enforcing nothing — so the name is checked
     * against `pg_indexes` rather than inferred from behaviour.
     */
    public function testTheIndexIsThereUnderTheNameTheEngineComputes(): void
    {
        $module = $this->switcher->runFor($this->tenant, fn () => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY));

        $expected = UniqueIndex::nameFor($module->getTableName(), 'email');

        $definition = $this->switcher->runFor($this->tenant, fn () => $this->tenantConnection()
            ->fetchOne('SELECT indexdef FROM pg_indexes WHERE indexname = :name', ['name' => $expected]));

        self::assertIsString($definition, sprintf('there is an index called %s', $expected));
        self::assertStringContainsString('UNIQUE', $definition);
        // The two halves of the partial predicate, because an index without them
        // would make "unique" mean "unique and mandatory" and would reserve a
        // deleted record's email for ever.
        self::assertStringContainsString('deleted_at IS NULL', $definition);
        self::assertStringContainsString('IS NOT NULL', $definition);
    }

    /**
     * Neither the metadata editor nor the numbering page can reach a soft-deleted
     * record, and neither may the index.
     *
     * The rule the validator has always had, now written into the schema: a
     * customer who deletes a contact and re-enters the same person must not be
     * refused by a row nothing shows them.
     */
    public function testADeletedRecordDoesNotReserveItsValue(): void
    {
        $module = $this->switcher->runFor($this->tenant, fn () => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY));

        $this->switcher->runFor($this->tenant, function () use ($module): void {
            $writer = self::service(RecordWriter::class);
            $saved = $writer->save($module, new Record([
                'kind' => ContactModule::PERSON,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => self::TAKEN,
            ]));

            $writer->delete($module, $saved);

            $writer->save($module, new Record([
                'kind' => ContactModule::PERSON,
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'email' => self::TAKEN,
            ]));
        });

        self::assertSame(1, $this->contactsWithTheEmail(), 'the live one');
    }

    /** And several records with nothing in the field are not duplicates of each other. */
    public function testAnEmptyFieldIsNotADuplicateOfAnotherEmptyOne(): void
    {
        $module = $this->switcher->runFor($this->tenant, fn () => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY));

        $this->switcher->runFor($this->tenant, function () use ($module): void {
            $writer = self::service(RecordWriter::class);

            foreach (['Ada', 'Grace', 'Barbara'] as $name) {
                $writer->save($module, new Record([
                    'kind' => ContactModule::PERSON,
                    'first_name' => $name,
                    'last_name' => 'Nameless',
                ]));
            }
        });

        $counted = $this->switcher->runFor($this->tenant, fn (): int => self::service(RecordRepository::class)
            ->countAll($module));

        self::assertSame(3, $counted);
    }

    /**
     * A connection to this customer's database that belongs to nobody else.
     *
     * Built from the tenant's own DSN and its decrypted password rather than
     * taken from the container, because the container has exactly one tenant
     * connection and would hand the same object to both sides of a race. The
     * services doing the reading are the application's own, so this cannot drift
     * away from how a request reaches the same database.
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

    /** The unique-field validator's own question, asked on one connection. */
    private function emailIsTaken(Connection $connection): bool
    {
        return $connection->fetchOne(
            "SELECT 1 FROM contact WHERE data->>'email' = :email AND deleted_at IS NULL LIMIT 1",
            ['email' => self::TAKEN],
        ) !== false;
    }

    /**
     * A row written the way the repository writes one, on the connection given.
     *
     * Straight SQL rather than the repository, because the repository is bound to
     * the container's single tenant connection and the whole point here is that
     * these two writes are on different ones. The columns are the ones
     * {@see RecordRepository} fills.
     */
    private function insertContact(Connection $connection, string $firstName): void
    {
        $connection->executeStatement(
            'INSERT INTO contact (created_at, updated_at, data) VALUES (NOW(), NOW(), CAST(:data AS jsonb))',
            ['data' => json_encode([
                'kind' => ContactModule::PERSON,
                'first_name' => $firstName,
                'last_name' => 'Lovelace',
                'email' => self::TAKEN,
            ], \JSON_THROW_ON_ERROR)],
        );
    }

    private function contactsWithTheEmail(): int
    {
        return (int) $this->switcher->runFor($this->tenant, fn () => $this->tenantConnection()->fetchOne(
            "SELECT COUNT(*) FROM contact WHERE data->>'email' = :email AND deleted_at IS NULL",
            ['email' => self::TAKEN],
        ));
    }

    /**
     * A customer this class owns outright, made once and taken away at the end.
     *
     * Not {@see \App\Tests\Support\SharesATenant}: that trait's whole design is
     * a database kept between tests because DAMA rolls each one back, and this
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

        return self::service(TenantProvisioner::class)->provision(self::SLUG, 'Race', ['race.localhost']);
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

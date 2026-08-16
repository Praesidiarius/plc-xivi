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

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\History\HistoryEntry;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Validation\RecordValidator;

/**
 * The engine, proven by the one module that uses it.
 *
 * Everything here goes through definitions read from the database — nothing in
 * the assertions knows what a contact is except through them, which is the claim
 * §1 makes about the engine.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ContactRecordTest extends KernelTestCase
{
    use SharesATenant;

    private const string ALPHA = 'test_engine_alpha';
    private const string BETA = 'test_engine_beta';

    private TenantSwitcher $switcher;
    private Tenant $alpha;
    private Tenant $beta;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);

        // Two tenants, provisioned once for the whole class; each test is rolled
        // back in both of them (see SharesATenant).
        $this->alpha = $this->sharedTenant(self::ALPHA, ['engine-alpha.localhost']);
        $this->beta = $this->sharedTenant(self::BETA, ['engine-beta.localhost']);

        foreach ([$this->alpha, $this->beta] as $tenant) {
            $this->switcher->runFor($tenant, fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            ));
        }
    }

    /**
     * Note where the assertions happen: outside the tenant context the
     * definition was loaded in. A lazily-loaded field collection would try to
     * fetch itself here — against no tenant, or worse against a different one —
     * so this also pins the fetch join in MetadataRepository.
     */
    public function testInstallingWritesTheModulesDefinitions(): void
    {
        $module = $this->moduleIn($this->alpha);

        self::assertSame('contact', $module->getKey());
        self::assertSame('contact', $module->getTableName());
        self::assertSame(
            ['kind', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'birthday', 'company', 'payment_terms'],
            $module->getFieldKeys(),
        );

        $email = $module->getField('email');
        self::assertNotNull($email);
        self::assertTrue($email->isUnique());
        self::assertFalse($email->isRequired());
        self::assertTrue($email->isSystem());
    }

    /**
     * The same assertion as above, made outside the tenant context, for the part
     * of the shape that is a whole other table: a collection and its own fields
     * have to be loaded too, or holding a definition would lazily query
     * whichever database happened to be current.
     */
    public function testInstallingWritesTheCollectionsDefinitions(): void
    {
        $module = $this->moduleIn($this->alpha);

        self::assertSame(['addresses'], $module->getCollectionKeys());

        $addresses = $module->getCollection('addresses');
        self::assertNotNull($addresses);
        self::assertSame('contact_address', $addresses->getTableName());
        self::assertSame($module, $addresses->getParent());
        self::assertSame(['label', 'street', 'postal_code', 'city', 'country'], $addresses->getFieldKeys());

        // Described by the same kind of row, so it carries the same flags.
        $street = $addresses->getField('street');
        self::assertNotNull($street);
        self::assertTrue($street->isRequired());
        self::assertSame('text', $street->getType());
    }

    public function testChildrenAreStoredInTheirOwnTableAndReadBackByParent(): void
    {
        $contact = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace'], [
            'addresses' => [
                ['id' => null, 'data' => ['street' => 'Baker Street 1', 'city' => 'Zürich']],
                ['id' => null, 'data' => ['street' => 'Bahnhofstrasse 5', 'city' => 'Bern']],
            ],
        ]);

        $found = $this->switcher->runFor($this->alpha, function () use ($contact): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $addresses = $module->getCollection('addresses');
            \assert($addresses !== null);

            return self::service(RecordRepository::class)->findChildren($addresses, (int) $contact->id);
        });

        self::assertCount(2, $found);
        // Oldest first: these are a list somebody typed, not a feed.
        self::assertSame('Baker Street 1', $found[0]->get('street'));
        self::assertSame('Bern', $found[1]->get('city'));
        self::assertSame($contact->id, $found[0]->parentId);
        // A child has no owner of its own; whoever owns the contact owns these.
        self::assertNull($found[0]->ownerId);
    }

    /** Deleting a contact has to take its addresses with it, not orphan them. */
    public function testDeletingARecordSoftDeletesItsChildren(): void
    {
        $contact = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace'], [
            'addresses' => [['id' => null, 'data' => ['street' => 'Baker Street 1']]],
        ]);

        $remaining = $this->switcher->runFor($this->alpha, function () use ($contact): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $addresses = $module->getCollection('addresses');
            \assert($addresses !== null);

            self::service(RecordWriter::class)->delete($module, $contact);

            return self::service(RecordRepository::class)->findChildren($addresses, (int) $contact->id);
        });

        self::assertSame([], $remaining);
    }

    /**
     * A row of a collection without a parent has nowhere to belong. Caught
     * before the database says so, because the not-null violation names a
     * column and not the mistake.
     */
    public function testAChildWithoutAParentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->switcher->runFor($this->alpha, function (): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $addresses = $module->getCollection('addresses');
            \assert($addresses !== null);

            self::service(RecordRepository::class)->save($addresses, new Record(['street' => 'Nowhere 1']));
        });
    }

    /**
     * The ids in a submitted collection come from a form, so a request naming a
     * row belonging to a different contact is an attempt to edit that contact's
     * data through a side door.
     */
    public function testAChildOfAnotherRecordCannotBeClaimed(): void
    {
        $ada = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace'], [
            'addresses' => [['id' => null, 'data' => ['street' => 'Baker Street 1']]],
        ]);
        $grace = $this->saveIn($this->alpha, ['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $hers = $this->switcher->runFor($this->alpha, function () use ($ada): int {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $addresses = $module->getCollection('addresses');
            \assert($addresses !== null);

            return (int) self::service(RecordRepository::class)->findChildren($addresses, (int) $ada->id)[0]->id;
        });

        $this->expectException(\InvalidArgumentException::class);

        $this->switcher->runFor($this->alpha, function () use ($grace, $hers): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            self::service(RecordWriter::class)->save($module, $grace, [
                'addresses' => [['id' => $hers, 'data' => ['street' => 'Stolen Street 1']]],
            ]);
        });
    }

    /** Validation reads a collection's definitions exactly as it reads a module's. */
    public function testACollectionIsValidatedByItsOwnDefinitions(): void
    {
        $violations = $this->switcher->runFor($this->alpha, function () {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $addresses = $module->getCollection('addresses');
            \assert($addresses !== null);

            return self::service(RecordValidator::class)->validate($addresses, ['city' => 'Zürich']);
        });

        self::assertCount(1, $violations);
        self::assertSame('[street]', $violations->get(0)->getPropertyPath());
    }

    public function testInstallingTwiceIsHarmless(): void
    {
        $before = $this->moduleIn($this->alpha)->getId();

        $again = $this->switcher->runFor($this->alpha, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));

        self::assertSame($before, $again->getId());
    }

    public function testSavingAndReadingBackARecord(): void
    {
        $saved = $this->saveIn($this->alpha, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ADA@example.com ',
            'birthday' => '1815-12-10',
        ]);

        self::assertNotNull($saved->id);

        $found = $this->switcher->runFor($this->alpha, function () use ($saved): ?Record {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            return self::service(RecordRepository::class)->find($module, (int) $saved->id);
        });

        self::assertNotNull($found);
        self::assertSame('Ada', $found->get('first_name'));
        // The email type lowercases and trims on the way in, so "unique" and any
        // future filter mean what a person means by them.
        self::assertSame('ada@example.com', $found->get('email'));
        // The date type hands back a value, not the string it was stored as.
        self::assertInstanceOf(\DateTimeImmutable::class, $found->get('birthday'));
        self::assertSame('1815-12-10', $found->get('birthday')->format('Y-m-d'));
        // An untouched optional field reads as null rather than being absent.
        self::assertNull($found->get('phone'));
    }

    public function testValidationComesFromTheDefinitions(): void
    {
        $violations = $this->validateIn($this->alpha, ['last_name' => 'Lovelace', 'email' => 'not-an-email']);

        $byField = [];
        foreach ($violations as $violation) {
            $byField[] = $violation->getPropertyPath();
        }

        // first_name is required by its definition; email must look like one.
        self::assertContains('[first_name]', $byField);
        self::assertContains('[email]', $byField);
        self::assertNotContains('[phone]', $byField);
    }

    public function testAValidRecordHasNoViolations(): void
    {
        $violations = $this->validateIn($this->alpha, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '+41 00 000 00 00',
        ]);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testUnknownFieldsAreRejectedRatherThanDroppedSilently(): void
    {
        $violations = $this->validateIn($this->alpha, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'salary' => 100,
        ]);

        self::assertGreaterThan(0, \count($violations));
        self::assertSame('[salary]', $violations->get(0)->getPropertyPath());
    }

    public function testTheUniqueFieldConstraintLooksInTheModulesTable(): void
    {
        $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);

        $violations = $this->validateIn($this->alpha, [
            'first_name' => 'Someone',
            'last_name' => 'Else',
            'email' => 'ada@example.com',
        ]);

        self::assertCount(1, $violations);
        self::assertSame('[email]', $violations->get(0)->getPropertyPath());
    }

    /** Editing a record must not collide with the record itself. */
    public function testAUniqueFieldDoesNotCollideWithItsOwnRecord(): void
    {
        $record = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);

        $violations = $this->validateIn(
            $this->alpha,
            ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com'],
            (int) $record->id,
        );

        self::assertCount(0, $violations, (string) $violations);
    }

    /**
     * The engine reads and writes through whichever database the tenant context
     * points at — the same guarantee the tenancy layer makes, now exercised by
     * something that stores real records.
     */
    public function testRecordsDoNotCrossTenants(): void
    {
        $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);

        $inAlpha = $this->switcher->runFor($this->alpha, fn (): int => self::service(RecordRepository::class)
            ->countAll(self::service(MetadataRepository::class)->get(ContactModule::KEY)));
        $inBeta = $this->switcher->runFor($this->beta, fn (): int => self::service(RecordRepository::class)
            ->countAll(self::service(MetadataRepository::class)->get(ContactModule::KEY)));

        self::assertSame(1, $inAlpha);
        self::assertSame(0, $inBeta);
    }

    /** The same email in two customers' databases is not a duplicate. */
    public function testAUniqueFieldIsOnlyUniqueWithinOneTenant(): void
    {
        $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);

        $violations = $this->validateIn($this->beta, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ]);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testDeletingIsSoft(): void
    {
        $record = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->switcher->runFor($this->alpha, function () use ($record): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            self::service(RecordWriter::class)->delete($module, $record);
        });

        [$visible, $withDeleted] = $this->switcher->runFor($this->alpha, function () use ($record): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $records = self::service(RecordRepository::class);

            return [
                $records->find($module, (int) $record->id),
                $records->find($module, (int) $record->id, includeDeleted: true),
            ];
        });

        self::assertNull($visible);
        self::assertNotNull($withDeleted);
        self::assertTrue($withDeleted->isDeleted());
    }

    /**
     * Through the writer, not the repository: that is the only supported way to
     * write a record (§5.2), so the tests should be taking it too.
     *
     * @param array<string, mixed>                                                 $data
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $children
     */
    private function saveIn(Tenant $tenant, array $data, array $children = []): Record
    {
        return $this->switcher->runFor($tenant, fn (): Record => self::service(RecordWriter::class)->save(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            new Record([...self::asPerson(), ...$data]),
            $children,
        ));
    }

    public function testCreatingARecordIsRecorded(): void
    {
        $contact = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $history = $this->historyOf($this->alpha, (int) $contact->id);

        self::assertCount(1, $history);
        self::assertSame(RecordAction::Created, $history[0]->action);
        // No user: nothing is signed in here, and "System" is the honest answer.
        self::assertNull($history[0]->userId);
        self::assertSame('Ada', $history[0]->fieldChanges()['first_name']['to']);
        self::assertSame('First name', $history[0]->fieldChanges()['first_name']['label']);
    }

    public function testEditingRecordsOnlyWhatChanged(): void
    {
        $contact = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->switcher->runFor($this->alpha, function () use ($contact): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $contact->data['first_name'] = 'Augusta';

            self::service(RecordWriter::class)->save($module, $contact);
        });

        $history = $this->historyOf($this->alpha, (int) $contact->id);

        self::assertCount(2, $history);
        // Newest first.
        self::assertSame(RecordAction::Updated, $history[0]->action);
        self::assertSame(['first_name'], array_keys($history[0]->fieldChanges()));
        self::assertSame('Ada', $history[0]->fieldChanges()['first_name']['from']);
        self::assertSame('Augusta', $history[0]->fieldChanges()['first_name']['to']);
    }

    /**
     * The rule that keeps a timeline readable: saving without changing anything
     * is not an event.
     */
    public function testSavingWithoutChangingAnythingRecordsNothing(): void
    {
        $contact = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->switcher->runFor($this->alpha, function () use ($contact): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            self::service(RecordWriter::class)->save($module, $contact);
        });

        self::assertCount(1, $this->historyOf($this->alpha, (int) $contact->id));
    }

    /**
     * A date is stored as a string and read back as an object. Comparing the two
     * forms naively would report a change on every save, which is how audit
     * trails become noise nobody reads.
     */
    public function testATypedValueSurvivingARoundTripIsNotAChange(): void
    {
        $contact = $this->saveIn($this->alpha, [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'birthday' => '1815-12-10',
        ]);

        $this->switcher->runFor($this->alpha, function () use ($contact): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $records = self::service(RecordRepository::class);

            // Read back, so birthday is a DateTimeImmutable rather than a string.
            $reloaded = $records->find($module, (int) $contact->id);
            \assert($reloaded !== null);

            self::service(RecordWriter::class)->save($module, $reloaded);
        });

        self::assertCount(1, $this->historyOf($this->alpha, (int) $contact->id));
    }

    /** One action, one entry — the contact and its addresses together (§5.2). */
    public function testChangesToAChildLandInTheParentsEntry(): void
    {
        $contact = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->switcher->runFor($this->alpha, function () use ($contact): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $contact->data['phone'] = '+41 00 000 00 00';

            self::service(RecordWriter::class)->save($module, $contact, [
                'addresses' => [['id' => null, 'data' => ['street' => 'Baker Street 1', 'city' => 'Zürich']]],
            ]);
        });

        $history = $this->historyOf($this->alpha, (int) $contact->id);

        // One entry, not two: the phone number and the address were one action.
        self::assertCount(2, $history);
        self::assertSame(RecordAction::Updated, $history[0]->action);
        self::assertSame(['phone'], array_keys($history[0]->fieldChanges()));

        $addresses = $history[0]->collectionChanges()['addresses'];
        self::assertCount(1, $addresses);
        self::assertSame('added', $addresses[0]['action']);
        self::assertSame('Baker Street 1', $addresses[0]['values']['street']['value']);
    }

    public function testRemovingAChildSaysWhatItWas(): void
    {
        $contact = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace'], [
            'addresses' => [['id' => null, 'data' => ['street' => 'Baker Street 1', 'city' => 'Zürich']]],
        ]);

        $this->switcher->runFor($this->alpha, function () use ($contact): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            // Every address gone.
            self::service(RecordWriter::class)->save($module, $contact, ['addresses' => []]);
        });

        $removed = $this->historyOf($this->alpha, (int) $contact->id)[0]->collectionChanges()['addresses'][0];

        self::assertSame('removed', $removed['action']);
        // What it was, because the row it described is gone now.
        self::assertSame('Zürich', $removed['values']['city']['value']);
    }

    public function testDeletingIsRecordedOnce(): void
    {
        $contact = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace'], [
            'addresses' => [['id' => null, 'data' => ['street' => 'Baker Street 1']]],
        ]);

        $this->switcher->runFor($this->alpha, function () use ($contact): void {
            self::service(RecordWriter::class)->delete(
                self::service(MetadataRepository::class)->get(ContactModule::KEY),
                $contact,
            );
        });

        $history = $this->historyOf($this->alpha, (int) $contact->id);

        self::assertSame(RecordAction::Deleted, $history[0]->action);
        // One line, not one per address: "deleted" is the fact.
        self::assertSame([], $history[0]->collectionChanges());
    }

    /** History is a table in the customer's own database, like everything else. */
    public function testHistoryDoesNotCrossTenants(): void
    {
        $inAlpha = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $inBeta = $this->saveIn($this->beta, ['first_name' => 'Grace', 'last_name' => 'Hopper']);

        // Same id in both databases, since ids are only unique within a tenant.
        self::assertSame($inAlpha->id, $inBeta->id);

        $alpha = $this->historyOf($this->alpha, (int) $inAlpha->id);
        $beta = $this->historyOf($this->beta, (int) $inBeta->id);

        self::assertSame('Ada', $alpha[0]->fieldChanges()['first_name']['to']);
        self::assertSame('Grace', $beta[0]->fieldChanges()['first_name']['to']);
    }

    /** @return list<HistoryEntry> */
    private function historyOf(Tenant $tenant, int $recordId): array
    {
        return $this->switcher->runFor($tenant, fn (): array => self::service(HistoryRepository::class)->findFor(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            $recordId,
        ));
    }

    /**
     * A contact is a person unless a test says otherwise (§5.5) — without a kind
     * a record has no variant, and the person fields would not apply to it.
     *
     * @return array<string, mixed>
     */
    private static function asPerson(): array
    {
        return ['kind' => ContactModule::PERSON];
    }

    /** @param array<string, mixed> $data */
    private function validateIn(Tenant $tenant, array $data, ?int $recordId = null): \Symfony\Component\Validator\ConstraintViolationListInterface
    {
        return $this->switcher->runFor($tenant, fn () => self::service(RecordValidator::class)->validate(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            [...self::asPerson(), ...$data],
            $recordId,
        ));
    }

    private function moduleIn(Tenant $tenant): ModuleDefinition
    {
        return $this->switcher->runFor($tenant, fn (): ModuleDefinition => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY));
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

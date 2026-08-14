<?php

declare(strict_types=1);

namespace App\Tests\Functional\Engine;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Validation\RecordValidator;

/**
 * The engine, proven by the one module that uses it.
 *
 * Everything here goes through definitions read from the database — nothing in
 * the assertions knows what a contact is except through them, which is the claim
 * §1 makes about the engine.
 */
final class ContactRecordTest extends KernelTestCase
{
    private const string ALPHA = 'test_engine_alpha';
    private const string BETA = 'test_engine_beta';

    private TenantProvisioner $provisioner;
    private TenantSwitcher $switcher;
    private Tenant $alpha;
    private Tenant $beta;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->provisioner = self::service(TenantProvisioner::class);
        $this->switcher = self::service(TenantSwitcher::class);

        $this->removeTenants();
        $this->alpha = $this->provisioner->provision(self::ALPHA, 'Alpha', ['engine-alpha.localhost']);
        $this->beta = $this->provisioner->provision(self::BETA, 'Beta', ['engine-beta.localhost']);

        foreach ([$this->alpha, $this->beta] as $tenant) {
            $this->switcher->runFor($tenant, fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            ));
        }
    }

    protected function tearDown(): void
    {
        $this->removeTenants();

        parent::tearDown();
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
        self::assertSame(['first_name', 'last_name', 'email', 'phone', 'birthday'], $module->getFieldKeys());

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
        $contact = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $found = $this->switcher->runFor($this->alpha, function () use ($contact): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $addresses = $module->getCollection('addresses');
            \assert($addresses !== null);
            $records = self::service(RecordRepository::class);

            $records->save($addresses, new Record(['street' => 'Baker Street 1', 'city' => 'Zürich'], parentId: $contact->id));
            $records->save($addresses, new Record(['street' => 'Bahnhofstrasse 5', 'city' => 'Bern'], parentId: $contact->id));

            return $records->findChildren($addresses, (int) $contact->id);
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
        $contact = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $remaining = $this->switcher->runFor($this->alpha, function () use ($contact): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $addresses = $module->getCollection('addresses');
            \assert($addresses !== null);
            $records = self::service(RecordRepository::class);

            $records->save($addresses, new Record(['street' => 'Baker Street 1'], parentId: $contact->id));
            $records->delete($module, $contact);

            return $records->findChildren($addresses, (int) $contact->id);
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
        $ada = $this->saveIn($this->alpha, ['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $grace = $this->saveIn($this->alpha, ['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->expectException(\InvalidArgumentException::class);

        $this->switcher->runFor($this->alpha, function () use ($ada, $grace): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $addresses = $module->getCollection('addresses');
            \assert($addresses !== null);
            $records = self::service(RecordRepository::class);

            $hers = $records->save($addresses, new Record(['street' => 'Baker Street 1'], parentId: $ada->id));

            $records->replaceChildren($addresses, (int) $grace->id, [
                ['id' => (int) $hers->id, 'data' => ['street' => 'Stolen Street 1']],
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
        $saved = $this->switcher->runFor($this->alpha, function (): Record {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            return self::service(RecordRepository::class)->save($module, new Record([
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ADA@example.com ',
                'birthday' => '1815-12-10',
            ]));
        });

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
            self::service(RecordRepository::class)->delete($module, $record);
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

    /** @param array<string, mixed> $data */
    private function saveIn(Tenant $tenant, array $data): Record
    {
        return $this->switcher->runFor($tenant, fn (): Record => self::service(RecordRepository::class)->save(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            new Record($data),
        ));
    }

    /** @param array<string, mixed> $data */
    private function validateIn(Tenant $tenant, array $data, ?int $recordId = null): \Symfony\Component\Validator\ConstraintViolationListInterface
    {
        return $this->switcher->runFor($tenant, fn () => self::service(RecordValidator::class)->validate(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            $data,
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

    private function removeTenants(): void
    {
        $tenants = self::service(TenantRepository::class);

        foreach ([self::ALPHA, self::BETA] as $slug) {
            $tenant = $tenants->findOneBySlug($slug);

            if ($tenant instanceof Tenant) {
                $this->provisioner->deprovision($tenant);
            }
        }
    }
}

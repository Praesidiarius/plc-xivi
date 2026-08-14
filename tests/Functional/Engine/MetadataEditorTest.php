<?php

declare(strict_types=1);

namespace App\Tests\Functional\Engine;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\UnknownFieldType;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Validation\RecordValidator;

/**
 * A customer changing the shape of their own module (§5.4).
 *
 * The tests that matter here are the refusals: everything this will not do is
 * something that would leave data the application can no longer read, save, or
 * explain.
 */
final class MetadataEditorTest extends KernelTestCase
{
    private const string SLUG = 'test_editor';

    private TenantProvisioner $provisioner;
    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->provisioner = self::service(TenantProvisioner::class);
        $this->switcher = self::service(TenantSwitcher::class);

        $this->removeTenant();
        $this->tenant = $this->provisioner->provision(self::SLUG, 'Editor', ['editor.localhost']);

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTenant();

        parent::tearDown();
    }

    /**
     * The whole point: a field added as a row shows up everywhere, because the
     * form, the validation and the query layer all read the same rows.
     */
    public function testAddingAFieldChangesWhatTheModuleIs(): void
    {
        $this->addField('vat_number', 'VAT number', 'text', filterable: true);

        $keys = $this->switcher->runFor($this->tenant, fn (): array => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY)->getFieldKeys());

        self::assertContains('vat_number', $keys);

        // Storable, and queryable, with nothing else touched.
        $this->contact(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'vat_number' => 'CHE-1']);

        $found = $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)->findBy(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            new RecordQuery([new Filter('vat_number', Operator::Equals, 'CHE-1')]),
        ));

        self::assertCount(1, $found);
        self::assertSame('CHE-1', $found[0]->get('vat_number'));
    }

    public function testAddedFieldsAreNotTheModulesOwn(): void
    {
        $field = $this->addField('vat_number', 'VAT number', 'text');

        // Which is what makes it removable, unlike the ones the module installed.
        self::assertFalse($field->isSystem());
    }

    /**
     * A module's own fields are its designed shape and appear on the list; one
     * added later does not, until somebody asks for it (§5.4). Otherwise every
     * addition widens a table people read every day.
     */
    public function testAddedFieldsAreNotOnTheListByDefault(): void
    {
        $added = $this->addField('vat_number', 'VAT number', 'text');

        self::assertFalse($added->isListed());

        $shipped = $this->switcher->runFor($this->tenant, fn () => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY)->getField('first_name'));

        self::assertNotNull($shipped);
        self::assertTrue($shipped->isListed());
    }

    /** It is a UI hint: the value is stored, validated and queryable regardless. */
    public function testAFieldOffTheListIsStillARealField(): void
    {
        $this->addField('vat_number', 'VAT number', 'text', filterable: true);
        $this->contact(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'vat_number' => 'CHE-1']);

        $found = $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)->findBy(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            new RecordQuery([new Filter('vat_number', Operator::Equals, 'CHE-1')]),
        ));

        self::assertCount(1, $found);
        self::assertSame('CHE-1', $found[0]->get('vat_number'));
    }

    public function testAFieldNameMustBeAnIdentifier(): void
    {
        foreach (['VAT Number', '1st', 'vat-number', '', 'vat number'] as $key) {
            try {
                $this->addField($key, 'Nope', 'text');
                self::fail(sprintf('"%s" should have been refused', $key));
            } catch (MetadataChangeRefused $e) {
                self::assertStringContainsString('must start with a letter', $e->getMessage());
            }
        }
    }

    public function testAKeyThatIsAlreadyTakenIsRefused(): void
    {
        $this->expectException(MetadataChangeRefused::class);
        $this->expectExceptionMessageMatches('/already has a field/');

        $this->addField('first_name', 'Another one', 'text');
    }

    public function testAnUnknownTypeIsRefused(): void
    {
        $this->expectException(UnknownFieldType::class);

        $this->addField('vat_number', 'VAT number', 'telepathy');
    }

    /**
     * Switching on a rule is a promise about data that already exists. Applying
     * it blind leaves records nobody can save until they work out why.
     */
    public function testMakingAFieldRequiredIsRefusedWhenRecordsWouldFail(): void
    {
        $this->contact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->contact(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->expectException(MetadataChangeRefused::class);
        $this->expectExceptionMessageMatches('/2 existing records/');

        $this->update('phone', required: true);
    }

    public function testMakingAFieldUniqueIsRefusedWhenRecordsCollide(): void
    {
        $this->contact(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '+41 1']);
        $this->contact(['first_name' => 'Grace', 'last_name' => 'Hopper', 'phone' => '+41 1']);

        $this->expectException(MetadataChangeRefused::class);
        $this->expectExceptionMessageMatches('/2 existing records/');

        $this->update('phone', unique: true);
    }

    /** Relaxing a rule cannot invalidate anything, so it is never refused. */
    public function testRelaxingARuleIsAlwaysAllowed(): void
    {
        $this->contact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->update('first_name', required: false);

        $violations = $this->switcher->runFor($this->tenant, fn () => self::service(RecordValidator::class)->validate(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            ['last_name' => 'Nameless'],
        ));

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testAModulesOwnFieldCannotBeRemoved(): void
    {
        $this->expectException(MetadataChangeRefused::class);
        $this->expectExceptionMessageMatches('/came with the module/');

        $this->switcher->runFor($this->tenant, function (): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $field = $module->getField('phone');
            \assert($field !== null);

            self::service(MetadataEditor::class)->removeField($field);
        });
    }

    /**
     * §7.2's answer for deletion: the definition goes, the values stay. Nothing
     * on this path can destroy data.
     */
    public function testRemovingAFieldLeavesItsValuesInStorage(): void
    {
        $this->addField('vat_number', 'VAT number', 'text');
        $contact = $this->contact(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'vat_number' => 'CHE-1']);

        $holding = $this->switcher->runFor($this->tenant, function (): int {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $field = $module->getField('vat_number');
            \assert($field !== null);

            $editor = self::service(MetadataEditor::class);
            $count = $editor->recordsHolding($field);
            $editor->removeField($field);

            return $count;
        });

        // The confirmation page's number, before the removal.
        self::assertSame(1, $holding);

        // Gone from the shape…
        $keys = $this->switcher->runFor($this->tenant, fn (): array => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY)->getFieldKeys());
        self::assertNotContains('vat_number', $keys);

        // …and still in the row underneath it.
        $stored = $this->switcher->runFor($this->tenant, fn (): mixed => self::service(RecordRepository::class)
            ->find(self::service(MetadataRepository::class)->get(ContactModule::KEY), (int) $contact->id));
        self::assertNull($stored?->get('vat_number'), 'invisible while no field describes it');

        // Add the field back and the value is there again, which is what makes
        // this a reversible operation rather than a destructive one.
        $this->addField('vat_number', 'VAT number', 'text');

        $again = $this->switcher->runFor($this->tenant, fn (): mixed => self::service(RecordRepository::class)
            ->find(self::service(MetadataRepository::class)->get(ContactModule::KEY), (int) $contact->id));
        self::assertSame('CHE-1', $again?->get('vat_number'));
    }

    /** Editing a collection's fields works the same way, because it is a shape too. */
    public function testACollectionsFieldsAreEditableToo(): void
    {
        $this->switcher->runFor($this->tenant, function (): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $addresses = $module->getCollection('addresses');
            \assert($addresses !== null);

            self::service(MetadataEditor::class)->addField(
                shape: $addresses,
                key: 'floor',
                label: 'Floor',
                type: 'integer',
            );
        });

        $keys = $this->switcher->runFor($this->tenant, function (): array {
            $addresses = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getCollection('addresses');
            \assert($addresses !== null);

            return $addresses->getFieldKeys();
        });

        self::assertContains('floor', $keys);
    }

    private function addField(string $key, string $label, string $type, bool $filterable = false): FieldDefinition
    {
        return $this->switcher->runFor($this->tenant, fn (): FieldDefinition => self::service(MetadataEditor::class)->addField(
            shape: self::service(MetadataRepository::class)->get(ContactModule::KEY),
            key: $key,
            label: $label,
            type: $type,
            filterable: $filterable,
        ));
    }

    private function update(string $key, ?bool $required = null, ?bool $unique = null): void
    {
        $this->switcher->runFor($this->tenant, function () use ($key, $required, $unique): void {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $field = $module->getField($key);
            \assert($field !== null);

            self::service(MetadataEditor::class)->updateField(
                field: $field,
                label: $field->getLabel(),
                required: $required ?? $field->isRequired(),
                unique: $unique ?? $field->isUnique(),
                filterable: $field->isFilterable(),
                listed: $field->isListed(),
                position: $field->getPosition(),
                options: $field->getOptions(),
            );
        });
    }

    /** @param array<string, mixed> $data */
    private function contact(array $data): Record
    {
        return $this->switcher->runFor($this->tenant, fn (): Record => self::service(RecordWriter::class)->save(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            new Record($data),
        ));
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

    private function removeTenant(): void
    {
        $tenant = self::service(TenantRepository::class)->findOneBySlug(self::SLUG);

        if ($tenant instanceof Tenant) {
            $this->provisioner->deprovision($tenant);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Engine;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\SharesATenant;
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
    use SharesATenant;

    private const string SLUG = 'test_editor';

    /** A second tenant with no module installed, for the tests about installing one. */
    private const string FRESH = 'test_editor_fresh';

    private TenantSwitcher $switcher;
    private Tenant $tenant;
    private Tenant $fresh;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);

        // Editing definitions is what this class does, so it needs each test to
        // start from the shipped ones. That is a rollback, not a fresh database
        // (see SharesATenant) — including the installing done right here.
        $this->tenant = $this->sharedTenant(self::SLUG, ['editor.localhost']);
        $this->fresh = $this->sharedTenant(self::FRESH, ['editor-fresh.localhost']);

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));
    }

    /**
     * A preset is a named subset of the module's fields (§6.1) — installing with
     * "basic" leaves birthday out, and the customer can add it back later, which
     * is what makes choosing the smaller one reversible.
     */
    public function testInstallingWithAPresetTakesOnlyItsFields(): void
    {
        // The one with nothing installed; everything below then reads from it.
        $this->tenant = $this->fresh;

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            'basic',
        ));

        $module = $this->switcher->runFor($this->tenant, fn () => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY));

        self::assertSame(['kind', 'company_name', 'first_name', 'last_name', 'email'], $module->getFieldKeys());

        // Collections are installed either way: nothing can add one back later,
        // so a preset is not allowed to take one away.
        self::assertSame(['addresses'], $module->getCollectionKeys());
    }

    /** Order comes from the blueprint, not from the order the preset lists them. */
    public function testAPresetDoesNotReorderTheModulesFields(): void
    {
        $module = $this->switcher->runFor($this->tenant, fn () => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY));

        // The default preset is "extended", which is every field.
        self::assertSame(
            ['kind', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'birthday', 'company'],
            $module->getFieldKeys(),
        );
    }

    public function testAnUnknownPresetIsRefused(): void
    {
        // The one with nothing installed; everything below then reads from it.
        $this->tenant = $this->fresh;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no preset "deluxe". It offers: basic, extended/');

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            'deluxe',
        ));
    }

    /** A field the preset left out is not gone forever — that is the whole point. */
    public function testAFieldLeftOutByAPresetCanBeAddedBack(): void
    {
        // The one with nothing installed; everything below then reads from it.
        $this->tenant = $this->fresh;

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            'basic',
        ));

        $this->addField('birthday', 'Birthday', 'date');

        $keys = $this->switcher->runFor($this->tenant, fn (): array => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY)->getFieldKeys());

        self::assertContains('birthday', $keys);
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
        $this->contact(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'vat_number' => 'CHE-1']);

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
        $this->contact(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'vat_number' => 'CHE-1']);

        $found = $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)->findBy(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            new RecordQuery([new Filter('vat_number', Operator::Equals, 'CHE-1')]),
        ));

        self::assertCount(1, $found);
        self::assertSame('CHE-1', $found[0]->get('vat_number'));
    }

    /** The blueprint's flag reaches the definitions, not just the fallback. */
    public function testTheModuleDeclaresWhichFieldsNameARecord(): void
    {
        $module = $this->switcher->runFor($this->tenant, fn () => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY));

        self::assertTrue($module->getField('first_name')?->isTitle());
        self::assertTrue($module->getField('last_name')?->isTitle());
        self::assertFalse($module->getField('phone')?->isTitle());
    }

    /**
     * The test that distinguishes the flag from the old guess: Contact's title
     * fields and its required fields happen to be the same two, so only marking
     * something *else* shows which one is answering.
     */
    public function testAMarkedFieldOverridesTheRequiredFieldsGuess(): void
    {
        $named = $this->switcher->runFor($this->tenant, function (): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $editor = self::service(MetadataEditor::class);

            // email is not required, so the old heuristic would never pick it.
            foreach ($module->getFields() as $field) {
                $editor->updateField(
                    field: $field,
                    label: $field->getLabel(),
                    required: $field->isRequired(),
                    unique: $field->isUnique(),
                    filterable: $field->isFilterable(),
                    listed: $field->isListed(),
                    title: $field->getKey() === 'email',
                    position: $field->getPosition(),
                    options: $field->getOptions(),
                );
            }

            return array_map(
                static fn ($f): string => $f->getKey(),
                self::service(MetadataRepository::class)->get(ContactModule::KEY)->getTitleFields(),
            );
        });

        self::assertSame(['email'], $named);
    }

    /**
     * With nothing marked, the old guess still answers: the required fields,
     * first two. A wrong heading beats a blank one.
     */
    public function testWithoutAMarkedFieldItFallsBackToTheRequiredOnes(): void
    {
        $named = $this->switcher->runFor($this->tenant, function (): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $editor = self::service(MetadataEditor::class);

            foreach ($module->getFields() as $field) {
                $editor->updateField(
                    field: $field,
                    label: $field->getLabel(),
                    required: $field->isRequired(),
                    unique: $field->isUnique(),
                    filterable: $field->isFilterable(),
                    listed: $field->isListed(),
                    title: false,
                    position: $field->getPosition(),
                    options: $field->getOptions(),
                );
            }

            return array_map(
                static fn ($f): string => $f->getKey(),
                self::service(MetadataRepository::class)->get(ContactModule::KEY)->getTitleFields(),
            );
        });

        self::assertSame(['kind', 'company_name'], $named);
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
        $this->contact(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->contact(['kind' => ContactModule::PERSON, 'first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->expectException(MetadataChangeRefused::class);
        $this->expectExceptionMessageMatches('/2 existing records/');

        $this->update('phone', required: true);
    }

    public function testMakingAFieldUniqueIsRefusedWhenRecordsCollide(): void
    {
        $this->contact(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '+41 1']);
        $this->contact(['kind' => ContactModule::PERSON, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'phone' => '+41 1']);

        $this->expectException(MetadataChangeRefused::class);
        $this->expectExceptionMessageMatches('/2 existing records/');

        $this->update('phone', unique: true);
    }

    /** Relaxing a rule cannot invalidate anything, so it is never refused. */
    public function testRelaxingARuleIsAlwaysAllowed(): void
    {
        $this->contact(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->update('first_name', required: false);

        $violations = $this->switcher->runFor($this->tenant, fn () => self::service(RecordValidator::class)->validate(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            ['kind' => ContactModule::PERSON, 'last_name' => 'Nameless'],
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
                title: $field->isTitle(),
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
}

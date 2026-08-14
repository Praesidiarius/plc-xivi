<?php

declare(strict_types=1);

namespace App\Tests\Functional\Engine;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
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
 * A contact that is a person, or a company (§5.5, §7.6).
 *
 * One module rather than two, so that selecting a contact anywhere is a plain
 * foreign key to one table rather than a type-and-id pair — the shape that
 * cannot carry a key, and the one §5.2 already refused once.
 */
final class ContactVariantsTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_variants';

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);

        // One tenant for the class; each test is rolled back (see SharesATenant).
        $this->tenant = $this->sharedTenant(self::SLUG, ['variants.localhost']);

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));
    }


    public function testTheModuleKnowsItsVariants(): void
    {
        $module = $this->module();

        self::assertTrue($module->hasVariants());
        self::assertSame('kind', $module->getVariantField());
        self::assertSame(
            [ContactModule::PERSON => 'Person', ContactModule::COMPANY => 'Company'],
            $module->getVariants(),
        );
    }

    /** A company has no first name, and a person has no company name. */
    public function testEachVariantHasItsOwnFields(): void
    {
        $module = $this->module();

        $person = array_map(static fn ($f): string => $f->getKey(), $module->getFieldsFor(ContactModule::PERSON));
        $company = array_map(static fn ($f): string => $f->getKey(), $module->getFieldsFor(ContactModule::COMPANY));

        self::assertContains('first_name', $person);
        self::assertNotContains('company_name', $person);

        self::assertContains('company_name', $company);
        self::assertNotContains('first_name', $company);

        // What both are: shared fields belong to every variant.
        foreach (['kind', 'email', 'phone'] as $shared) {
            self::assertContains($shared, $person, $shared);
            self::assertContains($shared, $company, $shared);
        }
    }

    /**
     * The point of variants. first_name is required, and a company cannot
     * satisfy it — so it is not asked of one.
     */
    public function testACompanyIsNotRequiredToHaveAFirstName(): void
    {
        $violations = $this->validate([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
        ]);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testAPersonIsStillRequiredToHaveOne(): void
    {
        $violations = $this->validate(['kind' => ContactModule::PERSON, 'last_name' => 'Lovelace']);

        self::assertCount(1, $violations);
        self::assertSame('[first_name]', $violations->get(0)->getPropertyPath());
    }

    /** And a company is required to have the name it does have. */
    public function testACompanyIsRequiredToHaveACompanyName(): void
    {
        $violations = $this->validate(['kind' => ContactModule::COMPANY]);

        self::assertCount(1, $violations);
        self::assertSame('[company_name]', $violations->get(0)->getPropertyPath());
    }

    /**
     * A value belonging to another variant travels with the record rather than
     * being rejected as unknown — it is somebody's data, the same reason
     * removing a field leaves its values alone (§7.2).
     */
    public function testAValueFromAnotherVariantIsCarriedNotRejected(): void
    {
        $violations = $this->validate([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            'first_name' => 'left over from when this was a person',
        ]);

        self::assertCount(0, $violations, (string) $violations);
    }

    /** A key the module has never heard of is still a mistake. */
    public function testAnUnknownFieldIsStillRejected(): void
    {
        $violations = $this->validate([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            'salary' => 100,
        ]);

        self::assertCount(1, $violations);
        self::assertSame('[salary]', $violations->get(0)->getPropertyPath());
    }

    /**
     * Both kinds are named by the same rule, because record_title skips whatever
     * is empty — so one mechanism names a person and a company (§5.4).
     */
    public function testEachVariantIsNamedByItsOwnFields(): void
    {
        $module = $this->module();
        $titles = array_map(static fn ($f): string => $f->getKey(), $module->getTitleFields());

        self::assertSame(['company_name', 'first_name', 'last_name'], $titles);
    }

    /** The link, stored on the person, pointing at the company (§7.6). */
    public function testAPersonCanBeLinkedToACompany(): void
    {
        $company = $this->save(['kind' => ContactModule::COMPANY, 'company_name' => 'Acme AG']);
        $person = $this->save([
            'kind' => ContactModule::PERSON,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'company' => $company->id,
        ]);

        $stored = $this->switcher->runFor($this->tenant, fn (): ?Record => self::service(RecordRepository::class)
            ->find($this->module(), (int) $person->id));

        // A plain id, because a contact is one module — not a type and an id.
        self::assertSame($company->id, $stored?->get('company'));
    }

    /**
     * The reverse: who works here. A query over the person's field rather than a
     * second stored list, so the two cannot disagree.
     */
    public function testACompanysPeopleAreFoundByQueryingTheLink(): void
    {
        $company = $this->save(['kind' => ContactModule::COMPANY, 'company_name' => 'Acme AG']);
        $other = $this->save(['kind' => ContactModule::COMPANY, 'company_name' => 'Globex']);

        $this->save(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'company' => $company->id]);
        $this->save(['kind' => ContactModule::PERSON, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'company' => $company->id]);
        $this->save(['kind' => ContactModule::PERSON, 'first_name' => 'Barbara', 'last_name' => 'Liskov', 'company' => $other->id]);

        $found = $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)->findBy(
            $this->module(),
            new RecordQuery([new Filter('company', Operator::Equals, (int) $company->id)]),
        ));

        $names = array_map(static fn (Record $r): string => (string) $r->get('first_name'), $found);
        sort($names);

        self::assertSame(['Ada', 'Grace'], $names);
    }

    /** Companies are contacts, so a filter finds them in the same table. */
    public function testCompaniesAndPeopleLiveInOneList(): void
    {
        $this->save(['kind' => ContactModule::COMPANY, 'company_name' => 'Acme AG']);
        $this->save(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $all = $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)
            ->findBy($this->module(), new RecordQuery()));
        self::assertCount(2, $all);

        $companies = $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)->findBy(
            $this->module(),
            new RecordQuery([new Filter('kind', Operator::Equals, ContactModule::COMPANY)]),
        ));
        self::assertCount(1, $companies);
        self::assertSame('Acme AG', $companies[0]->get('company_name'));
    }

    /** @param array<string, mixed> $data */
    private function save(array $data): Record
    {
        return $this->switcher->runFor($this->tenant, fn (): Record => self::service(RecordWriter::class)->save(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            new Record($data),
        ));
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data): \Symfony\Component\Validator\ConstraintViolationListInterface
    {
        return $this->switcher->runFor($this->tenant, fn () => self::service(RecordValidator::class)->validate(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            $data,
        ));
    }

    private function module(): \Xivi\Core\Entity\ModuleDefinition
    {
        return $this->switcher->runFor($this->tenant, fn () => self::service(MetadataRepository::class)
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

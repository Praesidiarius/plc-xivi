<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * The test harness testing itself.
 *
 * Sharing one database between the tests of a class is only safe if nothing
 * survives a test, and the part that is easy to get wrong is the definitions:
 * the previous arrangement truncated records and left a field added by one test
 * standing for the rest of the class. These two pairs fail if the rollback ever
 * stops happening — silently sharing a database is otherwise indistinguishable
 * from isolating one, right up until a test starts failing depending on which
 * other tests ran.
 *
 * The order matters here, deliberately: each pair writes and then looks. PHPUnit
 * runs the methods of a class in the order they are declared.
 */
final class RollbackIsolationTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_rollback';

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->sharedTenant(self::SLUG, ['rollback.localhost']);

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));
    }

    public function testARecordIsWritten(): void
    {
        $this->switcher->runFor($this->tenant, fn () => self::service(RecordWriter::class)->save(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            new Record(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace']),
        ));

        self::assertCount(1, $this->records());
    }

    public function testTheRecordIsGoneAgain(): void
    {
        self::assertCount(0, $this->records());
    }

    /** The half a truncate could not undo. */
    public function testAFieldIsAdded(): void
    {
        $this->switcher->runFor($this->tenant, fn () => self::service(MetadataEditor::class)->addField(
            shape: self::service(MetadataRepository::class)->get(ContactModule::KEY),
            key: 'vat_number',
            label: 'VAT number',
            type: 'text',
        ));

        self::assertContains('vat_number', $this->fieldKeys());
    }

    public function testTheFieldIsGoneAgain(): void
    {
        self::assertNotContains('vat_number', $this->fieldKeys());
    }

    /** @return list<Record> */
    private function records(): array
    {
        return $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)->findBy(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            new RecordQuery(),
        ));
    }

    /** @return list<string> */
    private function fieldKeys(): array
    {
        return $this->switcher->runFor($this->tenant, fn (): array => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY)->getFieldKeys());
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

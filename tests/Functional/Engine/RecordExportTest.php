<?php

declare(strict_types=1);

namespace App\Tests\Functional\Engine;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use OpenSpout\Reader\XLSX\Reader;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Export\RecordExporter;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * Records out as a spreadsheet.
 *
 * Read back with the same library that wrote it, because a test that only
 * checks the file is non-empty is a test that would pass on a corrupt workbook.
 */
final class RecordExportTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_export';

    private TenantSwitcher $switcher;
    private Tenant $tenant;
    private string $path;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);

        // One tenant for the class, emptied between tests (see SharesATenant).
        $this->tenant = $this->sharedTenant(self::SLUG, ['export.localhost']);

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));

        $this->clearRecords($this->tenant);

        $this->path = (string) tempnam(sys_get_temp_dir(), 'xivi-export-test-');
    }


    /** The header is the shape's field keys, which are what survive a relabel. */
    public function testTheHeaderComesFromTheDefinitions(): void
    {
        $sheets = $this->export(new RecordQuery());

        self::assertSame(
            ['id', 'kind', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'birthday', 'company'],
            $sheets['contact'][0],
        );
    }

    public function testRecordsAreWrittenInStorageForm(): void
    {
        $this->save([
            'kind' => ContactModule::PERSON,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ADA@example.com ',
            'birthday' => '1815-12-10',
        ]);

        $row = $this->export(new RecordQuery())['contact'][1];

        // The stored value of the choice, not its label — otherwise the file
        // could not be read back in.
        self::assertSame('person', $row[1]);
        self::assertSame('Ada', $row[3]);
        // Normalised on the way in, and exported as stored.
        self::assertSame('ada@example.com', $row[5]);
        self::assertSame('1815-12-10', $row[7]);
    }

    /** A company's person-only columns are simply blank (§5.5). */
    public function testBothVariantsShareOneSheet(): void
    {
        $this->save(['kind' => ContactModule::COMPANY, 'company_name' => 'Acme AG']);
        $this->save(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $rows = $this->export(new RecordQuery())['contact'];
        $byKind = [];

        foreach (\array_slice($rows, 1) as $row) {
            $byKind[$row[1]] = $row;
        }

        // Blank, not null: a null written to a cell reads back as an empty
        // string, which is the same absence spelled the way a spreadsheet spells
        // it — worth knowing for the import half.
        self::assertSame('Acme AG', $byKind['company'][2]);
        self::assertSame('', $byKind['company'][3], 'a company has no first name');
        self::assertSame('Ada', $byKind['person'][3]);
        self::assertSame('', $byKind['person'][2], 'a person has no company name');
    }

    /**
     * Collections get their own sheet, because a contact has many addresses and
     * they cannot share its row (§5.1). parent_id is what ties them back.
     */
    public function testACollectionBecomesItsOwnSheet(): void
    {
        $contact = $this->save(
            ['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            ['addresses' => [
                ['id' => null, 'data' => ['street' => 'Baker Street 1', 'city' => 'Zürich']],
                ['id' => null, 'data' => ['street' => 'Bahnhofstrasse 5', 'city' => 'Bern']],
            ]],
        );

        $sheets = $this->export(new RecordQuery());

        self::assertSame(
            ['id', 'parent_id', 'label', 'street', 'postal_code', 'city', 'country'],
            $sheets['addresses'][0],
        );
        self::assertCount(3, $sheets['addresses'], 'header plus two addresses');
        self::assertSame($contact->id, $sheets['addresses'][1][1]);
        self::assertSame('Baker Street 1', $sheets['addresses'][1][3]);
    }

    /**
     * A filtered export contains what the filter showed — and, importantly, only
     * the children of those records.
     */
    public function testAFilteredExportCarriesOnlyThoseRecordsAndTheirChildren(): void
    {
        $this->save(
            ['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            ['addresses' => [['id' => null, 'data' => ['street' => 'Baker Street 1']]]],
        );
        $this->save(
            ['kind' => ContactModule::PERSON, 'first_name' => 'Grace', 'last_name' => 'Hopper'],
            ['addresses' => [['id' => null, 'data' => ['street' => 'Kramgasse 2']]]],
        );

        $sheets = $this->export(new RecordQuery([new Filter('last_name', Operator::Equals, 'Hopper')]));

        self::assertCount(2, $sheets['contact'], 'header plus one record');
        self::assertSame('Grace', $sheets['contact'][1][3]);

        self::assertCount(2, $sheets['addresses'], 'header plus one address');
        self::assertSame('Kramgasse 2', $sheets['addresses'][1][3]);
    }

    /**
     * More records than fit in one batch, so the paging is actually walked.
     *
     * With a batch of four rather than the production five hundred: proving the
     * loop turns a page does not need five hundred rows written one at a time,
     * and it made this the slowest test in the suite.
     */
    public function testEveryMatchingRecordIsExportedNotJustTheFirstPage(): void
    {
        $total = 9;

        for ($i = 0; $i < $total; ++$i) {
            $this->save([
                'kind' => ContactModule::PERSON,
                'first_name' => 'Person' . $i,
                'last_name' => 'Number' . $i,
            ]);
        }

        $rows = $this->export(new RecordQuery(), batch: 4)['contact'];

        self::assertCount($total + 1, $rows, 'header plus every record');
    }

    public function testAModuleWithNoRecordsStillProducesAReadableFile(): void
    {
        $sheets = $this->export(new RecordQuery());

        self::assertCount(1, $sheets['contact'], 'just the header');
        self::assertCount(1, $sheets['addresses']);
    }

    /**
     * @param array<string, mixed>                                                $data
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $children
     */
    private function save(array $data, array $children = []): Record
    {
        return $this->switcher->runFor($this->tenant, fn (): Record => self::service(RecordWriter::class)->save(
            self::service(MetadataRepository::class)->get(ContactModule::KEY),
            new Record($data),
            $children,
        ));
    }

    /**
     * The written file, read back as sheet name => rows.
     *
     * @return array<string, list<list<mixed>>>
     */
    private function export(RecordQuery $query, ?int $batch = null): array
    {
        $this->switcher->runFor($this->tenant, function () use ($query, $batch): void {
            $exporter = $batch === null
                ? self::service(RecordExporter::class)
                : new RecordExporter(self::service(RecordRepository::class), $batch);

            $exporter->toFile(
                self::service(MetadataRepository::class)->get(ContactModule::KEY),
                $query,
                $this->path,
            );
        });

        $reader = new Reader();
        $reader->open($this->path);

        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];

            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_values($row->toArray());
            }

            $sheets[$sheet->getName()] = $rows;
        }

        $reader->close();

        return $sheets;
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

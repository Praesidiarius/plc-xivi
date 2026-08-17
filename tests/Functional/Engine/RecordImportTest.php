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
use App\Tenancy\TenantSwitcher;
use App\Tests\Support\SharesATenant;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Import\ImportProblem;
use Xivi\Core\Import\ImportReport;
use Xivi\Core\Import\RecordImporter;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * A spreadsheet back in — the other half of §5.6.
 *
 * Every test builds a real xlsx with the same library the exporter writes with,
 * because an import tested against arrays would be a test of the part that was
 * never in doubt.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordImportTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_import';

    /** The contact sheet as the exporter writes it. */
    private const array HEADER = ['id', 'kind', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'birthday', 'company'];

    private TenantSwitcher $switcher;
    private Tenant $tenant;
    private string $path;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->sharedTenant(self::SLUG, ['import.localhost']);

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));

        $this->path = (string) tempnam(sys_get_temp_dir(), 'xivi-import-test-');
    }

    public function testRowsWithoutAnIdBecomeNewRecords(): void
    {
        $this->file(['contact' => [
            self::HEADER,
            ['', 'person', '', 'Ada', 'Lovelace', 'ada@example.com', '', '', ''],
            ['', 'company', 'Acme AG', '', '', '', '', '', ''],
        ]]);

        $report = $this->apply();

        self::assertTrue($report->applied, implode(' | ', $this->messages($report)));
        self::assertSame(2, $report->created);
        self::assertSame(0, $report->updated);
        self::assertCount(2, $this->all());
    }

    /** What makes export → fix in a spreadsheet → import a round trip. */
    public function testARowWithAnIdUpdatesThatRecord(): void
    {
        $contact = $this->save(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->file(['contact' => [
            self::HEADER,
            [$contact->id, 'person', '', 'Ada', 'King', 'ada@example.com', '', '', ''],
        ]]);

        $report = $this->apply();

        self::assertSame(0, $report->created);
        self::assertSame(1, $report->updated);

        $records = $this->all();
        self::assertCount(1, $records, 'the record was corrected, not duplicated');
        self::assertSame('King', $records[0]->data['last_name']);
    }

    /**
     * A field the file has no column for keeps what it had. A three-column
     * correction should correct three things, not blank everything else.
     */
    public function testAFieldWithNoColumnIsLeftAlone(): void
    {
        $contact = $this->save([
            'kind' => ContactModule::PERSON,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '+41 44 000 00 00',
        ]);

        $this->file(['contact' => [
            ['id', 'last_name'],
            [$contact->id, 'King'],
        ]]);

        self::assertTrue($this->apply()->applied);

        $record = $this->all()[0];
        self::assertSame('King', $record->data['last_name']);
        self::assertSame('+41 44 000 00 00', $record->data['phone'], 'a column the file does not have is not a value to clear');
    }

    /** Lenient in, stable out (§5.6): the export writes keys, the import takes either. */
    public function testAColumnMayBeNamedByItsLabel(): void
    {
        $labels = $this->switcher->runFor($this->tenant, fn (): array => [
            self::module()->getField('first_name')?->getLabel(),
            self::module()->getField('last_name')?->getLabel(),
        ]);

        $this->file(['contact' => [
            ['kind', $labels[0], $labels[1]],
            ['person', 'Grace', 'Hopper'],
        ]]);

        self::assertTrue($this->apply()->applied);
        self::assertSame('Grace', $this->all()[0]->data['first_name']);
    }

    public function testAColumnMatchingNoFieldRefusesTheFile(): void
    {
        $this->file(['contact' => [
            ['kind', 'first_name', 'last_name', 'favourite_colour'],
            ['person', 'Ada', 'Lovelace', 'green'],
        ]]);

        $report = $this->apply();

        self::assertFalse($report->applied);
        self::assertStringContainsString('favourite_colour', $this->messages($report)[0]);
        self::assertSame([], $this->all(), 'nothing is written when the file is refused');
    }

    /** A mistyped id would otherwise quietly duplicate the record it meant to fix. */
    public function testAnIdThatNamesNoRecordIsRefused(): void
    {
        $this->file(['contact' => [
            self::HEADER,
            [4242, 'person', '', 'Ada', 'Lovelace', '', '', '', ''],
        ]]);

        $report = $this->apply();

        self::assertFalse($report->applied);
        self::assertStringContainsString('4242', $this->messages($report)[0]);
        self::assertSame([], $this->all());
    }

    /** All or nothing: the good rows of a bad file are not a state to be left in. */
    public function testOneUnvalidatableRowRefusesEverySoundOneWithIt(): void
    {
        $this->file(['contact' => [
            self::HEADER,
            ['', 'person', '', 'Ada', 'Lovelace', '', '', '', ''],
            // A person with no last name; the definitions say it is required.
            ['', 'person', '', 'Grace', '', '', '', '', ''],
        ]]);

        $report = $this->apply();

        self::assertFalse($report->applied);
        self::assertSame([], $this->all(), 'the sound row is not kept either');
        self::assertStringContainsString('row 3', $this->messages($report)[0]);
    }

    /**
     * Two new rows claiming one unique email. Neither exists when the file is
     * read, so only actually writing the first one turns the second into a
     * collision — which is the argument for a check that writes and rolls back.
     */
    public function testRowsThatCollideWithEachOtherAreCaught(): void
    {
        $this->file(['contact' => [
            self::HEADER,
            ['', 'person', '', 'Ada', 'Lovelace', 'same@example.com', '', '', ''],
            ['', 'person', '', 'Grace', 'Hopper', 'same@example.com', '', '', ''],
        ]]);

        $report = $this->check();

        self::assertFalse($report->isClean());
        self::assertStringContainsString('row 3', $this->messages($report)[0]);
    }

    public function testACheckWritesNothingButReportsWhatWouldHappen(): void
    {
        $this->file(['contact' => [
            self::HEADER,
            ['', 'person', '', 'Ada', 'Lovelace', '', '', '', ''],
        ]]);

        $report = $this->check();

        self::assertTrue($report->isClean());
        self::assertFalse($report->applied, 'a check never keeps anything');
        self::assertSame(1, $report->created, 'and still says what it would have created');
        self::assertSame([], $this->all());
    }

    public function testCollectionRowsAttachToAnExistingParent(): void
    {
        $contact = $this->save(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->file([
            'contact' => [self::HEADER, [$contact->id, 'person', '', 'Ada', 'Lovelace', '', '', '', '']],
            'addresses' => [
                ['id', 'parent_id', 'label', 'street', 'postal_code', 'city', 'country'],
                ['', $contact->id, 'Home', 'Baker Street 1', '8000', 'Zürich', 'CH'],
            ],
        ]);

        $report = $this->apply();

        self::assertTrue($report->applied, implode(' | ', $this->messages($report)));
        self::assertSame(1, $report->childrenWritten());
        self::assertSame('Baker Street 1', $this->addresses((int) $contact->id)[0]->data['street']);
    }

    /**
     * A record and its children arriving together, from a system that had its own
     * ids. The contact's id cell is a name this file made up; the address points
     * at that name and lands on the record it created.
     */
    public function testCollectionRowsAttachToARecordTheFileIsCreating(): void
    {
        $this->file([
            'contact' => [self::HEADER, ['acme-1', 'person', '', 'Grace', 'Hopper', '', '', '', '']],
            'addresses' => [
                ['id', 'parent_id', 'label', 'street', 'postal_code', 'city', 'country'],
                ['', 'acme-1', 'Home', 'Kramgasse 2', '3011', 'Bern', 'CH'],
            ],
        ]);

        $report = $this->apply();

        self::assertTrue($report->applied, implode(' | ', $this->messages($report)));
        self::assertSame(1, $report->created);

        $contact = $this->all()[0];
        self::assertSame('Kramgasse 2', $this->addresses((int) $contact->id)[0]->data['street']);
    }

    /**
     * The everyday case: several new contacts arriving at once, each with its own
     * address. Each contact names itself, and each address says which name it
     * belongs to — the only way the file can tell them apart before any of them
     * has an id.
     */
    public function testSeveralNewRecordsEachKeepTheirOwnChildren(): void
    {
        $this->file([
            'contact' => [
                self::HEADER,
                ['ada', 'person', '', 'Ada', 'Lovelace', '', '', '', ''],
                ['grace', 'person', '', 'Grace', 'Hopper', '', '', '', ''],
            ],
            'addresses' => [
                ['id', 'parent_id', 'label', 'street', 'postal_code', 'city', 'country'],
                ['', 'grace', 'Home', 'Kramgasse 2', '3011', 'Bern', 'CH'],
                ['', 'ada', 'Home', 'Baker Street 1', '8000', 'Zürich', 'CH'],
            ],
        ]);

        $report = $this->apply();

        self::assertTrue($report->applied, implode(' | ', $this->messages($report)));
        self::assertSame(2, $report->created);
        self::assertSame(2, $report->childrenWritten());

        $streets = [];
        foreach ($this->all() as $contact) {
            $streets[$contact->data['first_name']] = array_map(
                static fn (Record $address): mixed => $address->data['street'],
                $this->addresses((int) $contact->id),
            );
        }

        // Listed in the other order in the file, on purpose: the rows are matched
        // by name, not by the order they happen to appear in.
        self::assertSame(['Baker Street 1'], $streets['Ada']);
        self::assertSame(['Kramgasse 2'], $streets['Grace']);
    }

    /**
     * Leaving the id empty is the instinct, and it cannot work: a record with no
     * name in the file is a record nothing can point at. Said out loud rather
     * than dropped, because a silently address-less import is worse.
     */
    public function testAnAddressCannotBeAttachedToARecordThatNamedItselfNothing(): void
    {
        $this->file([
            'contact' => [self::HEADER, ['', 'person', '', 'Ada', 'Lovelace', '', '', '', '']],
            'addresses' => [
                ['id', 'parent_id', 'label', 'street', 'postal_code', 'city', 'country'],
                ['', '', 'Home', 'Baker Street 1', '', '', ''],
            ],
        ]);

        $report = $this->apply();

        self::assertFalse($report->applied);
        self::assertStringContainsString('names no parent', $this->messages($report)[0]);
        self::assertSame([], $this->all(), 'the contact is not created without its address');
    }

    /**
     * The child would have to be attached by loading a record the file never
     * mentions, which is a two-line file reaching into anything.
     */
    public function testACollectionRowNamingAParentOutsideTheFileIsRefused(): void
    {
        $this->file([
            'contact' => [self::HEADER, ['', 'person', '', 'Ada', 'Lovelace', '', '', '', '']],
            'addresses' => [
                ['id', 'parent_id', 'label', 'street', 'postal_code', 'city', 'country'],
                ['', '999', 'Home', 'Baker Street 1', '', '', ''],
            ],
        ]);

        $report = $this->apply();

        self::assertFalse($report->applied);
        self::assertStringContainsString('999', $this->messages($report)[0]);
    }

    /**
     * The destructive half. A sheet that is present speaks for the whole
     * collection, so a row deleted from the file is a row deleted from the record.
     */
    public function testAnAddressTheFileNoLongerListsIsRemoved(): void
    {
        $contact = $this->save(
            ['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            ['addresses' => [
                ['id' => null, 'data' => ['street' => 'Baker Street 1']],
                ['id' => null, 'data' => ['street' => 'Bahnhofstrasse 5']],
            ]],
        );

        $keep = $this->addresses((int) $contact->id)[0];

        $this->file([
            'contact' => [self::HEADER, [$contact->id, 'person', '', 'Ada', 'Lovelace', '', '', '', '']],
            'addresses' => [
                ['id', 'parent_id', 'label', 'street', 'postal_code', 'city', 'country'],
                [$keep->id, $contact->id, '', 'Baker Street 1', '', '', ''],
            ],
        ]);

        $report = $this->check();

        self::assertSame(1, $report->childrenRemoved(), 'a check has to say what an import would destroy');

        self::assertTrue($this->apply()->applied);
        self::assertCount(1, $this->addresses((int) $contact->id));
    }

    /**
     * No sheet is the file saying nothing about addresses, which is not the same
     * as saying there are none.
     */
    public function testACollectionWithNoSheetIsLeftAlone(): void
    {
        $contact = $this->save(
            ['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            ['addresses' => [['id' => null, 'data' => ['street' => 'Baker Street 1']]]],
        );

        $this->file(['contact' => [
            self::HEADER,
            [$contact->id, 'person', '', 'Ada', 'King', '', '', '', ''],
        ]]);

        self::assertTrue($this->apply()->applied);
        self::assertCount(1, $this->addresses((int) $contact->id), 'the address survives an import that never mentioned it');
    }

    /** A sheet the module knows nothing about is data this import would drop. */
    public function testASheetMatchingNothingRefusesTheFile(): void
    {
        $this->file([
            'contact' => [self::HEADER, ['', 'person', '', 'Ada', 'Lovelace', '', '', '', '']],
            'invoices' => [['id', 'total'], ['', '100']],
        ]);

        $report = $this->apply();

        self::assertFalse($report->applied);
        self::assertStringContainsString('invoices', $this->messages($report)[0]);
    }

    public function testAFileWithNoSheetForTheModuleIsRefused(): void
    {
        $this->file(['something_else' => [['a'], ['b']]]);

        $report = $this->apply();

        self::assertFalse($report->applied);
        self::assertStringContainsString('contact', $this->messages($report)[0]);
    }

    /**
     * The two halves of §5.6 against each other: export a module, import the
     * file back unedited, and nothing should have changed or doubled.
     *
     * The test the pair is actually for. Either half can be individually correct
     * and still disagree about a sheet name, a header or a stored value, and this
     * is the only test that would notice.
     */
    public function testAnExportCanBeImportedBackUnchanged(): void
    {
        $contact = $this->save(
            [
                'kind' => ContactModule::PERSON,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.com',
                'birthday' => '1815-12-10',
            ],
            ['addresses' => [['id' => null, 'data' => ['street' => 'Baker Street 1', 'city' => 'Zürich']]]],
        );

        $this->switcher->runFor($this->tenant, fn () => self::service(\Xivi\Core\Export\RecordExporter::class)
            ->toFile(self::module(), new RecordQuery(), RecordAccess::unrestricted(), $this->path));

        $report = $this->apply();

        self::assertTrue($report->applied, implode(' | ', $this->messages($report)));
        self::assertSame(0, $report->created, 'a re-import is not a second copy');
        self::assertSame(1, $report->updated);
        self::assertSame(0, $report->childrenRemoved(), 'the file lists the address it exported');

        $records = $this->all();
        self::assertCount(1, $records);
        self::assertSame('ada@example.com', $records[0]->data['email'], 'the unique email did not collide with itself');
        self::assertSame('1815-12-10', $records[0]->data['birthday']?->format('Y-m-d'));
        self::assertCount(1, $this->addresses((int) $contact->id));
    }

    /** Every imported row is a change somebody made, and says so (§5.2). */
    public function testAnImportedRecordGetsItsHistoryEntry(): void
    {
        $this->file(['contact' => [
            self::HEADER,
            ['', 'person', '', 'Ada', 'Lovelace', '', '', '', ''],
        ]]);

        self::assertTrue($this->apply()->applied);

        $contact = $this->all()[0];
        $history = $this->switcher->runFor(
            $this->tenant,
            fn (): array => self::service(HistoryRepository::class)->findFor(self::module(), (int) $contact->id),
        );

        self::assertCount(1, $history);
    }

    /**
     * @param array<string, list<list<mixed>>> $sheets
     */
    private function file(array $sheets): void
    {
        $writer = new Writer();
        $writer->openToFile($this->path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $first = false;

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();
    }

    private function apply(): ImportReport
    {
        return $this->switcher->runFor($this->tenant, fn (): ImportReport => self::service(RecordImporter::class)
            ->apply(self::module(), $this->path));
    }

    private function check(): ImportReport
    {
        return $this->switcher->runFor($this->tenant, fn (): ImportReport => self::service(RecordImporter::class)
            ->check(self::module(), $this->path));
    }

    /**
     * The problems as somebody would read them.
     *
     * Translated rather than read raw, because a problem carries a key now
     * (XIV-8) — and asserting on the key would stop noticing whether the
     * sentence it names still exists.
     *
     * @return list<string>
     */
    private function messages(ImportReport $report): array
    {
        $translator = self::service(\Symfony\Contracts\Translation\TranslatorInterface::class);

        return array_map(
            static fn (ImportProblem $problem): string => $problem->translatable()->trans($translator, 'en'),
            $report->problems,
        );
    }

    /** @return list<Record> */
    private function all(): array
    {
        return $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)
            ->findBy(self::module(), new RecordQuery(), RecordAccess::unrestricted()));
    }

    /** @return list<Record> */
    private function addresses(int $parentId): array
    {
        return $this->switcher->runFor($this->tenant, function () use ($parentId): array {
            $collection = self::module()->getCollection('addresses');
            \assert($collection !== null);

            return self::service(RecordRepository::class)->findChildren($collection, $parentId);
        });
    }

    /**
     * @param array<string, mixed>                                                 $data
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $children
     */
    private function save(array $data, array $children = []): Record
    {
        return $this->switcher->runFor($this->tenant, fn (): Record => self::service(RecordWriter::class)
            ->save(self::module(), new Record($data), $children));
    }

    private static function module(): \Xivi\Core\Entity\ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(ContactModule::KEY);
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

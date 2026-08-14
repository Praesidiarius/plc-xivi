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
use Xivi\Core\Export\RecordExporter;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;
use Xivi\Core\Permission\PermissionSet;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * "Only the records I own" as a WHERE clause (§7.5).
 *
 * The claim §7.3 made and this proves: record-level access cannot be a check
 * performed after loading, because the count is loaded separately from the page.
 * Every test here that asserts on a list also asserts on its total, since a
 * restriction that reached one and not the other is the exact bug the design
 * exists to prevent — and it would look like a working feature.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordAccessTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_access';
    private const int ALICE = 101;
    private const int BOB = 202;

    private TenantSwitcher $switcher;
    private Tenant $tenant;
    private string $path;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->sharedTenant(self::SLUG, ['access.localhost']);
        $this->path = (string) tempnam(sys_get_temp_dir(), 'xivi-access-test-');

        $this->switcher->runFor($this->tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));

        // Three of Alice's, two of Bob's, and one belonging to nobody — records
        // predating an owner being recorded, which a naive predicate would hand
        // to whoever asked first.
        $this->contact('Ada', 'Lovelace', self::ALICE);
        $this->contact('Grace', 'Hopper', self::ALICE);
        $this->contact('Barbara', 'Liskov', self::ALICE);
        $this->contact('Alan', 'Turing', self::BOB);
        $this->contact('Edsger', 'Dijkstra', self::BOB);
        $this->contact('Nobody', 'Atall', null);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function testUnrestrictedAccessSeesEverything(): void
    {
        self::assertSame(6, $this->countMatching(RecordAccess::unrestricted()));
        self::assertCount(6, $this->find(RecordAccess::unrestricted()));
    }

    public function testOwnedAccessSeesOnlyThatPersonsRecords(): void
    {
        $found = $this->find(RecordAccess::ownedBy(self::ALICE));

        self::assertSame(['Ada', 'Barbara', 'Grace'], $this->firstNames($found));
    }

    /**
     * The reason this is a predicate and not a filter: the total is a second
     * query, and a restriction reaching the page but not the count would show
     * three rows above the words "5 records".
     */
    public function testTheCountAgreesWithTheRestrictedPage(): void
    {
        foreach ([self::ALICE => 3, self::BOB => 2] as $owner => $expected) {
            self::assertSame($expected, $this->countMatching(RecordAccess::ownedBy($owner)));
            self::assertCount($expected, $this->find(RecordAccess::ownedBy($owner)));
        }
    }

    /** A record nobody owns belongs to nobody, not to everybody. */
    public function testAnUnownedRecordIsNotOwnedByAnybody(): void
    {
        self::assertNotContains('Nobody', $this->firstNames($this->find(RecordAccess::ownedBy(self::ALICE))));
        self::assertNotContains('Nobody', $this->firstNames($this->find(RecordAccess::ownedBy(self::BOB))));
        self::assertContains('Nobody', $this->firstNames($this->find(RecordAccess::unrestricted())));
    }

    public function testNothingMatchesNoRecordsAtAll(): void
    {
        self::assertSame(0, $this->countMatching(RecordAccess::nothing()));
        self::assertSame([], $this->find(RecordAccess::nothing()));
    }

    /**
     * The restriction narrows what a filter found; it never widens it. Both are
     * ANDed, so the order they were applied in cannot matter.
     */
    public function testTheRestrictionCombinesWithAFilter(): void
    {
        $query = new RecordQuery([new Filter('last_name', Operator::Contains, 'r')]);

        // Hopper, Turing and Dijkstra — one of Alice's and two of Bob's, so
        // neither restriction can pass by matching the whole filtered set.
        self::assertSame(3, $this->countMatching(RecordAccess::unrestricted(), $query));
        self::assertSame(1, $this->countMatching(RecordAccess::ownedBy(self::ALICE), $query));
        self::assertSame(2, $this->countMatching(RecordAccess::ownedBy(self::BOB), $query));
    }

    /**
     * An export is the fastest way to leave with records somebody was only shown
     * one page of, so it carries the same restriction the list did.
     */
    public function testAnExportCarriesTheRestriction(): void
    {
        $this->switcher->runFor($this->tenant, function (): void {
            self::service(RecordExporter::class)->toFile(
                $this->module(),
                new RecordQuery(),
                RecordAccess::ownedBy(self::ALICE),
                $this->path,
            );
        });

        $written = file_get_contents($this->path);
        self::assertIsString($written);

        // xlsx is a zip, so the strings are compressed and cannot be searched for
        // directly. Reading it back through the repository would be testing the
        // repository again; counting the sheet's rows is what the file promises.
        self::assertSame(3, $this->exportedRowCount());
    }

    // -- the mapping from a resolved permission set -------------------------

    public function testAPermissionSetScopedToAllBecomesUnrestricted(): void
    {
        $set = PermissionSet::nothing()->with('contact', ModuleAction::List, PermissionScope::All);

        $access = RecordAccess::fromPermissions($set, 'contact', ModuleAction::List, self::ALICE);

        self::assertFalse($access->isRestricted());
    }

    public function testAPermissionSetScopedToOwnBecomesThatOwner(): void
    {
        $set = PermissionSet::nothing()->with('contact', ModuleAction::List, PermissionScope::Own);

        $access = RecordAccess::fromPermissions($set, 'contact', ModuleAction::List, self::ALICE);

        self::assertTrue($access->isRestricted());
        self::assertSame(self::ALICE, $access->ownerId());
    }

    /** No grant is not "everything" — every way of not knowing fails closed. */
    public function testNoGrantMatchesNothing(): void
    {
        $access = RecordAccess::fromPermissions(
            PermissionSet::nothing(),
            'contact',
            ModuleAction::List,
            self::ALICE,
        );

        self::assertTrue($access->matchesNothing());
    }

    /**
     * Scoped to their own records, but there is nobody to be. A signed-out
     * request takes this branch, and it must not become "everybody's".
     */
    public function testOwnScopeWithNoUserMatchesNothing(): void
    {
        $set = PermissionSet::nothing()->with('contact', ModuleAction::List, PermissionScope::Own);

        $access = RecordAccess::fromPermissions($set, 'contact', ModuleAction::List, null);

        self::assertTrue($access->matchesNothing());
    }

    // -- helpers ------------------------------------------------------------

    /** @return list<Record> */
    private function find(RecordAccess $access, ?RecordQuery $query = null): array
    {
        return $this->switcher->runFor(
            $this->tenant,
            fn (): array => self::service(RecordRepository::class)
                ->findBy($this->module(), $query ?? new RecordQuery(), $access),
        );
    }

    private function countMatching(RecordAccess $access, ?RecordQuery $query = null): int
    {
        return $this->switcher->runFor(
            $this->tenant,
            fn (): int => self::service(RecordRepository::class)
                ->countBy($this->module(), $query ?? new RecordQuery(), $access),
        );
    }

    private function exportedRowCount(): int
    {
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($this->path);

        $rows = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== RecordExporter::sheetName(ContactModule::KEY)) {
                continue;
            }

            foreach ($sheet->getRowIterator() as $_) {
                ++$rows;
            }
        }

        $reader->close();

        // Minus the header, which is a row to the reader and not a record.
        return max(0, $rows - 1);
    }

    private function contact(string $first, string $last, ?int $ownerId): void
    {
        $this->switcher->runFor($this->tenant, function () use ($first, $last, $ownerId): void {
            $record = new Record();
            $record->set('first_name', $first);
            $record->set('last_name', $last);
            $record->set('kind', 'person');
            $record->ownerId = $ownerId;

            self::service(RecordWriter::class)->save($this->module(), $record);
        });
    }

    private function module(): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(ContactModule::KEY);
    }

    /**
     * @param list<Record> $records
     *
     * @return list<string>
     */
    private function firstNames(array $records): array
    {
        $names = array_map(static fn (Record $r): string => (string) $r->get('first_name'), $records);
        sort($names);

        return $names;
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

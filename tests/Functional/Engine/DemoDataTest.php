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
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Demo\DemoDataGenerator;
use Xivi\Core\Demo\DemoLedger;
use Xivi\Core\Demo\FieldSampler;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\History\HistoryEntry;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Lifecycle\Lifecycles;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\DerivedValues;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Validation\RecordValidator;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;

/**
 * Demo data generated from a module's own definitions.
 *
 * The claim under test is not "rows appeared". It is that the generator knows
 * nothing about contacts and still produces records the module itself would
 * accept — and that everything it made can be taken back out again.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DemoDataTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_demo';

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->sharedTenant(self::SLUG, ['demo.localhost']);

        $this->switcher->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $modules = self::service(ModuleRegistry::class);

            $installer->install($modules->get(ContactModule::KEY));
            // Contact declares no samples anywhere and article declares two, so
            // one tenant carries both halves of XIV-24: the field nobody has said
            // anything about, and the field that says something.
            $installer->install($modules->get(ArticleModule::KEY));
        });
    }

    public function testItGeneratesAsManyRecordsAsAsked(): void
    {
        self::assertSame(30, $this->generate(30));
        self::assertCount(30, $this->records());
    }

    /**
     * The claim the whole design rests on: nothing in the generator knows what a
     * contact is, and every record still passes the validation those same
     * definitions build.
     *
     * Run for the article module too, because a declared value is written
     * without anything checking it (XIV-24) — a `samples` list is worth exactly
     * as much care as a default, and this is where a declaration the field would
     * refuse shows up.
     */
    #[TestWith([ContactModule::KEY])]
    #[TestWith([ArticleModule::KEY])]
    public function testEveryGeneratedRecordPassesTheModulesOwnValidation(string $key): void
    {
        $this->generate(40, module: $key);

        $this->switcher->runFor($this->tenant, function () use ($key): void {
            $validator = self::service(RecordValidator::class);
            $module = self::module($key);

            foreach (self::service(RecordRepository::class)->findBy($module, new RecordQuery(perPage: 100), RecordAccess::unrestricted()) as $record) {
                $violations = $validator->validate($module, $record->data, $record->id);

                self::assertCount(0, $violations, sprintf(
                    'record %d: %s',
                    (int) $record->id,
                    implode(', ', array_map(static fn ($v): string => $v->getPropertyPath() . ' ' . $v->getMessage(), iterator_to_array($violations))),
                ));
            }
        });
    }

    /**
     * The variant field is a choice, so sampling it produces both kinds without
     * the generator having heard the words "person" or "company" (§5.5).
     */
    public function testBothVariantsAreGenerated(): void
    {
        $this->generate(40);

        $kinds = [];
        foreach ($this->records() as $record) {
            $kinds[(string) $record->data['kind']] = true;
        }

        self::assertArrayHasKey(ContactModule::PERSON, $kinds);
        self::assertArrayHasKey(ContactModule::COMPANY, $kinds);
    }

    /** A company has no first name and a person no company name, as ever (§5.5). */
    public function testAVariantsOwnFieldsAreTheOnlyOnesFilled(): void
    {
        $this->generate(40);

        foreach ($this->records() as $record) {
            if ($record->data['kind'] === ContactModule::COMPANY) {
                self::assertNull($record->data['first_name'], 'a company was given a first name');
                self::assertNotNull($record->data['company_name']);
            } else {
                self::assertNull($record->data['company_name'], 'a person was given a company name');
            }
        }
    }

    /**
     * The distribution is the point. Every record having exactly one address
     * would hide both the empty case and the crowded one.
     */
    public function testCollectionsGetASpreadNotAFixedNumber(): void
    {
        $this->generate(40);

        $counts = [];
        $this->switcher->runFor($this->tenant, function () use (&$counts): void {
            $collection = self::module()->getCollection('addresses');
            \assert($collection !== null);
            $records = self::service(RecordRepository::class);

            foreach ($records->findBy(self::module(), new RecordQuery(perPage: 100), RecordAccess::unrestricted()) as $record) {
                $counts[] = \count($records->findChildren($collection, (int) $record->id));
            }
        });

        self::assertContains(0, $counts, 'no record was left without an address');
        self::assertContains(1, $counts);
        self::assertTrue(max($counts) > 1, 'no record got more than one address');
    }

    /** Unique fields have to survive volume, not just a handful. */
    public function testAUniqueFieldDoesNotCollide(): void
    {
        $this->generate(120);

        $emails = [];
        foreach ($this->records(200) as $record) {
            $email = $record->data['email'];

            if ($email !== null) {
                $emails[] = $email;
            }
        }

        self::assertSame($emails, array_values(array_unique($emails)));
    }

    /** What makes "it broke on record 4,312" something somebody else can see. */
    public function testTheSameSeedProducesTheSameRecords(): void
    {
        $this->generate(10, seed: 99);
        $first = array_map(static fn (Record $r): mixed => $r->data['email'], $this->records());

        $this->purge();

        $this->generate(10, seed: 99);
        $second = array_map(static fn (Record $r): mixed => $r->data['email'], $this->records());

        self::assertSame($first, $second);
    }

    public function testADifferentSeedProducesDifferentRecords(): void
    {
        $this->generate(10, seed: 1);
        $first = array_map(static fn (Record $r): mixed => $r->data['email'], $this->records());

        $this->purge();

        $this->generate(10, seed: 2);

        self::assertNotSame($first, array_map(static fn (Record $r): mixed => $r->data['email'], $this->records()));
    }

    /**
     * The criterion that protects every field nobody has said anything about
     * (XIV-24), asserted rather than assumed.
     *
     * For every field that has *not* declared anything, the sampler has to
     * return what the field's own type returns *and* leave the seeded sequence
     * in the same place — a single extra call to mt_rand anywhere on this path
     * would shift every value after it, which is how "nothing changed" quietly
     * becomes "everything changed by one".
     *
     * The declared ones are taken out of both runs rather than allowed to
     * disagree in one of them: a field that says something is supposed to be
     * sampled differently, and leaving it in would make this assert the opposite
     * of what it is for. Contact carried none of them until XIV-73 gave
     * `payment_terms` a list, which is exactly the drift this filter absorbs.
     *
     * Compared value for value against the types themselves rather than against
     * a recorded snapshot, because a snapshot would also be asserting which
     * names this version of Faker happens to hold.
     */
    public function testAFieldThatDeclaresNothingIsSampledExactlyAsItWasBefore(): void
    {
        $this->switcher->runFor($this->tenant, function (): void {
            $fields = array_values(array_filter(
                self::everyFieldOf(self::module()),
                static fn (FieldDefinition $field): bool => $field->getOption(FieldSampler::OPTION) === null,
            ));
            self::assertNotEmpty($fields);

            $sampler = self::service(FieldSampler::class);
            $types = self::service(FieldTypeRegistry::class);

            mt_srand(4242);
            $viaSampler = [];
            foreach ($fields as $field) {
                for ($sequence = 1; $sequence <= 5; ++$sequence) {
                    $viaSampler[] = $sampler->sample($field, $sequence);
                }
            }

            mt_srand(4242);
            $viaType = [];
            foreach ($fields as $field) {
                for ($sequence = 1; $sequence <= 5; ++$sequence) {
                    $viaType[] = $types->get($field->getType())->sample($field, $sequence);
                }
            }

            self::assertSame($viaType, $viaSampler);
        });
    }

    /** A declared list is where the values come from, and the only place. */
    public function testADeclaredFieldIsFilledFromItsOwnList(): void
    {
        $this->generate(60, module: ArticleModule::KEY);

        $titles = [];
        foreach ($this->records(100, ArticleModule::KEY) as $record) {
            $titles[(string) $record->data['title']] = true;
            self::assertContains($record->data['tax_rate'], ['8.10', '2.60', '3.80', null], 'a tax rate nobody declared');
        }

        // What the ticket was opened about: a catalogue full of "Kuhn GmbH".
        foreach (array_keys($titles) as $title) {
            self::assertNotEmpty($title);
            self::assertStringNotContainsString('GmbH', $title, 'an article is being sold as a company');
        }

        self::assertGreaterThan(1, \count($titles), 'every article got the same title');
    }

    /**
     * "Including some with none at all" (XIV-24). An article sold without VAT is
     * a real case and the one whose totals are easiest to get wrong, so it is in
     * the declared list rather than in a weighting nobody can read.
     */
    public function testADeclaredEmptyValueIsGeneratedToo(): void
    {
        $this->generate(60, module: ArticleModule::KEY);

        $rates = array_map(
            static fn (Record $r): mixed => $r->data['tax_rate'],
            $this->records(100, ArticleModule::KEY),
        );

        self::assertContains(null, $rates, 'no article was left without VAT');
        self::assertGreaterThan(1, \count(array_unique(array_filter($rates), \SORT_REGULAR)), 'only one rate was ever drawn');
    }

    /** Declared or not, the seed is still what decides which record gets what. */
    public function testTheSameSeedProducesTheSameRecordsWhenSamplesAreDeclared(): void
    {
        $of = fn (): array => array_map(
            static fn (Record $r): array => [$r->data['title'], $r->data['tax_rate']],
            $this->records(100, ArticleModule::KEY),
        );

        $this->generate(20, seed: 99, module: ArticleModule::KEY);
        $first = $of();

        $this->purge(ArticleModule::KEY);

        $this->generate(20, seed: 99, module: ArticleModule::KEY);

        self::assertSame($first, $of());
        self::assertGreaterThan(1, \count(array_unique(array_column($first, 0))), 'the seed produced one value twenty times');
    }

    /**
     * A required field cannot be empty, so a null among its samples is dropped
     * rather than written — the generator's promise is that everything it makes
     * passes the module's own validation, and a declaration is not allowed to
     * break it.
     */
    public function testARequiredFieldIsNeverGivenADeclaredEmptyValue(): void
    {
        $field = self::declared(['Bürostuhl', null, ''], required: true);
        $sampler = self::service(FieldSampler::class);

        mt_srand(3);
        for ($sequence = 1; $sequence <= 40; ++$sequence) {
            self::assertSame('Bürostuhl', $sampler->sample($field, $sequence));
        }
    }

    /**
     * A unique field is the one a fixed list cannot fill: the second record drawn
     * from it collides. The type's own sample puts the sequence number on the
     * end, so the declaration is ignored rather than honoured into a duplicate.
     */
    public function testAUniqueFieldKeepsItsTypesSample(): void
    {
        $field = self::declared(['Bürostuhl'], required: true, unique: true);
        $sampler = self::service(FieldSampler::class);

        $values = [];
        mt_srand(3);
        for ($sequence = 1; $sequence <= 20; ++$sequence) {
            $values[] = $sampler->sample($field, $sequence);
        }

        self::assertNotContains('Bürostuhl', $values);
        self::assertSame($values, array_values(array_unique($values)));
    }

    /**
     * A text field on a shape nothing has installed, so the sampler can be asked
     * about a declaration without a module having to carry one for the test.
     *
     * @param list<mixed> $samples
     */
    private static function declared(array $samples, bool $required = false, bool $unique = false): FieldDefinition
    {
        $field = new FieldDefinition(
            shape: new ModuleDefinition('demo_shape', 'Demo', 'demo_shape'),
            key: 'title',
            label: 'Title',
            type: 'text',
            required: $required,
            unique: $unique,
        );
        $field->setOptions([FieldSampler::OPTION => $samples]);

        return $field;
    }

    /**
     * Every field of a module and of its collections, since a collection's field
     * is sampled by exactly the same call.
     *
     * @return list<FieldDefinition>
     */
    private static function everyFieldOf(ModuleDefinition $module): array
    {
        $fields = array_values($module->getFields()->toArray());

        foreach ($module->getCollections() as $collection) {
            $fields = [...$fields, ...array_values($collection->getFields()->toArray())];
        }

        return $fields;
    }

    /** Generated records are records, so they have a history like any other (§5.2). */
    public function testGeneratedRecordsHaveTheirHistory(): void
    {
        $this->generate(5);

        $this->switcher->runFor($this->tenant, function (): void {
            $record = self::service(RecordRepository::class)->findBy(self::module(), new RecordQuery(), RecordAccess::unrestricted())[0];

            self::assertCount(1, self::service(HistoryRepository::class)->findFor(self::module(), (int) $record->id));
        });
    }

    /**
     * The reason the ledger exists. Cleanup deletes what a generator made and
     * nothing else — a record somebody typed into the same module survives it.
     */
    public function testClearingRemovesOnlyWhatWasGenerated(): void
    {
        $mine = $this->switcher->runFor($this->tenant, fn (): Record => self::service(RecordWriter::class)->save(
            self::module(),
            new Record(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace']),
        ));

        $this->generate(20);
        self::assertCount(21, $this->records(50));

        self::assertSame(20, $this->purge());

        $left = $this->records(50);
        self::assertCount(1, $left);
        self::assertSame($mine->id, $left[0]->id);
    }

    /**
     * The acceptance criterion of XIV-73, and the reason it is worth writing
     * here rather than anywhere else.
     *
     * A stored record is read back with its rows and put through the engine's
     * own derivers a second time. Everything they produce has to come back
     * identical — which is the difference between a value the generator invented
     * and a value the engine worked out, said in one assertion instead of one
     * per field.
     *
     * The generator is the only caller in the system that writes *every* field
     * of *every* module, so this is where that assertion is both cheap and
     * broad: a module that grows a derived field tomorrow is covered by it
     * without anybody adding a line, and a deriver that fills only an empty
     * field — which is the shape the bug was hiding behind, since an invented
     * value silently suppresses it — cannot pass it.
     *
     * Ordering is under test here too, though it does not look like it. Totals
     * follow from the lines, so a generator that wrote the header before the
     * rows existed would store zeroes against rows that turn up afterwards, and
     * every one of those records would fail this comparison.
     *
     * **What it cannot see, and why the three tests below exist.** A stored
     * record is a fixed point of the derivers that *always recompute*; it is
     * also a fixed point of the ones that fill only an empty field, whatever is
     * in that field — which is the whole reason nonsense document numbers and
     * 1996 due dates survived in plain sight. Re-deriving them here would mean
     * emptying the field first, and a number cannot be re-derived at all without
     * taking a second one out of the counter. So they are asserted against the
     * counter and against the customer's terms instead, one test each.
     */
    public function testEveryDerivedValueIsWhatTheEngineWouldHaveProduced(): void
    {
        $this->installDocuments();
        $this->generate(12);
        $this->generate(12, module: OrderModule::KEY);
        $this->generate(12, module: InvoiceModule::KEY);

        foreach ([OrderModule::KEY, InvoiceModule::KEY] as $key) {
            $this->switcher->runFor($this->tenant, function () use ($key): void {
                $module = self::module($key);
                $records = self::service(RecordRepository::class);
                $derived = self::service(DerivedValues::class);
                $seen = 0;

                foreach ($records->findBy($module, new RecordQuery(perPage: 50), RecordAccess::unrestricted()) as $record) {
                    $rows = [];

                    foreach ($module->getCollections() as $collection) {
                        $rows[$collection->getKey()] = array_map(
                            static fn (Record $child): array => ['id' => (int) $child->id, 'data' => $child->data],
                            $records->findChildren($collection, (int) $record->id),
                        );
                    }

                    $again = $derived->of($module, $record->data, $rows);
                    self::assertNotNull($again, sprintf('%s derives nothing at all', $key));

                    self::assertSame(
                        $records->storageValues($module, $record->data),
                        $records->storageValues($module, $again->fields),
                        sprintf('%s %d: a stored field is not what the derivers make of it', $key, (int) $record->id),
                    );

                    // Compared by value and not by row, because a derived
                    // collection is rebuilt from scratch on every save and its
                    // rows carry no id until the writer matches them by position.
                    foreach ($module->getCollections() as $collection) {
                        $of = static fn (array $list): array => array_map(
                            static fn (array $row): array => $records->storageValues($collection, $row['data']),
                            $list,
                        );

                        self::assertSame(
                            $of($rows[$collection->getKey()]),
                            $of($again->rowsOf($collection->getKey())),
                            sprintf(
                                '%s %d: the stored "%s" rows are not what the derivers make of them',
                                $key,
                                (int) $record->id,
                                $collection->getKey(),
                            ),
                        );
                    }

                    ++$seen;
                }

                self::assertSame(12, $seen);
            });
        }
    }

    /**
     * What the bug looked like from the outside: orders numbered "Distinctio
     * voluptatem dolorum" because an invented value suppressed the allocation
     * (XIV-73, §5.10).
     */
    public function testGeneratedDocumentsAreNumberedFromTheModulesOwnCounter(): void
    {
        $this->installDocuments();
        $this->generate(10);
        $this->generate(25, module: OrderModule::KEY);

        $year = (new \DateTimeImmutable())->format('Y');
        $expected = [];

        for ($i = 1; $i <= 25; ++$i) {
            $expected[] = sprintf('ORD-%s-%04d', $year, $i);
        }

        $numbers = array_map(
            static fn (Record $record): mixed => $record->data['number'],
            $this->records(50, OrderModule::KEY),
        );

        // Sorted rather than taken in order, because which record got which
        // number is the writer's business and not this test's. Zero-padding is
        // what makes sorting the text sort the numbers.
        sort($numbers);

        self::assertSame($expected, $numbers);
    }

    /**
     * Generating demo data must not spend a tenant's real numbering.
     *
     * The counter is the part of the bug that could not have been cleaned up
     * afterwards: three hundred generated orders left it reading 29, so the next
     * genuine order was ORD-2026-0030 with twenty-nine records in front of it
     * carrying no number at all. Deleting the demo records would not have given
     * those numbers back.
     */
    public function testGeneratingLeavesTheCounterAtExactlyWhatWasGenerated(): void
    {
        $this->installDocuments();
        $this->generate(10);
        $this->generate(25, module: OrderModule::KEY);

        $this->switcher->runFor($this->tenant, function (): void {
            $next = self::tenantConnection()->fetchOne(
                'SELECT next_value FROM number_sequence WHERE shape_key = :shape AND field_key = :field',
                ['shape' => OrderModule::KEY, 'field' => 'number'],
            );

            // Twenty-five numbers handed out and the twenty-sixth waiting. One
            // record that failed to allocate, or one that allocated twice, shows
            // up here and nowhere else.
            self::assertSame(26, (int) $next);
        });
    }

    /**
     * A due date is worked out from what the customer agreed, on the way into
     * `sent`, and never drawn from the date sampler (XIV-67, §5.16).
     *
     * Checked against the contact's own `payment_terms` rather than against the
     * resolver, so this is arithmetic somebody can do on paper rather than the
     * engine agreeing with itself. A draft has no due date and must not acquire
     * one — nobody owes anything for a document that has not gone out.
     */
    public function testAGeneratedInvoiceFallsDueOnTheTermsItWasSentUnder(): void
    {
        $this->installDocuments();
        $this->generate(20);
        $this->generate(20, module: OrderModule::KEY);
        $this->generate(30, module: InvoiceModule::KEY);

        $checked = 0;

        foreach ($this->records(50, InvoiceModule::KEY) as $invoice) {
            $status = $invoice->data[InvoiceModule::STATUS];
            $due = self::asDate($invoice->data[InvoiceModule::DUE_DATE]);

            if (!\in_array($status, [InvoiceModule::SENT, InvoiceModule::PAID], true)) {
                self::assertNull($due, sprintf('a %s invoice was given a deadline', (string) $status));

                continue;
            }

            $terms = $this->contactOf($invoice)->data['payment_terms'];

            if ($terms === null) {
                continue;
            }

            $issued = self::asDate($invoice->data[InvoiceModule::ISSUED_ON]);
            self::assertNotNull($issued);
            self::assertNotNull($due, 'a sent invoice on agreed terms has no due date');

            self::assertSame(
                $issued->modify(sprintf('+%d days', (int) $terms))->format('Y-m-d'),
                $due->format('Y-m-d'),
            );

            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'not one generated invoice ever went out');
    }

    /**
     * **The lifecycle decision, asserted** (XIV-73, §5.17). A generated record
     * arrives at its state by being moved there, so the timeline says how it got
     * there and no record is in a state the lifecycle forbids reaching directly.
     *
     * The count is what makes this more than a smoke test: a delivered order
     * carries *two* transition entries, because there is no way from draft to
     * delivered that does not go through confirmed.
     */
    public function testARecordArrivesAtItsStateByBeingMovedThere(): void
    {
        $this->installDocuments();
        $this->generate(10);

        // **Seeded, because the two assertions at the bottom are about the
        // distribution and an unseeded draw eventually does not produce one.**
        // `draft` is one of the seven samples the module declares, so forty
        // unseeded orders leave none in the initial state about once in every
        // 476 runs — which is rare enough to look like a real failure and
        // common enough to happen. It did, on the first CI run after this test
        // landed. The seed is not here to make the numbers pretty; it is what
        // turns "usually covers both paths" into "covers both paths".
        $this->generate(40, seed: 24, module: OrderModule::KEY);

        $states = [];

        $this->switcher->runFor($this->tenant, function () use (&$states): void {
            $module = self::module(OrderModule::KEY);
            $lifecycle = self::service(Lifecycles::class)->for(OrderModule::KEY)?->lifecycle;
            self::assertNotNull($lifecycle);

            $history = self::service(HistoryRepository::class);

            foreach (self::service(RecordRepository::class)->findBy($module, new RecordQuery(perPage: 50), RecordAccess::unrestricted()) as $record) {
                $state = (string) $record->data['status'];
                $states[$state] = ($states[$state] ?? 0) + 1;

                $entries = $history->findFor($module, (int) $record->id);
                $moves = array_values(array_filter(
                    $entries,
                    static fn (HistoryEntry $entry): bool => $entry->action === RecordAction::Transitioned,
                ));

                self::assertCount(
                    \count($lifecycle->pathTo($lifecycle->initial, $state)),
                    $moves,
                    sprintf('order %d is %s without having been moved there', (int) $record->id, $state),
                );

                // The creation, and then one entry per move. Nothing else edits
                // a generated record.
                self::assertCount(\count($moves) + 1, $entries);
            }
        });

        self::assertArrayHasKey(OrderModule::DRAFT, $states, 'nothing was left where it started');
        self::assertArrayHasKey(
            OrderModule::DELIVERED,
            $states,
            'nothing was walked the whole way, so the two-step path is untested',
        );
    }

    /**
     * The same question asked of the variant field, which is sampled before the
     * others and would otherwise be the one place the rule did not reach.
     *
     * Nothing declares a derived variant field today, so the definition is bent
     * for this test rather than waited for: a derived `kind` would mean the
     * engine decides what sort of record this is, and a generator has as little
     * to say about that as it has about a total. With nothing choosing a variant
     * the variant-scoped fields are not filled either, which is §5.5 behaving
     * exactly as it does for a record whose kind has not been picked yet.
     */
    public function testAVariantFieldTheEngineOwnsIsNotSampledEither(): void
    {
        $made = $this->switcher->runFor($this->tenant, function (): array {
            $module = self::module();
            $kind = $module->getField('kind');
            self::assertNotNull($kind);
            $kind->setDerived(true);

            self::generator()->generate($module, 12);

            return self::service(RecordRepository::class)
                ->findBy($module, new RecordQuery(perPage: 50), RecordAccess::unrestricted());
        });

        self::assertCount(12, $made);

        foreach ($made as $record) {
            self::assertNull($record->data['kind'], 'the generator decided what kind of contact this is');
            self::assertNull($record->data['first_name']);
            self::assertNull($record->data['company_name']);
        }
    }

    /** Their addresses and history go with them, rather than being orphaned. */
    public function testClearingTakesCollectionsAndHistoryWithIt(): void
    {
        $this->generate(20);
        $this->purge();

        $this->switcher->runFor($this->tenant, function (): void {
            $connection = self::tenantConnection();

            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM contact_address'));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM contact_history'));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM demo_record'));
        });
    }

    private function generate(int $amount, ?int $seed = null, string $module = ContactModule::KEY): int
    {
        return $this->switcher->runFor(
            $this->tenant,
            fn (): int => self::generator()->generate(self::module($module), $amount, $seed),
        );
    }

    /**
     * Built by hand rather than taken from the container, so the batch size can
     * be one a test can afford to walk.
     */
    private static function generator(): DemoDataGenerator
    {
        return new DemoDataGenerator(
            self::tenantConnection(),
            self::service(RecordWriter::class),
            self::service(FieldSampler::class),
            self::service(DemoLedger::class),
            self::service(Lifecycles::class),
            // A small batch, so the paging is walked without writing hundreds of
            // records to prove it.
            batch: 7,
        );
    }

    /**
     * The two modules with something to derive, installed only where they are
     * wanted.
     *
     * Not in setUp: every test in this class is rolled back, so an install there
     * is four modules created and thrown away for each of twenty tests, most of
     * which are about a contact.
     */
    private function installDocuments(): void
    {
        $this->switcher->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $modules = self::service(ModuleRegistry::class);

            $installer->install($modules->get(OrderModule::KEY));
            $installer->install($modules->get(InvoiceModule::KEY));
        });
    }

    /** The customer an invoice was addressed to, for the terms it was sent under. */
    private function contactOf(Record $invoice): Record
    {
        $id = $invoice->data[InvoiceModule::CONTACT];
        self::assertIsNumeric($id);

        $contact = $this->switcher->runFor(
            $this->tenant,
            fn (): ?Record => self::service(RecordRepository::class)->find(self::module(), (int) $id),
        );

        self::assertNotNull($contact);

        return $contact;
    }

    /**
     * A stored date, however the repository handed it back. Dates come out of a
     * record as strings or as objects depending on the field type's own reading,
     * and this test is about the day rather than about which.
     */
    private static function asDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }

    private function purge(string $module = ContactModule::KEY): int
    {
        return $this->switcher->runFor(
            $this->tenant,
            fn (): int => self::service(DemoLedger::class)->purge(self::module($module)),
        );
    }

    /** @return list<Record> */
    private function records(int $perPage = 100, string $module = ContactModule::KEY): array
    {
        return $this->switcher->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)
            ->findBy(self::module($module), new RecordQuery(perPage: $perPage), RecordAccess::unrestricted()));
    }

    private static function module(string $key = ContactModule::KEY): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get($key);
    }

    /**
     * The customer's database, not the control plane.
     *
     * `Connection::class` autowires the default connection, which is the control
     * plane — the same trap the importer fell into. Named explicitly here so the
     * test cannot quietly assert against the wrong database.
     */
    private static function tenantConnection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
        \assert($connection instanceof Connection);

        return $connection;
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

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
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Metadata\ConversionPlan;
use Xivi\Core\Metadata\FieldTypeConversion;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * A field changing type on a tenant that already has records ([XIV-146], §7.2).
 *
 * **The worked example is `contact.phone` and it is not an illustration.** §5.23
 * built a phone type whose whole point is that one number has one stored value,
 * and then took the consequence honestly: a tenant that predates it keeps a
 * `text` field holding `+41 79 123 45 67`, `0791234567` and `079 123 45 67` as
 * three values for one number, because installing does not retro-fit (§6.1). The
 * question this ticket answers is how such a customer gets to the shape a new
 * installation has, and the answer has to survive their actual data: some of
 * those rows say `ask reception`.
 *
 * So the tenant here is built into exactly that state, by converting the shipped
 * `phone` field back to `text` while it is still empty, and every test below is
 * about getting it out again.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FieldTypeConversionTest extends KernelTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_convert';

    /** One Swiss mobile and the way somebody typed it into a text box. */
    private const string SPACED = '+41 79 123 45 67';

    /** Another, typed the way you would read it out at home. */
    private const string LOCAL = '079 765 43 21';

    /** And a third that happens to already be in the shape the new type stores. */
    private const string CANONICAL = '+41791112233';

    /** What a real address book has in it, and what no phone type can read. */
    private const string UNREADABLE = 'ask reception';

    private TenantSwitcher $switcher;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->switcher = self::service(TenantSwitcher::class);
        $this->tenant = $this->sharedTenant(self::SLUG, ['convert.localhost']);

        $this->switcher->runFor($this->tenant, function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            );

            // The country a national number is read against (§5.23). Without it
            // `079 765 43 21` is not a number at all, which is a true fact about
            // phone numbers and not the thing under test here.
            self::service(TenantProfileManager::class)->apply('Convert AG', '', 'CH');
        });

        // And back to the world before XIV-114: a `text` field called "phone".
        // Done through the conversion itself rather than by writing the column,
        // because it is a legal conversion over an empty field and saying so
        // here is cheaper than a fixture nobody can check.
        $this->convert('phone', 'text');
    }

    /**
     * The dry run reports what would convert and what would be refused, before
     * anything is written.
     */
    public function testTheDryRunReadsEveryValueBeforeAnythingIsWritten(): void
    {
        $this->contactsWithPhones([self::SPACED, self::LOCAL, self::CANONICAL, self::UNREADABLE]);

        $plan = $this->plan('phone', 'phone');

        self::assertSame(4, $plan->records);
        self::assertSame(3, $plan->converts);
        self::assertSame(1, $plan->refuses);
        // The canonical one converts and does not move, which is a distinction
        // the page is built to draw: a change of what a column means is not the
        // same size as a change to what is in it.
        self::assertSame(2, $plan->changes);
        self::assertSame([self::UNREADABLE => 1], $plan->refusing);
        self::assertSame(
            [self::SPACED => '+41791234567', self::LOCAL => '+41797654321'],
            $plan->rewritten,
        );

        // And nothing happened: it is a read.
        self::assertSame('text', $this->tenantField('phone')->getType());
        self::assertSame([self::SPACED, self::LOCAL, self::CANONICAL, self::UNREADABLE], $this->phones());
    }

    /** A change every row survives simply happens (§7.2). */
    public function testAChangeEveryRowSurvivesSimplyHappens(): void
    {
        $this->contactsWithPhones([self::SPACED, self::LOCAL, self::CANONICAL]);

        $done = $this->convert('phone', 'phone');

        self::assertSame(3, $done->converts);
        self::assertSame(0, $done->refuses);
        self::assertSame('phone', $this->tenantField('phone')->getType());
        self::assertSame(['+41791234567', '+41797654321', self::CANONICAL], $this->phones());
    }

    /**
     * A change any row fails is refused, with the count and the offending values
     * named (§7.2). Nothing is written, including the rows that would have been
     * fine.
     */
    public function testAChangeAnyRowFailsIsRefusedWithTheValuesNamed(): void
    {
        $this->contactsWithPhones([self::SPACED, self::UNREADABLE]);

        try {
            $this->convert('phone', 'phone');
            self::fail('A value the new type cannot read should refuse the whole change.');
        } catch (MetadataChangeRefused $refused) {
            self::assertStringContainsString('1 existing record', $refused->getMessage());
            self::assertStringContainsString(self::UNREADABLE, $refused->getMessage());
        }

        self::assertSame('text', $this->tenantField('phone')->getType());
        self::assertSame([self::SPACED, self::UNREADABLE], $this->phones());
    }

    /**
     * Emptying the failing rows is the customer's explicit second choice, and
     * never a default: the same call refuses without it and goes through with it.
     */
    public function testEmptyingTheFailingRowsIsAnExplicitSecondChoice(): void
    {
        $this->contactsWithPhones([self::SPACED, self::UNREADABLE]);

        $done = $this->convert('phone', 'phone', emptyRefused: true);

        self::assertSame(1, $done->converts);
        self::assertSame(1, $done->refuses);
        self::assertSame('phone', $this->tenantField('phone')->getType());
        // Emptied rather than left holding something the field can no longer
        // read: `null` is what "no value" is stored as (§5).
        self::assertSame(['+41791234567', null], $this->phones());
    }

    /**
     * And emptying is refused outright where the field may not be empty.
     *
     * The second choice §7.2 offers is not a choice here at all: it would leave
     * records that the next save of an unrelated field refuses, for a reason
     * nobody would connect to a conversion done last month. The way out is the
     * ordinary one, since relaxing a rule is always allowed (§5.4).
     */
    public function testEmptyingIsRefusedOnARequiredField(): void
    {
        $this->contactsWithPhones([self::SPACED, self::UNREADABLE]);

        $this->switcher->runFor($this->tenant, function (): void {
            $field = $this->field('phone');

            self::service(MetadataEditor::class)->updateField(
                field: $field,
                label: $field->getLabel(),
                required: true,
                unique: $field->isUnique(),
                filterable: $field->isFilterable(),
                listed: $field->isListed(),
                title: $field->isTitle(),
                position: $field->getPosition(),
                width: $field->getWidth(),
                section: $field->getSection(),
            );
        });

        $this->expectException(MetadataChangeRefused::class);
        $this->expectExceptionMessageMatches('/"phone" is required/');

        $this->convert('phone', 'phone', emptyRefused: true);
    }

    /**
     * Every value the run converts or empties is in the record's history, which
     * is what makes even the lossy half of this reversible by hand (§7.2, §5.2).
     */
    public function testEveryValueTheRunTouchesIsWrittenToTheRecordsHistory(): void
    {
        $ids = $this->contactsWithPhones([self::SPACED, self::CANONICAL, self::UNREADABLE]);

        $this->convert('phone', 'phone', emptyRefused: true);

        // The one that was restated.
        self::assertSame(
            [['converted', self::SPACED, '+41791234567']],
            $this->conversionsFor($ids[0]),
        );

        // The one that was emptied. The value it used to hold is the only place
        // `ask reception` still exists, which is the whole condition on which
        // §7.2 allows a lossy conversion at all.
        self::assertSame(
            [['converted', self::UNREADABLE, null]],
            $this->conversionsFor($ids[2]),
        );

        // And the one nothing happened to. A value that came out of the
        // conversion exactly as it went in is not an event (§5.2).
        self::assertSame([], $this->conversionsFor($ids[1]));
    }

    /**
     * A conversion that would breach a `unique` index is reported as such, not
     * attempted and rolled back (§7.2).
     *
     * The three spellings of one number are three distinct strings while the
     * field is text, so `unique` is perfectly happy with them today. Read as
     * phone numbers they are one value, which is exactly what the promise was
     * ticked to find out and exactly what the customer has to be told before
     * anything moves.
     */
    public function testAConversionThatWouldBreachAUniqueIndexIsReportedNotAttempted(): void
    {
        $this->contactsWithPhones([self::SPACED, '079 123 45 67', '0791234567']);
        $this->makeUnique('phone');

        $plan = $this->plan('phone', 'phone');

        self::assertTrue($plan->blocked());
        self::assertSame(['+41791234567' => 3], $plan->shared);

        try {
            $this->convert('phone', 'phone');
            self::fail('A conversion that collides on a unique field should be refused.');
        } catch (MetadataChangeRefused $refused) {
            self::assertStringContainsString('marked unique', $refused->getMessage());
            self::assertStringContainsString('+41791234567', $refused->getMessage());
        }

        // Reported rather than attempted: the column is untouched, so this is
        // not a rollback of a half-done rewrite.
        self::assertSame('text', $this->tenantField('phone')->getType());
        self::assertSame([self::SPACED, '079 123 45 67', '0791234567'], $this->phones());
    }

    /** Whether the door is one-way is said before it closes (§7.2). */
    public function testReversibilityIsStatedFromTheDataRatherThanFromTheTypes(): void
    {
        // Every value already spelled the way the new type would spell it: the
        // reverse gives every record exactly what it holds now, so the door
        // swings both ways and the page may say so.
        $this->contactsWithPhones([self::CANONICAL]);
        self::assertTrue($this->plan('phone', 'phone')->reversible);

        // One value whose spaces the conversion would take away. The number
        // survives and the spelling does not, so the same change on the same
        // pair of types is now final.
        $this->contactsWithPhones([self::SPACED]);
        self::assertFalse($this->plan('phone', 'phone')->reversible);
    }

    /**
     * A field the engine fills is refused, because its type is not the
     * customer's to restate underneath the thing filling it (§5.9, §5.10).
     */
    public function testADerivedFieldIsRefusedATypeChange(): void
    {
        $this->switcher->runFor($this->tenant, function (): void {
            self::service(MetadataEditor::class)->addField(
                shape: self::service(MetadataRepository::class)->get(ContactModule::KEY),
                key: 'reference',
                label: 'Reference',
                type: 'text',
            );
        });

        // Read again inside its own switch rather than carried across from the
        // one above: leaving a tenant drops the entity manager (§7.4), so a
        // definition held from the previous closure is detached and the flush
        // that follows would write nothing at all.
        $this->switcher->runFor($this->tenant, function (): void {
            self::service(MetadataEditor::class)->setNumbering($this->field('reference'), 'K-{number:4}');
        });

        self::assertTrue($this->tenantField('reference')->isDerived());

        $this->expectException(MetadataChangeRefused::class);
        $this->expectExceptionMessageMatches('/engine fills "reference"/');

        $this->convert('reference', 'integer');
    }

    /** The type it already has is nothing to convert, and says so. */
    public function testConvertingToTheSameTypeIsRefused(): void
    {
        $this->expectException(MetadataChangeRefused::class);
        $this->expectExceptionMessageMatches('/already a "text" field/');

        $this->convert('phone', 'text');
    }

    /**
     * The point of the whole ticket, said as one assertion: a tenant that
     * predates the phone type reaches the shape a new installation has.
     */
    public function testAnExistingTenantReachesTheShapeANewOneHas(): void
    {
        $this->contactsWithPhones([self::SPACED, self::LOCAL, self::UNREADABLE]);

        $this->convert('phone', 'phone', emptyRefused: true);

        // `phone`, which is what contact's blueprint declares for a new install
        // (§5.23) and what this tenant now has. Written out rather than read off
        // the blueprint, because a test that compares two things the same code
        // produced would pass even if both were wrong.
        self::assertSame('phone', $this->tenantField('phone')->getType());
        self::assertSame(['+41791234567', '+41797654321', null], $this->phones());
    }

    /**
     * §5.4's other refusals are untouched: a module's own field is still not
     * removable, and this one is a module's own field.
     *
     * Worth an assertion of its own because the conversion converts one, which
     * is the first time anything in the editor has changed a `system` field.
     * "Its type may move" and "it may be taken away" are different questions,
     * and only the first one changed.
     */
    public function testAModulesOwnFieldConvertsAndIsStillNotRemovable(): void
    {
        self::assertTrue($this->tenantField('phone')->isSystem());

        $this->convert('phone', 'phone');

        $this->expectException(MetadataChangeRefused::class);
        $this->expectExceptionMessageMatches('/came with the module/');

        $this->switcher->runFor($this->tenant, function (): void {
            self::service(MetadataEditor::class)->removeField($this->field('phone'));
        });
    }

    /**
     * A field of a collection converts too, and its entry goes on the parent's
     * timeline (§5.2).
     *
     * The contact is what changed, because a contact's addresses are part of
     * what the contact *is* rather than separate things with separate lives
     * (§5.1). So the row is named inside the entry rather than given one of its
     * own, which is the shape any other save of that address would have
     * produced.
     */
    public function testACollectionsFieldConvertsOntoItsParentsTimeline(): void
    {
        // A postcode somebody typed with a leading zero, so that reading it as a
        // number really does change what is stored, and one that is not a
        // number at all.
        $contact = $this->contactWithAddresses(['08001', 'CH-8001']);

        $plan = $this->switcher->runFor($this->tenant, fn (): ConversionPlan => self::service(FieldTypeConversion::class)
            ->plan($this->addressField('postal_code'), 'integer'));

        self::assertSame(1, $plan->converts);
        self::assertSame(1, $plan->refuses);
        self::assertSame(['CH-8001' => 1], $plan->refusing);

        $this->switcher->runFor($this->tenant, function (): void {
            self::service(FieldTypeConversion::class)
                ->convert($this->addressField('postal_code'), 'integer', emptyRefused: true);
        });

        $rows = $this->switcher->runFor($this->tenant, function () use ($contact): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $entries = self::service(HistoryRepository::class)->findFor($module, $contact);

            foreach ($entries as $entry) {
                if ($entry->action->value === 'converted') {
                    return $entry->collectionChanges()['addresses'] ?? [];
                }
            }

            return [];
        });

        // One entry naming both rows: the one that was restated and the one
        // that was emptied, each with the value it used to hold.
        self::assertCount(2, $rows);
        self::assertSame(['08001', 'CH-8001'], array_map(
            static fn (array $row): mixed => $row['changes']['postal_code']['from'],
            $rows,
        ));
        self::assertSame(['8001', null], array_map(
            static fn (array $row): mixed => $row['changes']['postal_code']['to'],
            $rows,
        ));
    }

    /**
     * One contact carrying an address per postcode.
     *
     * @param list<string> $postcodes
     *
     * @return int the contact's id, which is whose timeline the conversion writes to
     */
    private function contactWithAddresses(array $postcodes): int
    {
        return $this->switcher->runFor($this->tenant, function () use ($postcodes): int {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            $rows = array_map(static fn (string $postcode): array => [
                'id' => null,
                // `street` is required on this collection, so every row here is
                // one the module would have accepted anyway.
                'data' => ['street' => 'Bahnhofstrasse 1', 'postal_code' => $postcode],
            ], $postcodes);

            return (int) self::service(RecordWriter::class)->save(
                $module,
                new Record(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Address']),
                ['addresses' => $rows],
            )->id;
        });
    }

    /** A field of the addresses collection, read inside the tenant. */
    private function addressField(string $key): FieldDefinition
    {
        $collection = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getCollection('addresses');
        \assert($collection !== null);

        $field = $collection->getField($key);
        \assert($field !== null);

        return $field;
    }

    /**
     * @param list<string> $numbers
     *
     * @return list<int> the record ids, in the order the numbers were given
     */
    private function contactsWithPhones(array $numbers): array
    {
        $ids = [];

        foreach ($numbers as $index => $number) {
            $ids[] = (int) $this->switcher->runFor($this->tenant, fn (): Record => self::service(RecordWriter::class)->save(
                self::service(MetadataRepository::class)->get(ContactModule::KEY),
                new Record([
                    'kind' => ContactModule::PERSON,
                    'first_name' => 'Contact',
                    'last_name' => sprintf('Number %d', $index),
                    'phone' => $number,
                ]),
            ))->id;
        }

        return $ids;
    }

    private function plan(string $key, string $to): ConversionPlan
    {
        return $this->switcher->runFor($this->tenant, fn (): ConversionPlan => self::service(FieldTypeConversion::class)
            ->plan($this->field($key), $to));
    }

    private function convert(string $key, string $to, bool $emptyRefused = false): ConversionPlan
    {
        return $this->switcher->runFor($this->tenant, fn (): ConversionPlan => self::service(FieldTypeConversion::class)
            ->convert($this->field($key), $to, $emptyRefused));
    }

    private function makeUnique(string $key): void
    {
        $this->switcher->runFor($this->tenant, function () use ($key): void {
            self::service(MetadataEditor::class)->makeUnique($this->field($key));
        });
    }

    /**
     * The field as the tenant's own definitions have it, read fresh every time.
     *
     * Fresh because the conversion clears the definition cache and hands back a
     * different object afterwards (XIV-53), and a test holding the old one would
     * be asserting against a definition nothing reads.
     */
    private function field(string $key): FieldDefinition
    {
        $field = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField($key);
        \assert($field !== null);

        return $field;
    }

    /**
     * The same, from outside a tenant context.
     *
     * Two methods rather than one that switches on its own, because
     * {@see TenantSwitcher::runFor()} drops the connection when it leaves and
     * nesting one inside another would take the outer one's connection with it.
     * The rule this leaves is simple: {@see self::field()} inside a closure,
     * this one outside.
     */
    private function tenantField(string $key): FieldDefinition
    {
        return $this->switcher->runFor($this->tenant, fn (): FieldDefinition => $this->field($key));
    }

    /**
     * What the column holds, in the order the records were made.
     *
     * @return list<string|null>
     */
    private function phones(): array
    {
        return $this->switcher->runFor($this->tenant, function (): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $records = self::service(RecordRepository::class)->findAll($module, limit: 100);

            usort($records, static fn (Record $a, Record $b): int => (int) $a->id <=> (int) $b->id);

            return array_map(static fn (Record $record): ?string => $record->get('phone'), $records);
        });
    }

    /**
     * The conversion entries on one record's timeline, as
     * [verb, what it was, what it became].
     *
     * @return list<array{0: string, 1: mixed, 2: mixed}>
     */
    private function conversionsFor(int $recordId): array
    {
        return $this->switcher->runFor($this->tenant, function () use ($recordId): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $entries = self::service(HistoryRepository::class)->findFor($module, $recordId);

            $conversions = [];

            foreach ($entries as $entry) {
                if ($entry->action->value !== 'converted') {
                    continue;
                }

                $conversions[] = [
                    $entry->action->value,
                    $entry->fieldChanges()['phone']['from'] ?? null,
                    $entry->fieldChanges()['phone']['to'] ?? null,
                ];
            }

            return $conversions;
        });
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

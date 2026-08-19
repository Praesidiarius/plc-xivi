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
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tenant\Security\UserManager;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Contact\ContactModule;
use Xivi\Core\Demo\DemoDataGenerator;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\DateRangeFieldType;
use Xivi\Core\Field\Type\DateTimeRangeFieldType;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Period\ExclusiveWithin;
use Xivi\Core\Period\Period;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\QueryCompiler;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\OverlapExclusion;
use Xivi\Core\Record\OverlappingPeriod;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * A period as one value, and two of them that cannot overlap (XIV-136).
 *
 * ### What this class is for, and what it deliberately leaves to another one
 *
 * The claim this ticket makes is not that a field type can format two dates. It
 * is that **the database refuses an overlap**, that what a period is exclusive
 * within is the customer's to decide, and that the boundary day comes out the way
 * the bound says it does. So almost every test here writes through
 * {@see RecordWriter} — which validates nothing — and lets Postgres answer.
 *
 * The *race* is proved next door, in {@see PeriodOverlapRaceTest}, because two
 * connections cannot be arranged inside DAMA's transaction. This class proves
 * everything the constraint says when there is only one writer; that one proves
 * there is no gap between two.
 *
 * ### The shape being built, and why a care home
 *
 * The ticket came from reading a care home built on the previous engine, and the
 * question was never whether Xivi should ship one — it is whether Xivi can
 * *express* one. So the setup does what a customer would do with no code at all:
 * a `room` field beside a `stay`, marked exclusive within the room, in the
 * metadata editor. Nothing here is a module, and that is the point.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PeriodFieldTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_period';
    private const string HOST = 'period.localhost';
    private const string EMAIL = 'period@example.test';
    private const string PASSWORD = 'period-password';

    /** The resource a stay is exclusive within: a care home's room, a hotel's. */
    private const string ROOM = 'room';

    /** The period itself, in days. */
    private const string STAY = 'stay';

    /** And in moments — the meeting room, the bath, the physiotherapist. */
    private const string SLOT = 'slot';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_HOST', self::HOST);

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            );

            $editor = self::service(MetadataEditor::class);
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            $editor->addField(shape: $module, key: self::ROOM, label: 'Room', type: 'text', filterable: true);
            $editor->addField(
                shape: $module,
                key: self::STAY,
                label: 'Stay',
                type: DateRangeFieldType::KEY,
                filterable: true,
                options: [ExclusiveWithin::OPTION => self::ROOM],
            );
            $editor->addField(
                shape: $module,
                key: self::SLOT,
                label: 'Slot',
                type: DateTimeRangeFieldType::KEY,
                options: [ExclusiveWithin::OPTION => self::ROOM],
            );
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Period', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /** The record form is a page behind a firewall, and the component behind the same one. */
    private function signIn(): void
    {
        $page = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
        $this->client->submit($page->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
    }

    // -- one value ----------------------------------------------------------

    /**
     * **A record holds a period as one value, and the engine knows it is one.**.
     *
     * Two assertions rather than one, and they are the two halves of that
     * sentence. What is *stored* is a single JSONB key holding one ISO-8601
     * interval — not two keys the application would have to remember belong
     * together — and what comes *back* is a {@see Period}, so everything above
     * storage handles a period rather than a pair of strings it has to pair up
     * again.
     */
    public function testARecordHoldsAPeriodAsOneValue(): void
    {
        $record = $this->write(['first_name' => 'Ada', 'room' => '3', 'stay' => ['from' => '2026-08-01', 'until' => '2026-08-05']]);

        self::assertSame('2026-08-01/2026-08-05', $this->stored((int) $record->id, self::STAY), 'one key, one value');

        $read = $this->read((int) $record->id)->get(self::STAY);

        self::assertInstanceOf(Period::class, $read);
        self::assertSame('2026-08-01', $read->from?->format('Y-m-d'));
        self::assertSame('2026-08-05', $read->until?->format('Y-m-d'));
    }

    // -- the database refuses -----------------------------------------------

    /**
     * **The whole ticket, with no PHP anywhere in the refusal.**.
     *
     * {@see RecordWriter} runs no validator — that is what the form does — so
     * nothing in this process has looked at the other booking. The second save
     * reaches Postgres, meets the exclusion constraint, and comes back as
     * {@see OverlappingPeriod} naming the field.
     *
     * The last assertion is the one that would catch a fix that "worked" by
     * checking first: nothing was written.
     */
    public function testTheDatabaseRefusesTwoOverlappingStaysInOneRoom(): void
    {
        $this->write(['first_name' => 'Ada', 'room' => '3', 'stay' => $this->days('2026-08-01', '2026-08-05')]);

        try {
            $this->write(['first_name' => 'Grace', 'room' => '3', 'stay' => $this->days('2026-08-04', '2026-08-06')]);

            self::fail('two overlapping stays were written for one room');
        } catch (OverlappingPeriod $refused) {
            self::assertSame(self::STAY, $refused->fieldKey, 'and it says which field it was about');
            self::assertStringContainsString(
                'Stay',
                $refused->translatable()->trans(self::service(TranslatorInterface::class), 'en'),
                'in a sentence somebody can read',
            );
        }

        self::assertSame(1, $this->staysIn('3'), 'and nothing was written');
    }

    /** Two rooms are two resources, and a hotel with one bookable room is not a hotel. */
    public function testTwoRoomsMayHoldTheSameDays(): void
    {
        $this->write(['first_name' => 'Ada', 'room' => '3', 'stay' => $this->days('2026-08-01', '2026-08-05')]);
        $this->write(['first_name' => 'Grace', 'room' => '4', 'stay' => $this->days('2026-08-01', '2026-08-05')]);

        self::assertSame(1, $this->staysIn('3'));
        self::assertSame(1, $this->staysIn('4'));
    }

    /**
     * A cancelled booking does not go on holding the room.
     *
     * Records are soft-deleted and keep their values (§5), so without the
     * `deleted_at IS NULL` half of the constraint's predicate a cancellation
     * would reserve a room for ever, discoverable only by hitting it — the same
     * rule and the same argument [XIV-109] wrote into its index.
     */
    public function testACancelledStayDoesNotHoldTheRoom(): void
    {
        $booked = $this->write(['first_name' => 'Ada', 'room' => '3', 'stay' => $this->days('2026-08-01', '2026-08-05')]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($booked): void {
            self::service(RecordWriter::class)->delete($this->module(), $booked);
        });

        $this->write(['first_name' => 'Grace', 'room' => '3', 'stay' => $this->days('2026-08-01', '2026-08-05')]);

        self::assertSame(1, $this->staysIn('3'), 'the live one');
    }

    /** A record with no room chosen yet is a draft, and drafts occupy nothing. */
    public function testAStayWithNoRoomConflictsWithNothing(): void
    {
        $this->write(['first_name' => 'Ada', 'stay' => $this->days('2026-08-01', '2026-08-05')]);
        $this->write(['first_name' => 'Grace', 'stay' => $this->days('2026-08-01', '2026-08-05')]);

        self::assertSame(2, $this->countWhere("data->>'stay' IS NOT NULL AND data->>'room' IS NULL"));
    }

    // -- the boundary day ---------------------------------------------------

    /**
     * **The 5th, in both directions.**.
     *
     * The bound is `[from, until)` — `until` is the first day *outside* the
     * period ({@see Period}) — and this is the day that decides whether that is
     * true. A stay ending on the 5th and one starting on the 5th are adjacent and
     * both may exist; a stay ending on the 6th and one starting on the 5th share
     * the 5th and cannot.
     *
     * One test rather than two, because half of this passing is not a weaker
     * result but a different bug: an inclusive end would pass the second
     * assertion and fail the first, and a bound applied to only one side of the
     * comparison would do the opposite.
     *
     * The messages name the bound rather than the expectation, so that a failure
     * says what rule was being asserted rather than which line broke.
     */
    public function testTheBoundaryDayIsWhereOneStayEndsAndTheNextBegins(): void
    {
        $this->write(['first_name' => 'Ada', 'room' => '3', 'stay' => $this->days('2026-08-01', '2026-08-05')]);

        try {
            $this->write(['first_name' => 'Grace', 'room' => '3', 'stay' => $this->days('2026-08-05', '2026-08-09')]);
        } catch (OverlappingPeriod) {
            self::fail(
                'the end bound is exclusive: a stay until the 5th leaves the room free ON the 5th, '
                . 'so a stay starting on the 5th must not be an overlap',
            );
        }

        self::assertSame(2, $this->staysIn('3'));

        try {
            $this->write(['first_name' => 'Barbara', 'room' => '3', 'stay' => $this->days('2026-08-04', '2026-08-06')]);

            self::fail(
                'the end bound is exclusive but the start bound is not: a stay from the 4th to the 6th '
                . 'holds the 4th, which the stay until the 5th also holds, so it is an overlap',
            );
        } catch (OverlappingPeriod) {
            // What was wanted.
        }

        self::assertSame(2, $this->staysIn('3'));
    }

    /**
     * The same day, at the other precision, where it is an hour rather than a
     * day.
     *
     * A meeting 09:00–11:00 and one 11:00–12:00 are the datetime version of the
     * 5th, and they are also the case a `date_range` gets *wrong*: both are the
     * same Tuesday, so a period over days would call them a collision. That is
     * what {@see \Xivi\Core\Period\PeriodPrecision} exists to tell apart, and it
     * is why "what overlap means" is a different sentence for each.
     */
    public function testTheBoundaryMomentIsWhereOneSlotEndsAndTheNextBegins(): void
    {
        $this->write(['first_name' => 'Ada', 'room' => '9', 'slot' => $this->moments('2026-08-04T09:00:00Z', '2026-08-04T11:00:00Z')]);

        try {
            $this->write(['first_name' => 'Grace', 'room' => '9', 'slot' => $this->moments('2026-08-04T11:00:00Z', '2026-08-04T12:00:00Z')]);
        } catch (OverlappingPeriod) {
            self::fail(
                'the end bound is exclusive at this precision too: a slot until 11:00 leaves the room free '
                . 'AT 11:00, so a slot starting at 11:00 must not be an overlap — and both are the same day, '
                . 'which is what a period over days would have called a collision',
            );
        }

        self::assertSame(2, $this->countWhere("data->>'room' = '9' AND data->>'slot' IS NOT NULL"));

        try {
            $this->write(['first_name' => 'Barbara', 'room' => '9', 'slot' => $this->moments('2026-08-04T10:30:00Z', '2026-08-04T11:30:00Z')]);

            self::fail('a slot that starts inside another one was accepted');
        } catch (OverlappingPeriod) {
            // What was wanted.
        }
    }

    // -- open ended ---------------------------------------------------------

    /**
     * A tenancy with no agreed end, and what it does to everything after it.
     *
     * `[from, ∞)` is a real value rather than an empty box, and the consequence
     * is exactly what a care home would expect: while somebody lives in room 7,
     * nobody else is booked into it — not next week, and not in 2031.
     */
    public function testAnOpenEndedStayHoldsTheRoomForever(): void
    {
        $resident = $this->write([
            'first_name' => 'Ada',
            'room' => '7',
            'stay' => ['from' => '2026-08-01', 'until' => null, 'open_ended' => true],
        ]);

        self::assertSame('2026-08-01/..', $this->stored((int) $resident->id, self::STAY), 'a value, not a blank');
        self::assertTrue($this->read((int) $resident->id)->get(self::STAY)?->isOpenEnded());

        try {
            $this->write(['first_name' => 'Grace', 'room' => '7', 'stay' => $this->days('2031-01-01', '2031-02-01')]);

            self::fail('a room with an open-ended stay took a second booking five years later');
        } catch (OverlappingPeriod) {
            // What was wanted.
        }
    }

    /**
     * **An empty end box nobody ticked is refused**, and the same box ticked is
     * saved.
     *
     * The acceptance criterion that open-endedness is *visibly deliberate*. Both
     * halves go through the real record form, because this is the one rule that
     * is entirely about what a person did with a control: the engine's stored
     * value cannot tell "runs for ever" from "I forgot", so the form's third
     * control is what makes them different values before they get that far
     * ({@see \Xivi\Core\Form\PeriodType}).
     */
    public function testAnEndDateLeftBlankHasToBeDeliberate(): void
    {
        $refused = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::PERSON,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'room' => '11',
            'stay' => ['from' => '2026-08-01', 'until' => '', 'open_ended' => false],
        ], variant: ContactModule::PERSON);

        self::assertFalse($refused->isRedirect(), 'a blank end with nothing ticked is not a saved record');
        self::assertStringContainsString('no end date', (string) $refused->getContent());

        $saved = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::PERSON,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'room' => '11',
            'stay' => ['from' => '2026-08-01', 'until' => '', 'open_ended' => '1'],
        ], variant: ContactModule::PERSON);

        self::assertTrue($saved->isRedirect(), 'and the same form with the box ticked saves');
        self::assertSame(['2026-08-01/..'], $this->storedStays());
    }

    /** And a period that ends before it starts is refused where the bound is explained. */
    public function testAPeriodThatEndsBeforeItStartsIsRefused(): void
    {
        $response = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::PERSON,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'stay' => ['from' => '2026-08-05', 'until' => '2026-08-01', 'open_ended' => false],
        ], variant: ContactModule::PERSON);

        self::assertFalse($response->isRedirect());
        self::assertStringContainsString('ends before it starts', (string) $response->getContent());
        self::assertSame([], $this->storedStays());
    }

    // -- filtering ----------------------------------------------------------

    /**
     * **"Which of these overlap today", answered in the query.**.
     *
     * The assertion that this is not PHP doing the work is the arrangement rather
     * than the result: sixty records, a page size of twenty-five, and the three
     * that overlap the day in question are the *oldest* — so they sort last and
     * are nowhere near the first page. A filter applied after loading a page
     * would come back with nothing at all.
     *
     * The SQL is asserted as well, because a test that only checks the rows would
     * also pass if somebody quietly loaded the table into memory: what has to be
     * true is that the overlap is a predicate.
     */
    public function testTheFilterAnswersWhichOverlapTodayWithoutLoadingEveryRecord(): void
    {
        // The three that matter, written first so they carry the lowest ids —
        // and the list sorts by id descending, which puts them on page three.
        foreach (['3', '4', '5'] as $room) {
            $this->write(['first_name' => 'Resident ' . $room, 'room' => $room, 'stay' => $this->days('2026-08-01', '2026-08-20')]);
        }

        for ($i = 0; $i < 57; ++$i) {
            $this->write([
                'first_name' => 'Later ' . $i,
                'room' => 'x' . $i,
                'stay' => $this->days('2027-01-01', '2027-01-05'),
            ]);
        }

        $query = new RecordQuery([new Filter(self::STAY, Operator::Overlaps, '2026-08-10')], perPage: 25);

        $found = self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)
            ->findBy($this->module(), $query, RecordAccess::unrestricted()));

        self::assertCount(3, $found, 'the three overlapping stays, from a page of records they are not on');
        self::assertSame(
            ['Resident 5', 'Resident 4', 'Resident 3'],
            array_map(static fn (Record $record): string => (string) $record->get('first_name'), $found),
        );

        $compiled = self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(QueryCompiler::class)
            ->compile($this->module(), $query, RecordAccess::unrestricted()));

        self::assertStringContainsString('&&', $compiled->where, 'the overlap is a predicate, not a loop');
        self::assertStringContainsString('xivi_date_range', $compiled->where, 'over the same expression the constraint indexes');
    }

    /** And a day nothing covers finds nothing, which is the half that shows it is really filtering. */
    public function testADayNoStayCoversFindsNothing(): void
    {
        $this->write(['first_name' => 'Ada', 'room' => '3', 'stay' => $this->days('2026-08-01', '2026-08-05')]);

        self::assertSame([], $this->overlapping('2026-09-01'));
        self::assertSame(['Ada'], $this->overlapping('2026-08-04'));
        self::assertSame(
            [],
            $this->overlapping('2026-08-05'),
            'the boundary day again, through the filter this time: the room is free on the 5th',
        );
    }

    // -- the editor ---------------------------------------------------------

    /** A scope that names nothing is a rule that would enforce nothing, so it is refused. */
    public function testTheEditorRefusesAScopeThatIsNotAField(): void
    {
        $this->expectException(MetadataChangeRefused::class);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $field = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField(self::STAY);
            self::assertNotNull($field);

            self::service(MetadataEditor::class)->updateField(
                field: $field,
                label: 'Stay',
                required: false,
                unique: false,
                filterable: true,
                listed: true,
                title: false,
                position: $field->getPosition(),
                options: [ExclusiveWithin::OPTION => 'wing'],
            );
        });
    }

    /**
     * Records that already overlap are named rather than met as a driver
     * exception.
     *
     * The courtesy [XIV-109] pays for duplicates, one feature along: the rule
     * cannot be switched on over records that break it, so the refusal hands over
     * the pairs to go and look at.
     */
    public function testTheEditorRefusesToMakeAPeriodExclusiveOverRecordsThatAlreadyOverlap(): void
    {
        $free = $this->addSecondPeriodField();

        $first = $this->write(['first_name' => 'Ada', 'room' => '3', 'holiday' => $this->days('2026-08-01', '2026-08-05')]);
        $second = $this->write(['first_name' => 'Grace', 'room' => '3', 'holiday' => $this->days('2026-08-04', '2026-08-06')]);

        try {
            $this->setScope($free, self::ROOM);

            self::fail('a constraint was built over records that break it');
        } catch (MetadataChangeRefused $refused) {
            $said = $refused->getMessage();

            self::assertStringContainsString(sprintf('#%d/#%d', $first->id, $second->id), $said, 'the pair to go and fix');
            self::assertStringContainsString('"3"', $said, 'and the room they collide in');
        }

        self::assertNull($this->constraintOn('holiday'), 'and no constraint was left behind');
    }

    /** And the same change over records that do not overlap builds the constraint. */
    public function testAPeriodBecomesExclusiveWhenTheRecordsAllow(): void
    {
        $free = $this->addSecondPeriodField();

        $this->write(['first_name' => 'Ada', 'room' => '3', 'holiday' => $this->days('2026-08-01', '2026-08-05')]);
        $this->write(['first_name' => 'Grace', 'room' => '3', 'holiday' => $this->days('2026-08-05', '2026-08-09')]);

        $this->setScope($free, self::ROOM);

        $definition = $this->constraintOn('holiday');

        self::assertIsString($definition, 'the constraint exists under the name the engine computes');
        self::assertStringContainsString('EXCLUDE USING gist', $definition);
        self::assertStringContainsString('deleted_at IS NULL', $definition);

        try {
            $this->write(['first_name' => 'Barbara', 'room' => '3', 'holiday' => $this->days('2026-08-02', '2026-08-03')]);

            self::fail('the constraint was created and enforces nothing');
        } catch (OverlappingPeriod) {
            // What was wanted.
        }
    }

    /** Clearing the scope takes the rule away, and the records stay exactly as they are. */
    public function testClearingTheScopeTakesTheRuleAwayAndKeepsTheValues(): void
    {
        $this->write(['first_name' => 'Ada', 'room' => '3', 'stay' => $this->days('2026-08-01', '2026-08-05')]);

        $field = $this->module()->getField(self::STAY);
        self::assertNotNull($field);
        $this->setScope($field, null);

        self::assertNull($this->constraintOn(self::STAY));

        $this->write(['first_name' => 'Grace', 'room' => '3', 'stay' => $this->days('2026-08-04', '2026-08-06')]);

        self::assertSame(2, $this->staysIn('3'), 'overlaps are allowed again, and nothing lost its value');
    }

    // -- what must not have changed -----------------------------------------

    /**
     * **Existing `date` fields are untouched.**.
     *
     * The acceptance criterion that is about everything this ticket did *not* do.
     * Contact's `birthday` predates all of it: it still stores an ISO day, still
     * filters by equality as text, and — the part worth asserting rather than
     * assuming — has no constraint and no index of its own, because a period's
     * machinery is reached through the field's own type and its own option and
     * nothing else.
     */
    public function testAnOrdinaryDateFieldIsExactlyAsItWas(): void
    {
        $record = $this->write(['first_name' => 'Ada', 'birthday' => '1815-12-10']);

        self::assertSame('1815-12-10', $this->stored((int) $record->id, 'birthday'), 'still a plain ISO day');
        self::assertNull($this->constraintOn('birthday'), 'and nothing was built over it');

        $compiled = self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(QueryCompiler::class)
            ->compile(
                $this->module(),
                new RecordQuery([new Filter('birthday', Operator::Equals, '1815-12-10')]),
                RecordAccess::unrestricted(),
            ));

        self::assertStringNotContainsString('xivi_date_range', $compiled->where, 'a date is not a period');
        self::assertStringNotContainsString('::date', $compiled->where, 'and is still compared as text');
    }

    // -- the reader's zone --------------------------------------------------

    /**
     * **A period renders in the reader's timezone, through [XIV-83]'s own chain.**.
     *
     * A slot stored `22:00Z–23:30Z` is Tuesday night in Greenwich and Wednesday
     * morning in Zurich, so the zone decides not only the clock but the **day it
     * is filed under** — which is exactly where §8.4.4 says this goes wrong when
     * it goes wrong.
     *
     * Asserted through the record page rather than by calling `display()`,
     * because the claim is about the chain and not about the method: the page is
     * where a real reader's user, a real request and a real
     * {@see \Xivi\Core\Time\ReaderTimezone} meet. The person reading has chosen
     * Zurich; nothing about what is stored changed, and the same record read by
     * somebody in UTC still says 22:00.
     */
    public function testADatetimePeriodRendersInTheReadersZone(): void
    {
        $record = $this->write([
            'first_name' => 'Ada',
            'room' => '21',
            'slot' => $this->moments('2026-08-04T22:00:00Z', '2026-08-04T23:30:00Z'),
        ]);

        self::assertSame(
            '2026-08-04T22:00:00Z/2026-08-04T23:30:00Z',
            $this->stored((int) $record->id, self::SLOT),
            'stored in UTC, whoever is reading',
        );

        // The suite reads in en-US, so the clock is that locale's — which is the
        // other half of what `display()` does and is [XIV-50]'s chain rather than
        // this ticket's. What matters here is the *instant* it names.
        self::assertStringContainsString('8/4/2026, 10:00 PM – 11:30 PM', $this->shown((int) $record->id));

        $this->readingFrom('Europe/Zurich');

        self::assertStringContainsString(
            '8/5/2026, 12:00 AM – 1:30 AM',
            $this->shown((int) $record->id),
            'the reader in Zurich sees the next day, and the same instant — and the date is written '
            . 'once, because in that zone both ends are on it',
        );
    }

    // -- demo data ----------------------------------------------------------

    /**
     * **A generated tenant builds, with an exclusive period field in the module.**.
     *
     * The hazard that has bitten two tickets this week: the demo generator writes
     * in batches inside a transaction, so one pair of overlapping periods takes
     * the whole batch — and `tenant:reset` destroys before it builds, so a
     * failure part way costs the tenant. The type's `sample()` places each record
     * in its own week off the sequence for exactly this reason, and this is what
     * says so.
     *
     * Thirty records is enough: the generator draws rooms from a small alphabet,
     * so at thirty every room has several stays and a sampler that ignored the
     * sequence would have collided many times over.
     */
    public function testGeneratedDemoDataDoesNotCollideWithItsOwnConstraint(): void
    {
        $written = self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): int => self::service(DemoDataGenerator::class)
            ->generate($this->module(), 30));

        self::assertSame(30, $written);

        $stays = $this->storedStays();

        self::assertNotSame([], $stays, 'and the field was actually filled in');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): Record => self::service(RecordWriter::class)
            ->save($this->module(), new Record([
                'kind' => ContactModule::PERSON,
                'last_name' => 'Lovelace',
                ...$data,
            ])));
    }

    private function read(int $id): Record
    {
        $record = self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): ?Record => self::service(RecordRepository::class)
            ->find($this->module(), $id));

        self::assertInstanceOf(Record::class, $record);

        return $record;
    }

    /** @return array{from: string, until: string} */
    private function days(string $from, string $until): array
    {
        return ['from' => $from, 'until' => $until];
    }

    /** @return array{from: string, until: string} */
    private function moments(string $from, string $until): array
    {
        return ['from' => $from, 'until' => $until];
    }

    private function module(): ModuleDefinition
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): ModuleDefinition => self::service(MetadataRepository::class)
            ->get(ContactModule::KEY));
    }

    /** A second period field with no scope, for the tests that switch one on. */
    private function addSecondPeriodField(): FieldDefinition
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): FieldDefinition => self::service(MetadataEditor::class)
            ->addField(
                shape: self::service(MetadataRepository::class)->get(ContactModule::KEY),
                key: 'holiday',
                label: 'Holiday',
                type: DateRangeFieldType::KEY,
            ));
    }

    private function setScope(FieldDefinition $field, ?string $scope): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($field, $scope): void {
            $current = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField($field->getKey());
            self::assertNotNull($current);

            self::service(MetadataEditor::class)->updateField(
                field: $current,
                label: $current->getLabel(),
                required: false,
                unique: false,
                filterable: $current->isFilterable(),
                listed: true,
                title: false,
                position: $current->getPosition(),
                options: [ExclusiveWithin::OPTION => $scope],
            );
        });
    }

    /** @return list<string> */
    private function overlapping(string $day): array
    {
        $found = self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)
            ->findBy(
                $this->module(),
                new RecordQuery([new Filter(self::STAY, Operator::Overlaps, $day)]),
                RecordAccess::unrestricted(),
            ));

        return array_map(static fn (Record $record): string => (string) $record->get('first_name'), $found);
    }

    /**
     * The record's own page, as the signed-in reader sees it.
     *
     * The narrow no-break spaces ICU puts before `PM` and inside a clock are
     * flattened to ordinary ones, so that an assertion can be written the way the
     * page reads. They are correct typography and would otherwise make every
     * expected string in this class a puzzle.
     */
    private function shown(int $id): string
    {
        $this->client->request('GET', sprintf('https://%s/m/%s/%d', self::HOST, ContactModule::KEY, $id));

        return str_replace(["\u{202F}", "\u{00A0}"], ' ', (string) $this->client->getResponse()->getContent());
    }

    /** Give the signed-in reader a timezone of their own, the way the account page does. */
    private function readingFrom(string $zone): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($zone): void {
            $user = self::service(UserRepository::class)->findOneByEmail(self::EMAIL);
            \assert($user !== null);

            self::service(UserManager::class)->setTimezone($user, $zone);
        });
    }

    private function stored(int $id, string $key): ?string
    {
        $value = self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => $this->connection()->fetchOne(
            sprintf('SELECT data->>%s FROM contact WHERE id = :id', $this->connection()->quote($key)),
            ['id' => $id],
        ));

        return \is_string($value) ? $value : null;
    }

    /** @return list<string> */
    private function storedStays(): array
    {
        /** @var list<string> $stays */
        $stays = self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): array => $this->connection()->fetchFirstColumn(
            "SELECT data->>'stay' FROM contact WHERE deleted_at IS NULL AND data->>'stay' IS NOT NULL ORDER BY id",
        ));

        return $stays;
    }

    private function staysIn(string $room): int
    {
        return $this->countWhere(sprintf("data->>'room' = %s AND data->>'stay' IS NOT NULL", $this->quote($room)));
    }

    private function countWhere(string $condition): int
    {
        return (int) self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => $this->connection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM contact WHERE deleted_at IS NULL AND %s', $condition),
        ));
    }

    /** The constraint the engine would have created for a field, as Postgres reports it. */
    private function constraintOn(string $key): ?string
    {
        $name = OverlapExclusion::nameFor('contact', $key);

        $definition = self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => $this->connection()->fetchOne(
            'SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = :name',
            ['name' => $name],
        ));

        return \is_string($definition) ? $definition : null;
    }

    private function quote(string $value): string
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): string => $this->connection()->quote($value));
    }

    private function connection(): Connection
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

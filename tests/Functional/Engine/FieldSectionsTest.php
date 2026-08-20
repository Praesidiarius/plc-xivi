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
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\Section;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * Fields grouped under headings, so a form of twenty-five is readable
 * (XIV-119).
 *
 * Everything here goes through the editor's **own requests** rather than
 * through {@see \Xivi\Core\Metadata\MetadataEditor}, which is XIV-144's lesson
 * kept as a habit: a protection that reads correctly in the service can be
 * unreachable from the path a customer actually walks, and the editor's own
 * refusals are the easy half to get wrong.
 *
 * What is being proved, in the order the acceptance criteria ask it:
 *
 *  * a section can be created, named and ordered;
 *  * a field can be put in one, and a field in none is drawn exactly as before;
 *  * a definition that predates all of this is untouched, and no migration
 *    rewrote it;
 *  * the form and the record page agree about the grouping, through real
 *    requests to both;
 *  * a section is presentation only — the stored payload of a record saved with
 *    sections is identical to one saved without, and a filter behaves the same;
 *  * deleting a section deletes no field, and says so before it happens.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FieldSectionsTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_field_sections';
    private const string HOST = 'fieldsections.localhost';
    private const string ADMIN = 'admin@fieldsections.test';
    /** Whose session a record is saved under (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string PASSWORD = 'sections-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $tenant = $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($tenant, function (): void {
            self::service(ModuleInstaller::class)->install(self::service(ModuleRegistry::class)->get(ContactModule::KEY));
        });

        self::service(UserCreator::class)->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- creating, naming and ordering one -----------------------------------

    /**
     * The first acceptance criterion, through the page that offers it.
     *
     * The key is derived from the name and is never asked for, which is XIV-144's
     * decision for a choice field's options applied one level up — and the
     * position is a number the customer sets rather than one worked out from the
     * fields, because a section is empty for exactly as long as it takes to make
     * one and then put something in it.
     */
    public function testASectionCanBeCreatedAndNamed(): void
    {
        $this->addSection('Billing');

        $sections = $this->sectionsOf();

        self::assertCount(1, $sections);
        self::assertSame('billing', $sections[0]->key, 'the key is derived from the name');
        self::assertSame('Billing', $sections[0]->label);
    }

    /** And a section with no fields in it is a real thing, which is why it has a position of its own. */
    public function testASectionWithNoFieldsExists(): void
    {
        $this->addSection('Billing');

        self::assertSame(0, $this->countOnSectionsPage('billing'), 'nothing in it yet');
        self::assertNotSame([], $this->sectionsOf(), 'and it is still there');
    }

    /** Renaming changes what the page says and moves nothing, because the key is what fields carry. */
    public function testRenamingASectionMovesNoField(): void
    {
        $this->addSection('Billing');
        $this->putInSection('email', 'billing');

        $this->saveSections(labels: ['billing' => 'Rechnungsdaten']);

        $sections = $this->sectionsOf();

        self::assertSame('billing', $sections[0]->key, 'the key is permanent');
        self::assertSame('Rechnungsdaten', $sections[0]->label);
        self::assertSame('billing', $this->fieldOf('email')->getSection(), 'the field is where it was');
    }

    /**
     * The order is the customer's, and it is not the order they were made in.
     *
     * Tens, like a field's, so 15 goes between — and the assertion is on what
     * the *page* draws rather than on the stored numbers, because the order only
     * means anything where it is read.
     */
    public function testSectionsAreDrawnInTheOrderTheCustomerSets(): void
    {
        $this->addSection('Billing');
        $this->addSection('Notes');
        $this->putInSection('email', 'billing');
        $this->putInSection('phone', 'notes');

        // Notes above Billing, which is the reverse of the order they were made.
        $this->saveSections(positions: ['billing' => 20, 'notes' => 10]);

        self::assertSame(['Notes', 'Billing'], $this->headingsOnTheRecordPage($this->aContact()));
    }

    /** A heading with nothing written on it is refused, because a heading is only its name. */
    public function testASectionNeedsAName(): void
    {
        $this->post($this->url('/m/contact/fields/sections/add'), ['name' => '   ']);

        self::assertSelectorTextContains('.alert', 'it is the only thing it says');
        self::assertSame([], $this->sectionsOf());
    }

    // -- putting a field in one, and leaving one out --------------------------

    public function testAFieldCanBePutInASection(): void
    {
        $this->addSection('Billing');
        $this->putInSection('email', 'billing');

        self::assertSame('billing', $this->fieldOf('email')->getSection());
    }

    /**
     * And taken out again, which has to be possible or the first choice is a
     * trap.
     */
    public function testAFieldCanBeTakenOutOfASection(): void
    {
        $this->addSection('Billing');
        $this->putInSection('email', 'billing');
        $this->putInSection('email', '');

        self::assertNull($this->fieldOf('email')->getSection());
    }

    /**
     * The editor's own protection, reached through the editor's own request.
     *
     * The select's blank option means "no section", so a section key the module
     * has never heard of cannot be shrugged off as blank the way a width of 40
     * or a country that does not exist are: that would move somebody's field and
     * report success. It is refused, and the field does not budge.
     */
    public function testAFieldCannotBePutInASectionThatDoesNotExist(): void
    {
        $this->addSection('Billing');
        $this->putInSection('email', 'nowhere');

        self::assertSelectorTextContains('.alert', 'There is no section named "nowhere"');
        self::assertNull($this->fieldOf('email')->getSection(), 'the field did not move');
    }

    /**
     * A collection's fields have no sections, and the refusal is on the write
     * path rather than only absent from the page.
     *
     * A collection row is drawn as a row inside the form and as a row of a
     * *table* on the record page, and a table row has nowhere to put a heading.
     * So the select is not drawn there — and a form posted around it is refused,
     * which is the half that holds for a request nobody drew a page for.
     */
    public function testACollectionsFieldCannotBePutInASection(): void
    {
        $this->addSection('Billing');

        $street = $this->collectionFieldOf('addresses', 'street');
        $this->arrange($this->collectionId(), 'section', (int) $street->getId(), 'billing');

        self::assertSelectorTextContains('.alert', 'has no sections to put fields in');
        self::assertNull($this->collectionFieldOf('addresses', 'street')->getSection());
    }

    /** And the page does not offer it there either. */
    public function testTheSectionSelectIsDrawnOnlyForTheModulesOwnFields(): void
    {
        $this->addSection('Billing');

        $own = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/arrange', $this->shapeId())));

        self::assertGreaterThan(0, $own->filter('select[name^="section["]')->count());

        $rows = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/arrange', $this->collectionId())));

        self::assertCount(0, $rows->filter('select[name^="section["]'));
    }

    // -- an existing definition is untouched ---------------------------------

    /**
     * The claim this ticket turns on, proved against a definition nothing here
     * wrote.
     *
     * The Contact module installed in `setUp()` is the shape every tenant
     * already has: its fields were written by the installer, which knows nothing
     * about sections, and the migration that added the two columns adds nullable
     * ones and backfills nothing. So this is what a definition made before this
     * change looks like the moment after the migration runs — and it has to be
     * indistinguishable from one that never met it.
     */
    public function testADefinitionThatPredatesSectionsCarriesNone(): void
    {
        $definition = $this->definition();

        self::assertSame([], $definition->getSections(), 'the module has no headings');

        foreach ($definition->getFields() as $field) {
            self::assertNull($field->getSection(), sprintf('"%s" is in no section', $field->getKey()));
        }

        foreach ($definition->getCollections() as $collection) {
            foreach ($collection->getFields() as $field) {
                self::assertNull($field->getSection(), sprintf('"%s" is in no section', $field->getKey()));
            }
        }
    }

    /**
     * And such a definition yields one flat run — the thing the form has always
     * drawn.
     */
    public function testADefinitionWithNoSectionsIsOneFlatRun(): void
    {
        $definition = $this->definition();
        $groups = $definition->getFieldGroupsFor('person');

        self::assertCount(1, $groups);
        self::assertNull($groups[0]->section);
        self::assertSame(
            array_map(static fn (FieldDefinition $f): string => $f->getKey(), $definition->getFieldsFor('person')),
            array_map(static fn (FieldDefinition $f): string => $f->getKey(), $groups[0]->fields),
            'every field, in the order it always had',
        );
    }

    /**
     * **And the form draws it through the same call it always did**, which is a
     * stronger statement than "the output looks the same".
     *
     * `form_widget(form.fields)` renders the compound's own container, and that
     * container is the only thing on the page carrying this id. A rewrite that
     * routed the ungrouped case through the grouping branch would produce
     * something that looked right and would lose this — which is exactly the
     * kind of silent drift a promise about existing customers cannot afford.
     */
    public function testTheFormWithNoSectionsTakesTheFlatPath(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/new?variant=person'));

        self::assertCount(
            1,
            $crawler->filter('#module_record_fields'),
            'the compound is rendered by form_widget(), which is the only thing that emits this container',
        );
        // And the fields card holds no grouping rows of its own — a heading and
        // a `<div class="row">` per section is what the other branch produces.
        self::assertCount(0, $crawler->filter('.card')->eq(0)->filter('.card-body > h2'));
    }

    /** The record page likewise: one list, no headings. */
    public function testTheRecordPageWithNoSectionsDrawsOneList(): void
    {
        $id = $this->aContact();

        self::assertSame([], $this->headingsOnTheRecordPage($id));
    }

    // -- the form and the record page agree ----------------------------------

    /**
     * Two templates reading the same definitions, asked the same question
     * through two real requests.
     *
     * This is the place grouping quietly diverges, so it is asserted as an
     * equality between what the two pages say rather than as two separate
     * expectations that could both be edited to match a bug.
     */
    public function testTheFormAndTheRecordPageAgreeAboutTheGrouping(): void
    {
        $this->addSection('Billing');
        $this->addSection('Notes');
        $this->putInSection('email', 'billing');
        $this->putInSection('phone', 'notes');

        $id = $this->aContact();

        self::assertSame(['Billing', 'Notes'], $this->headingsOnTheRecordPage($id));
        self::assertSame(
            $this->headingsOnTheRecordPage($id),
            $this->headingsOnTheForm($id),
            'the form and the record page draw the same headings in the same order',
        );
    }

    /**
     * And the fields land under the heading they were put in, on both pages.
     *
     * Asserted on the **structure** rather than on where two words fall in the
     * source: each group is a heading and a container, so "under this heading"
     * is a containment question and answering it by string position would pass
     * for a page that merely mentioned both in that order.
     */
    public function testAFieldIsDrawnUnderItsOwnHeading(): void
    {
        $this->addSection('Billing');
        $this->putInSection('email', 'billing');

        $id = $this->aContact();

        // The record page: one `<dl>` per group, ungrouped first.
        $lists = $this->client->request('GET', $this->url('/m/contact/' . $id))
            ->filter('.col-lg-8 .card-body > dl');

        self::assertCount(2, $lists, 'the ungrouped run and the section');
        self::assertStringContainsString('Email', $lists->eq(1)->text(), 'the section holds the field');
        self::assertStringNotContainsString('Email', $lists->eq(0)->text(), 'and the ungrouped run does not');

        // The form: one `<div class="row">` per group, in the same order.
        $rows = $this->client->request('GET', $this->url('/m/contact/' . $id . '/edit'))
            ->filter('.card')->eq(0)->filter('.card-body > .row');

        self::assertCount(2, $rows);
        self::assertCount(1, $rows->eq(1)->filter('[name="module_record[fields][email]"]'));
        self::assertCount(0, $rows->eq(0)->filter('[name="module_record[fields][email]"]'));
    }

    /**
     * A section nobody put a field in draws no heading, on either page — the
     * editor keeps it, the pages do not show it.
     */
    public function testAnEmptySectionDrawsNoHeading(): void
    {
        $this->addSection('Billing');

        $id = $this->aContact();

        self::assertSame([], $this->headingsOnTheRecordPage($id));
        self::assertSame([], $this->headingsOnTheForm($id));
    }

    /**
     * A field in no section is still drawn, and above everything that is in one.
     *
     * **The decision that makes an existing customer's page not move.** Every
     * field in every tenant is ungrouped, so putting the ungrouped run last
     * would push twenty-two fields down the page the moment somebody grouped
     * three.
     */
    public function testUngroupedFieldsAreDrawnFirst(): void
    {
        $this->addSection('Billing');
        $this->putInSection('email', 'billing');

        $id = $this->aContact();
        $body = $this->client->request('GET', $this->url('/m/contact/' . $id))->filter('.col-lg-8 .card-body');

        // Heading and list alternate; the first thing in the card is a list.
        $order = $body->children()->each(static fn (object $node): string => $node->nodeName());

        self::assertSame('dl', $order[0] ?? null, 'the ungrouped fields come first, under no heading');
        self::assertStringContainsString('Ada', $body->filter('dl')->eq(0)->text());
    }

    // -- presentation only ---------------------------------------------------

    /**
     * **The claim that a section changes nothing about a record**, made as an
     * identity between two payloads rather than as an inspection of one.
     *
     * The same values are saved twice: once by a module with no sections at all,
     * and once after every field has been put into one. What is stored has to be
     * the same map — not merely equivalent, the same — because the only honest
     * meaning of "presentation" is that the storage layer cannot tell the
     * difference.
     */
    public function testARecordSavedWithSectionsIsStoredIdenticallyToOneSavedWithout(): void
    {
        $values = ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace'];

        $before = $this->dataOf($this->savedId($this->saveRecord(
            ContactModule::KEY,
            [...$values, 'email' => 'ada@example.test'],
            variant: 'person',
        )));

        $this->addSection('Billing');

        foreach (['first_name', 'last_name', 'email'] as $key) {
            $this->putInSection($key, 'billing');
        }

        // A second email, because the module made that field unique and two
        // records may not share one. It is left out of the comparison rather
        // than compared, which is honest here: what is being claimed is that
        // grouping changes nothing about storage, and a value deliberately made
        // different is not evidence either way.
        //
        // **It used to be the same email, and that passed for the wrong reason**
        // ([XIV-163]). Putting a field in a section was a post to the whole
        // field's form naming a label, a position and a section, so every
        // checkbox the form drew and the post did not name read as unticked, and
        // three fields quietly stopped being unique on the way past. Sections are
        // decided on their own page now, that page draws no rule checkboxes at
        // all, and the flag survives; the duplicate is refused, as it always
        // should have been.
        $after = $this->dataOf($this->savedId($this->saveRecord(
            ContactModule::KEY,
            [...$values, 'email' => 'grace@example.test'],
            variant: 'person',
        )));

        unset($before['email'], $after['email']);

        self::assertSame($before, $after, 'the stored payload is untouched by the grouping');
        self::assertTrue($this->fieldOf('email')->isUnique(), 'and the grouping did not relax a rule on the way');
    }

    /** And a filter finds the same records, because nothing about the query changed. */
    public function testAFilterBehavesTheSameWhenFieldsAreInSections(): void
    {
        $this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            variant: 'person',
        );

        $before = $this->matching('Lovelace');

        $this->addSection('Billing');
        $this->putInSection('last_name', 'billing');

        self::assertSame(1, $before);
        self::assertSame($before, $this->matching('Lovelace'), 'the filter is unmoved by the heading');
    }

    // -- removing one --------------------------------------------------------

    /**
     * **What happens to the fields is on the screen before it happens**, which
     * is the acceptance criterion and also the whole reason this page exists: a
     * section looks like a container, so deleting one looks like deleting what
     * is in it.
     */
    public function testTheDeleteConfirmationSaysWhatBecomesOfTheFields(): void
    {
        $this->addSection('Billing');
        $this->putInSection('email', 'billing');
        $this->putInSection('phone', 'billing');

        $this->client->request('GET', $this->url('/m/contact/fields/sections/billing/delete'));

        self::assertSelectorTextContains('body', '2 fields are in it');
        self::assertSelectorTextContains('body', 'keep their values');
        self::assertSelectorTextContains('body', 'top of the form');
    }

    /** And deleting it deletes no field, and no value. */
    public function testDeletingASectionKeepsItsFields(): void
    {
        $this->addSection('Billing');
        $this->putInSection('email', 'billing');

        $id = $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test'],
            variant: 'person',
        ));

        $this->post($this->url('/m/contact/fields/sections/billing/delete'));

        self::assertSelectorTextContains('.alert', 'Its fields are still here');
        // `fieldOf()` asserts the field exists, which is the first half of this:
        // a removal that took its fields with it would fail there rather than
        // here, and would say "contact has no field \"email\"".
        self::assertNull($this->fieldOf('email')->getSection(), 'the field survived, in no section');
        self::assertSame('ada@example.test', $this->dataOf($id)['email'] ?? null, 'and the value never moved');
        self::assertSame([], $this->sectionsOf());
    }

    // -- helpers -------------------------------------------------------------

    private function addSection(string $name): void
    {
        $this->post($this->url('/m/contact/fields/sections/add'), ['name' => $name]);
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, int>    $positions
     */
    private function saveSections(array $labels = [], array $positions = []): void
    {
        $this->post($this->url('/m/contact/fields/sections'), [
            'label' => $labels,
            'position' => array_map(static fn (int $p): string => (string) $p, $positions),
        ]);
    }

    /**
     * Put a field in a section through the page that owns the question
     * ([XIV-163]).
     *
     * Which heading a field sits under is one of the four settings the arrange
     * page draws, and that page is one form for the whole shape: a control per
     * field, named by field id, all submitted together. So this sends the page
     * back exactly as it renders it with one value changed, rather than posting
     * three fields by hand. A checkbox sends nothing when it is unticked, so a
     * post naming only this field's section would read as every list column on
     * the shape being turned off.
     */
    private function putInSection(string $key, string $section): void
    {
        $this->arrange($this->shapeId(), 'section', (int) $this->fieldOf($key)->getId(), $section);
    }

    /**
     * The arrange page, sent back with one control changed.
     *
     * Raw values rather than a submitted `Form`, because two of the tests here
     * are about answers the page does not offer: a section that does not exist,
     * and a section on a collection whose page draws no select at all. DomCrawler
     * refuses to set a select to a value it has no option for, which is the right
     * behaviour for a browser and useless for proving the engine refuses what a
     * browser cannot send. What keeps it honest is that everything *else* posted
     * is what the page itself rendered.
     */
    private function arrange(int $shape, string $control, int $field, string $value): void
    {
        $url = $this->url(sprintf('/m/contact/fields/%d/arrange', $shape));
        $values = $this->client->request('GET', $url)->selectButton('Save')->form()->getPhpValues();
        $values[$control][$field] = $value;

        $this->client->request('POST', $url, $values);
        $this->client->followRedirect();
    }

    /** The contact module's own shape, and its addresses collection. */
    private function shapeId(): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => (int) self::service(MetadataRepository::class)->get(ContactModule::KEY)->getId(),
        );
    }

    private function collectionId(): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function (): int {
            $collection = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getCollection('addresses');
            self::assertNotNull($collection);

            return (int) $collection->getId();
        });
    }

    /** @param array<string, mixed> $values */
    private function post(string $path, array $values = []): void
    {
        $this->client->request('POST', $path, ['_token' => $this->token(), ...$values]);
        $this->client->followRedirect();
    }

    /**
     * The headings drawn on a record's own page, in the order they appear.
     *
     * @return list<string>
     */
    private function headingsOnTheRecordPage(int $id): array
    {
        return $this->headingsIn($this->url('/m/contact/' . $id));
    }

    /**
     * And on the form, which is a different template reading the same rows.
     *
     * @return list<string>
     */
    private function headingsOnTheForm(int $id): array
    {
        return $this->headingsIn($this->url('/m/contact/' . $id . '/edit'));
    }

    /**
     * The section headings on a page, and nothing else that happens to be an h2.
     *
     * Scoped to the card holding the record's own fields, because both pages
     * have other headings on them — a collection's name, the history card, the
     * linked-records groups — and a test that counted those would go red for
     * reasons that have nothing to do with sections.
     *
     * @return list<string>
     */
    private function headingsIn(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        $known = array_map(static fn (object $s): string => $s->label, $this->sectionsOf());

        return array_values(array_filter(
            $crawler->filter('h2')->each(static fn (object $h): string => trim($h->text())),
            static fn (string $text): bool => \in_array($text, $known, true),
        ));
    }

    /** How many fields the sections page says are in one. */
    private function countOnSectionsPage(string $key): int
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/fields/sections'));
        $row = $crawler->filter('tbody tr')->reduce(
            static fn (object $tr): bool => str_contains((string) $tr->filter('a')->attr('href'), '/' . $key . '/'),
        );

        return preg_match('/(\d+) fields/', $row->text(), $m) === 1 ? (int) $m[1] : 0;
    }

    /** @return list<Section> */
    private function sectionsOf(): array
    {
        return $this->definition()->getSections();
    }

    private function definition(): ModuleDefinition
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ModuleDefinition => self::service(MetadataRepository::class)->get(ContactModule::KEY),
        );
    }

    private function fieldOf(string $key): FieldDefinition
    {
        $field = $this->definition()->getField($key);

        self::assertInstanceOf(FieldDefinition::class, $field, sprintf('contact has no field "%s"', $key));

        return $field;
    }

    private function collectionFieldOf(string $collection, string $key): FieldDefinition
    {
        $field = $this->definition()->getCollection($collection)?->getField($key);

        self::assertInstanceOf(FieldDefinition::class, $field);

        return $field;
    }

    /** @return array<string, mixed> */
    private function dataOf(int $id): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): array {
            $definition = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $record = self::service(RecordRepository::class)->find($definition, $id);
            \assert($record instanceof Record);

            return $record->data;
        });
    }

    private function matching(string $lastName): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($lastName): int {
            $definition = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            return \count(self::service(RecordRepository::class)->findBy(
                $definition,
                new RecordQuery([new Filter('last_name', Operator::Equals, $lastName)]),
                RecordAccess::unrestricted(),
            ));
        });
    }

    /** A contact to look at, saved through the form the way the application does. */
    private function aContact(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test'],
            variant: 'person',
        ));
    }

    private function token(): string
    {
        return (string) $this->client
            ->request('GET', $this->url('/m/contact/fields/sections'))
            ->filter('input[name="_token"]')
            ->first()
            ->attr('value');
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::ADMIN,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
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

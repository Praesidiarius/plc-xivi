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
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Import\ImportProblem;
use Xivi\Core\Import\ImportReport;
use Xivi\Core\Import\RecordImporter;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\DuplicateValue;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;

/**
 * One number, one stored value, through every door there is (XIV-114).
 *
 * **The unit test is not this test and cannot be.** `PhoneFieldTypeTest` calls
 * `toStorage()` and asserts what comes out, which proves the method. The claim
 * this ticket actually makes is about the *seam*: that the form, the spreadsheet
 * importer, the unique index and the query compiler cannot disagree about what a
 * phone number is, because none of them has an opinion. That is only provable by
 * going in one door and out another — typing a number one way and then finding it
 * by searching for it the other way, saving it through the form and reading it
 * back through the importer, colliding two spellings against a Postgres index
 * rather than against a PHP comparison.
 *
 * So every test in this class crosses at least one boundary on purpose, and none
 * of them touches `PhoneFieldType` directly.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PhoneNumberTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_phone';
    private const string HOST = 'phone.localhost';
    private const string EMAIL = 'phone@example.test';
    private const string PASSWORD = 'phone-password';

    /** One Swiss mobile, and the two ways somebody writes it. */
    private const string LOCAL = '079 123 45 67';
    private const string INTERNATIONAL = '+41 79 123 45 67';
    private const string STORED = '+41791234567';

    /** A second one, so that a filter can tell the two contacts apart. */
    private const string OTHER_INTERNATIONAL = '+41 79 765 43 21';
    private const string OTHER_LOCAL = '079 765 43 21';

    private KernelBrowser $client;
    private Tenant $tenant;
    private string $path;

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

            // The country the numbers are read against, and the whole of the
            // configuration this feature needs: §8.6's own region, set on the
            // page a customer already fills in. There is no phone setting.
            self::service(TenantProfileManager::class)->apply('Phone AG', '', 'CH');
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Phone', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
        $this->path = (string) tempnam(sys_get_temp_dir(), 'xivi-phone-test-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    // -- the seam -----------------------------------------------------------

    /**
     * **The assertion this whole ticket is about.**.
     *
     * A number goes in through the record form written the way a Swiss person
     * writes it, and is found by a filter typed the way a German person writes
     * it — through the real list page, with the query in the URL, compiled to
     * SQL against a JSONB column. Neither end was told about the other; they
     * agree because both went through `toStorage()`.
     *
     * The reverse is asserted in the same test rather than in a second one,
     * because half of this passing is not a weaker result but a different bug:
     * a normaliser that ran on the write path only would pass the first
     * direction and fail the second.
     */
    public function testANumberTypedOneWayIsFoundByAFilterTypedTheOther(): void
    {
        $this->saveContact('Ada', 'Lovelace', self::LOCAL);
        $this->saveContact('Grace', 'Hopper', self::OTHER_INTERNATIONAL);

        // Typed locally, searched internationally.
        self::assertSame(['Ada Lovelace'], $this->namesFilteredBy(self::INTERNATIONAL));

        // And typed internationally, searched locally — the same claim read
        // backwards, and the one a write-path-only normaliser would fail.
        self::assertSame(['Grace Hopper'], $this->namesFilteredBy(self::OTHER_LOCAL));

        // A third spelling nobody used, to show it is the number being matched
        // and not the string.
        self::assertSame(['Ada Lovelace'], $this->namesFilteredBy('0791234567'));
        self::assertSame([], $this->namesFilteredBy('079 000 00 00'), 'and a different number finds nobody');
    }

    /**
     * The importer is the third door and stores exactly what the form stores.
     *
     * A spreadsheet of numbers typed by hand over ten years is the case this
     * feature exists for, and the importer was never told about phone numbers —
     * it writes through the same repository, which normalises through the same
     * type.
     */
    public function testTheImporterStoresWhatTheFormStores(): void
    {
        $this->saveContact('Ada', 'Lovelace', self::LOCAL);

        $this->file([
            ['kind', 'first_name', 'last_name', 'phone'],
            ['person', 'Grace', 'Hopper', '079/123 45 68'],
        ]);

        $report = $this->import();

        self::assertTrue($report->applied, implode(' | ', $this->problems($report)));
        self::assertSame(
            ['+41791234567', '+41791234568'],
            $this->storedPhones(),
            'one canonical form, whichever door the value came through',
        );
    }

    /**
     * And a row it cannot read is refused, naming the value and the country.
     *
     * The consequence the ticket named in advance: an import of existing data
     * *will* refuse rows, that is correct, and it is still a surprise — so the
     * message has to be one somebody can act on rather than "row 2 is invalid".
     */
    public function testAnImportedRowThatIsNotANumberIsRefusedByName(): void
    {
        $this->file([
            ['kind', 'first_name', 'last_name', 'phone'],
            ['person', 'Grace', 'Hopper', '079 123 45'],
        ]);

        $report = $this->import();
        $said = implode(' | ', $this->problems($report));

        self::assertFalse($report->applied);
        self::assertStringContainsString('079 123 45', $said, 'the value it could not read');
        self::assertStringContainsString('Switzerland', $said, 'and the country it read it against');
        self::assertSame([], $this->storedPhones(), 'and nothing was written');
    }

    // -- unique, against the index ------------------------------------------

    /**
     * **`unique` catches the same number typed two ways, and Postgres is what
     * catches it.**.
     *
     * Deliberately through {@see RecordWriter} rather than through the form:
     * the form runs {@see \Xivi\Core\Validation\RecordValidator} first, which
     * would refuse the duplicate in PHP with a query of its own and never let
     * the write happen — so a green test would have proved a PHP comparison and
     * said nothing about [XIV-109]'s index. The writer validates nothing. What
     * refuses the second save here is the unique expression index over
     * `data ->> 'phone'`, and {@see DuplicateValue} is the engine turning a
     * driver exception back into something a person can read.
     *
     * Which is only possible because the two spellings *became one string* on
     * the way in. Before this field type they were two, and the index was
     * perfectly happy with both.
     */
    public function testUniqueCatchesTheSameNumberTypedTwoWays(): void
    {
        $this->makePhoneUnique();

        $this->write(['kind' => ContactModule::PERSON, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => self::LOCAL]);

        try {
            $this->write(['kind' => ContactModule::PERSON, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'phone' => self::INTERNATIONAL]);

            self::fail('the index accepted one number written two ways');
        } catch (DuplicateValue $refused) {
            self::assertStringContainsString(
                'Phone',
                $refused->translatable()->trans(self::service(TranslatorInterface::class), 'en'),
                'and it says which field it was about',
            );
        }

        self::assertSame([self::STORED], $this->storedPhones(), 'exactly one record holds the number');
    }

    /**
     * The readable half, on the form, where somebody can still fix it.
     *
     * The validator's own query compares against the normalised value too — it
     * has to, or the two halves would disagree about what a duplicate is, which
     * is precisely the failure §5.19 describes for voucher codes.
     */
    public function testTheFormRefusesTheDuplicateBeforeItReachesTheIndex(): void
    {
        $this->makePhoneUnique();
        $this->saveContact('Ada', 'Lovelace', self::LOCAL);

        $response = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::PERSON,
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'phone' => self::INTERNATIONAL,
        ], variant: ContactModule::PERSON);

        self::assertSame('', (string) $response->headers->get('Location'), 'nothing was saved');
        self::assertStringContainsString('Another record already uses this value.', (string) $response->getContent());
    }

    // -- refusals -----------------------------------------------------------

    /**
     * An unreadable number is refused with a sentence naming the value and the
     * country it was read against.
     *
     * `079 123 45` parses perfectly well and comes back as `+4107912345`, which
     * is a string of digits and not a telephone. Storing it would put something
     * in the column that looks exactly like a phone number and cannot be rung.
     */
    public function testAnUnreadableNumberIsRefusedWithSomethingToActOn(): void
    {
        $response = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::PERSON,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '079 123 45',
        ], variant: ContactModule::PERSON);

        $body = (string) $response->getContent();

        self::assertSame('', (string) $response->headers->get('Location'), 'the form comes back rather than a redirect');
        self::assertStringContainsString('079 123 45', $body, 'naming the value');
        self::assertStringContainsString('Switzerland', $body, 'and the country it was read against');
        self::assertSame([], $this->storedPhones());
    }

    /**
     * An extension is refused, and the refusal says what to do instead.
     *
     * The decision, and the reason it is not merely fussiness: E.164 has no room
     * for an extension and formatting drops it silently, so keeping the value
     * would file a switchboard and everybody behind it under one number.
     */
    public function testAnExtensionIsRefusedAndSaysWhereToPutIt(): void
    {
        $response = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::PERSON,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '+41 44 668 18 00 ext. 12',
        ], variant: ContactModule::PERSON);

        $body = (string) $response->getContent();

        self::assertSame('', (string) $response->headers->get('Location'));
        self::assertStringContainsString('extension', $body);
        self::assertStringContainsString('field of its own', $body, 'and what to do about it');
        self::assertSame([], $this->storedPhones());
    }

    /**
     * The per-field override, through the editor rather than through an option
     * set by hand.
     *
     * The claim is a chain and every link of it is asserted here: the *Country*
     * select is drawn on the phone field's row, choosing Germany lands
     * `region: DE` in that field's options, and the very next number typed into
     * the record form without a country code is read as a German one — while the
     * installation's own country goes on being Switzerland for everything else.
     *
     * A hand-set option would have proved the last link and none of the first
     * three, and the first three are where a capability interface, a `PER_TYPE`
     * entry and a template `{% if %}` can each silently fail to be wired up.
     */
    public function testAFieldCanBeGivenItsOwnCountryInTheEditor(): void
    {
        $this->setCountryOfPhoneField('DE');

        self::assertSame('DE', $this->phoneField()->getOption('region'), 'the editor wrote the option');

        $this->saveContact('Ada', 'Lovelace', self::LOCAL);
        self::assertSame(['+49791234567'], $this->storedPhones(), 'and the same digits are now a German number');

        // Emptied again, and the field goes back to following the installation.
        // Blank clearing rather than being left alone is the rule every control
        // on that row keeps (XIV-26), and the one a form that could only ever
        // *add* a setting would get backwards.
        $this->setCountryOfPhoneField('');

        self::assertNull($this->phoneField()->getOption('region'));
    }

    // -- reading ------------------------------------------------------------

    /**
     * E.164 is for storing, not for reading: a Swiss number on a Swiss reader's
     * page is written the way it is written on a business card.
     *
     * Through the record page rather than through `display()`, because the claim
     * is about what somebody sees — and the locale that decides it is set by the
     * request listener from [XIV-50]'s chain, which a direct call would bypass.
     */
    public function testALocalReaderSeesTheNumberTheWayTheyWriteIt(): void
    {
        $id = $this->saveContact('Ada', 'Lovelace', self::INTERNATIONAL);

        $this->client->request('GET', sprintf('https://%s/m/contact/%d', self::HOST, $id));

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString(self::LOCAL, $body, 'national, because the reader is where the number is');
        self::assertStringNotContainsString(self::STORED, $body, 'and never the stored form');
    }

    // -- helpers ------------------------------------------------------------

    private function signIn(): void
    {
        $page = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
        $this->client->submit($page->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
    }

    /** Saved the way a person saves one: the record form, through the component. */
    private function saveContact(string $first, string $last, string $phone): int
    {
        $response = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::PERSON,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone,
        ], variant: ContactModule::PERSON);

        self::assertNotSame('', (string) $response->headers->get('Location'), sprintf(
            '"%s" did not save: %s',
            $phone,
            strip_tags((string) $response->getContent()),
        ));

        foreach ($this->all() as $record) {
            if (($record->data['last_name'] ?? null) === $last) {
                return (int) $record->id;
            }
        }

        self::fail(sprintf('"%s %s" saved and then could not be found', $first, $last));
    }

    /**
     * The list page, filtered, as a URL somebody could paste.
     *
     * @return list<string> the names of the contacts that came back
     */
    private function namesFilteredBy(string $typed): array
    {
        $crawler = $this->client->request('GET', sprintf(
            'https://%s/m/contact?%s',
            self::HOST,
            http_build_query(['filter' => [['path' => 'phone', 'op' => 'eq', 'value' => $typed]]]),
        ));

        self::assertResponseIsSuccessful();
        // The list's own rows rather than the page's text: a name that happened
        // to appear in a flash message or a filter box would otherwise read as a
        // match, which is the one way this assertion could pass while the
        // feature was broken.
        self::assertSelectorNotExists('.alert-warning', 'the query was answered rather than refused');

        return $crawler->filter('tbody tr td:first-child a')->each(
            static fn (Crawler $cell): string => trim($cell->text()),
        );
    }

    /**
     * Choose a country for the phone field on the editor's own page.
     *
     * Sends what a browser sends, which is not what DomCrawler associates: the
     * editor's controls sit in table cells and belong to their row's form
     * through the HTML5 `form` attribute, so every control pointing at that form
     * has to be gathered by hand — the same shape `FieldUiTest::setWidth()` uses
     * and for the same reason. Sending only the country would read as somebody
     * unticking every box on the row.
     */
    private function setCountryOfPhoneField(string $country): void
    {
        $page = $this->client->request('GET', sprintf('https://%s/m/contact/fields', self::HOST));
        self::assertResponseIsSuccessful();

        $row = $page->filter('tbody tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->text(), 'phone'),
        )->first();

        $form = $row->filter('form')->first();
        $id = (string) $form->attr('id');

        self::assertCount(
            1,
            $page->filter(sprintf('select[form="%s"][name="region"]', $id)),
            'the country select is drawn on a phone field',
        );

        $values = ['_token' => (string) $form->filter('[name="_token"]')->attr('value')];

        foreach ($page->filter(sprintf('[form="%s"]', $id)) as $node) {
            \assert($node instanceof \DOMElement);

            $name = $node->getAttribute('name');

            if ($name === '' || $name === 'region') {
                continue;
            }

            // A checkbox sends its value only when it is ticked, which is the
            // whole difference between "filterable" staying on and being turned
            // off by a save that was about something else.
            if ($node->getAttribute('type') === 'checkbox') {
                if ($node->hasAttribute('checked')) {
                    $values[$name] = $node->getAttribute('value');
                }

                continue;
            }

            $values[$name] = $node->nodeName === 'select' ? self::selected($node) : $node->getAttribute('value');
        }

        $values['region'] = $country;

        $this->client->request('POST', sprintf('https://%s%s', self::HOST, (string) $form->attr('action')), $values);
        $this->client->followRedirect();
    }

    /** Whichever option a select is showing. */
    private static function selected(\DOMElement $select): string
    {
        foreach ($select->getElementsByTagName('option') as $option) {
            if ($option->hasAttribute('selected')) {
                return $option->getAttribute('value');
            }
        }

        return '';
    }

    private function makePhoneUnique(): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $field = $this->phoneField();
            self::service(MetadataEditor::class)->makeUnique($field);
        });
    }

    private function phoneField(): FieldDefinition
    {
        $field = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField('phone');
        self::assertNotNull($field);

        return $field;
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): Record => self::service(RecordWriter::class)
            ->save(self::service(MetadataRepository::class)->get(ContactModule::KEY), new Record($data)));
    }

    /** @return list<string> */
    private function storedPhones(): array
    {
        $phones = [];

        foreach ($this->all() as $record) {
            if (isset($record->data['phone'])) {
                $phones[] = (string) $record->data['phone'];
            }
        }

        sort($phones);

        return $phones;
    }

    /** @return list<Record> */
    private function all(): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): array => self::service(RecordRepository::class)
            ->findBy(
                self::service(MetadataRepository::class)->get(ContactModule::KEY),
                new RecordQuery(),
                RecordAccess::unrestricted(),
            ));
    }

    /** @param list<list<string>> $rows */
    private function file(array $rows): void
    {
        $writer = new Writer();
        $writer->openToFile($this->path);
        $writer->getCurrentSheet()->setName('contact');

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();
    }

    private function import(): ImportReport
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, fn (): ImportReport => self::service(RecordImporter::class)
            ->apply(self::service(MetadataRepository::class)->get(ContactModule::KEY), $this->path));
    }

    /** @return list<string> */
    private function problems(ImportReport $report): array
    {
        $translator = self::service(TranslatorInterface::class);

        return array_map(
            static fn (ImportProblem $problem): string => $problem->translatable()->trans($translator, 'en'),
            $report->problems,
        );
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

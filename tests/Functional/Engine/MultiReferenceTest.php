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
use App\Tests\Support\Dbal\MeasuresQueries;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Document\DocumentMarkers;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Export\RecordExporter;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Import\ImportProblem;
use Xivi\Core\Import\ImportReport;
use Xivi\Core\Import\RecordImporter;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Query\Sort;
use Xivi\Core\Query\UnsupportedQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordPrimer;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Record\ReferenceTargets;

/**
 * A field that names several records (XIV-113).
 *
 * One test per decision the ticket asked to be taken, because every one of them
 * could be got wrong in a way that looks right from the page: an array quietly
 * joined into a string still renders, a `unique` flag that matches nothing still
 * saves, a filter that compares text still finds *something*, and a page that
 * fetches a title per link is only slower. So the assertions here are mostly
 * about what is **stored**, what is **counted** and what is **refused**, rather
 * than about what is drawn.
 *
 * **The subject is a contact naming several articles**, which is the shape the
 * ticket describes (the tags on a contact, the categories an article is in),
 * with two modules that already exist rather than a fixture module invented
 * here. Beside it, on the same shape, a plain `reference` naming one article:
 * that field is what proves the other half of the storage criterion, since a
 * single reference's storage has to be exactly what it was.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MultiReferenceTest extends WebTestCase
{
    use MeasuresQueries;
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_multi_reference';
    private const string HOST = 'multiref.localhost';
    private const string ADMIN = 'admin@multiref.test';
    /** Whose session a record is saved under (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string PASSWORD = 'multiref-password';

    /** The field under test, and the single link beside it. */
    private const string SEVERAL = 'articles';
    private const string ONE = 'main_article';

    /**
     * The two sizes every query count here is taken at.
     *
     * Ten times the records, three links each, so a lookup per *value* would
     * show up as a difference of 135 and a lookup per record as one of 45.
     * {@see ReferencePrimingTest} takes its counts at the same pair and for the
     * same reason.
     */
    private const int FEW = 5;
    private const int MANY = 50;

    /** Links per record in the priming fixture. */
    private const int LINKS = 3;

    private KernelBrowser $client;
    private Tenant $tenant;
    private string $path;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);
        $this->path = tempnam(sys_get_temp_dir(), 'xivi-multiref-') . '.xlsx';

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            foreach ([ContactModule::KEY, ArticleModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }

            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $editor = self::service(MetadataEditor::class);

            if ($contacts->getField(self::SEVERAL) === null) {
                $editor->addField(
                    shape: $contacts,
                    key: self::SEVERAL,
                    label: 'Articles',
                    type: 'multi_reference',
                    filterable: true,
                    listed: true,
                    options: [ReferenceFieldType::MODULE => ArticleModule::KEY],
                );
            }

            if ($contacts->getField(self::ONE) === null) {
                $editor->addField(
                    shape: $contacts,
                    key: self::ONE,
                    label: 'Main article',
                    type: 'reference',
                    filterable: true,
                    options: [ReferenceFieldType::MODULE => ArticleModule::KEY],
                );
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    // -- the acceptance criteria, in order -----------------------------------

    /**
     * A record names several, and the payload says so in JSON, beside a single
     * reference whose payload has not moved.
     *
     * Read out of the column as text rather than through the repository, because
     * a repository that hydrated a joined string into a list would make the two
     * indistinguishable, and which of the two is written is the whole criterion.
     * The form is the write path, so this is also what proves the picker gives
     * back a list of ids rather than one.
     */
    public function testAContactNamesSeveralArticlesAndTheColumnHoldsAnArray(): void
    {
        $articles = $this->someArticles(3);

        $saved = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => [$articles[0], $articles[2]],
            self::ONE => $articles[1],
        ], variant: ContactModule::COMPANY);

        self::assertTrue($saved->isRedirect(), 'the form accepted several links');

        $stored = $this->payloadOf(ContactModule::KEY, $this->onlyContact());

        self::assertSame([$articles[0], $articles[2]], $stored[self::SEVERAL], 'a JSON array of ids');
        self::assertSame($articles[1], $stored[self::ONE], 'and a single reference is still a bare integer');
    }

    /**
     * The value is a set: de-duplicated, ascending, and the same however it was
     * picked.
     *
     * The second half is the one worth a test. Saving the same records in
     * another order has to be a save that changed nothing, or every re-save of a
     * record would write a history entry about a reordering nobody made and the
     * timeline would fill up with noise (§5.2). It is true because the storage
     * form is sorted and {@see RecordWriter}'s diff compares storage forms with
     * `===`, which for an array is order-sensitive.
     */
    public function testTheOrderIsNotMeaningfulAndTwoSpellingsOfOneSetAreOneValue(): void
    {
        $articles = $this->someArticles(3);
        [$a, $b, $c] = $articles;

        $contact = $this->write(ContactModule::KEY, [
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => [$c, $a, $b, $a],
        ]);

        self::assertSame([$a, $b, $c], $this->payloadOf(ContactModule::KEY, $contact)[self::SEVERAL]);

        // The same set, typed the other way round. One history entry, from the
        // creation above, and nothing about a change.
        $this->write(ContactModule::KEY, [
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => [$b, $c, $a],
        ], $contact);

        self::assertCount(1, $this->historyOf($contact), 'reordering the same links is not a change');
    }

    /**
     * `unique` is refused, and refused for a reason that is stated.
     *
     * Both halves matter. The engine refusing is what makes it true for the
     * importer and the console; the form not drawing the box is what stops a
     * customer meeting the refusal by ticking something. A checkbox that is
     * always refused is §8.3.1's own defect, and its absence has to be about
     * *this type* rather than about the form having lost the control, which is
     * why the same page is opened for `text` and asked the opposite question.
     */
    public function testUniqueIsRefusedRatherThanQuietlyMeaningNothing(): void
    {
        $refusal = null;

        try {
            self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(MetadataEditor::class)->addField(
                shape: self::service(MetadataRepository::class)->get(ContactModule::KEY),
                key: 'more_articles',
                label: 'More articles',
                type: 'multi_reference',
                unique: true,
                options: [ReferenceFieldType::MODULE => ArticleModule::KEY],
            ));
        } catch (MetadataChangeRefused $caught) {
            $refusal = $caught;
        }

        self::assertInstanceOf(MetadataChangeRefused::class, $refusal, 'the engine refuses the flag');
        self::assertStringContainsString('several values', $refusal->getMessage());

        // And the same refusal on a field that already exists, which is the door
        // an edit goes through.
        $this->expectException(MetadataChangeRefused::class);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $field = $this->fieldOf(ContactModule::KEY, self::SEVERAL);

            self::service(MetadataEditor::class)->updateField(
                field: $field,
                label: $field->getLabel(),
                required: false,
                unique: true,
                filterable: true,
                listed: true,
                title: false,
                position: $field->getPosition(),
            );
        });
    }

    /** And the box is not on the form for this type, while it is for every other. */
    public function testTheAddFormDoesNotOfferTheUniqueBoxForThisType(): void
    {
        $shape = $this->shapeId();

        $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/add/multi_reference', $shape)));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="unique"]', 'a box that would always be refused is not drawn');
        self::assertSelectorExists(
            'select[name="module"]',
            'and the one answer this type cannot work without is asked, on its own form',
        );

        $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/add/text', $shape)));
        self::assertSelectorExists('input[name="unique"]', 'the control itself has not gone missing');
    }

    /**
     * "Has this article" finds the records holding it, and does not find the
     * ones holding a number it is a prefix of.
     *
     * The second half is what a comma-joined string would have got wrong: asking
     * a `LIKE '%3%'` about article 3 finds every record naming article 13, 30 or
     * 23. The record holding 13 here names an article that does not exist, and
     * deliberately so: whether an id points at something is §7.6's open
     * question, and this test is about the comparison rather than about the row.
     */
    public function testIncludesIsContainmentAndNotTextMatching(): void
    {
        $articles = $this->someArticles(2);
        [$a, $b] = $articles;

        $both = $this->write(ContactModule::KEY, ['kind' => ContactModule::COMPANY, 'company_name' => 'Both', self::SEVERAL => [$a, $b]]);
        $second = $this->write(ContactModule::KEY, ['kind' => ContactModule::COMPANY, 'company_name' => 'Second', self::SEVERAL => [$b]]);
        $none = $this->write(ContactModule::KEY, ['kind' => ContactModule::COMPANY, 'company_name' => 'None']);
        $decoy = $this->write(ContactModule::KEY, [
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Decoy',
            // The id `$a` with a digit stuck on it. A text comparison finds this
            // one; containment does not.
            self::SEVERAL => [(int) ($a . '3')],
        ]);

        self::assertSame([$both], $this->matching(new Filter(self::SEVERAL, Operator::Includes, $a)));
        self::assertSame([$second, $both], $this->matching(new Filter(self::SEVERAL, Operator::Includes, $b)));

        // Everything that has not got it, empty records included: a contact with
        // no articles genuinely does not have this one.
        self::assertSame(
            [$decoy, $none, $second],
            $this->matching(new Filter(self::SEVERAL, Operator::Excludes, $a)),
        );

        // The decoy is *not* empty: it names an article that does not exist,
        // which is a stale link rather than a blank (§7.6).
        self::assertSame([$none], $this->matching(new Filter(self::SEVERAL, Operator::IsEmpty, null)));
    }

    /**
     * Two of them mean *and*, which is the honest answer to a question the query
     * layer cannot ask.
     *
     * "Has any of these" is the `OR` tree §7.3 says is not built, and the way
     * that would go wrong is not a crash but a filter that quietly answered a
     * different question. So the conjunction is asserted: this is what a pair of
     * these filters does, and it is what every other pair of filters does.
     */
    public function testTwoIncludesFiltersMeanAndRatherThanAny(): void
    {
        [$a, $b] = $this->someArticles(2);

        $both = $this->write(ContactModule::KEY, ['kind' => ContactModule::COMPANY, 'company_name' => 'Both', self::SEVERAL => [$a, $b]]);
        $this->write(ContactModule::KEY, ['kind' => ContactModule::COMPANY, 'company_name' => 'One', self::SEVERAL => [$a]]);

        self::assertSame([$both], $this->matching(
            new Filter(self::SEVERAL, Operator::Includes, $a),
            new Filter(self::SEVERAL, Operator::Includes, $b),
        ));
    }

    /**
     * Sorting by it is refused, and the list header does not offer the link.
     *
     * The two go together on purpose: the refusal is what makes a typed URL
     * honest, and the header is what stops anybody arriving at it by clicking.
     * A column offering a sort that raises would be a 500 somebody was invited
     * to.
     */
    public function testSortingByAFieldOfSeveralValuesIsRefusedAndNotOffered(): void
    {
        $this->write(ContactModule::KEY, ['kind' => ContactModule::COMPANY, 'company_name' => 'Acme AG']);

        $this->client->request('GET', $this->url('/m/contact'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('thead', 'Articles', 'the column is drawn');
        self::assertSelectorNotExists(
            sprintf('thead a[href*="sort=%s"]', self::SEVERAL),
            'and it is a heading rather than a link, because there is no ordering to offer',
        );

        $this->expectException(UnsupportedQuery::class);

        $this->find(new RecordQuery([], [new Sort(self::SEVERAL)], 1, 25));
    }

    /**
     * Ten times the records, the same number of statements.
     *
     * The acceptance criterion, in one `assertSame`, and `assertSame` rather
     * than "fewer than N" for {@see ReferencePrimingTest}'s reason: a bound that
     * grows with the data is the bug, so what has to fail is the moment the
     * count starts to move at all.
     *
     * A multi-value field is where this is easiest to lose. A page of 25 records
     * with four links each asks for a hundred names, and every one of them is a
     * lookup unless the whole set is read at once, so the second assertion is
     * the corroboration: without priming the count really does climb, which is
     * what makes the flat one worth something (XIV-60).
     */
    public function testTitlesArePrimedRatherThanFetchedPerValueHoweverManyRecords(): void
    {
        $few = $this->contactsNamingArticles(self::FEW);
        $many = $this->contactsNamingArticles(self::MANY);

        [$smallNames, $small] = $this->namesOf($few, prime: true);
        [$largeNames, $large] = $this->namesOf($many, prime: true);

        self::assertCount(self::FEW, $smallNames);
        self::assertCount(self::MANY, $largeNames);

        self::assertSame($small, $large, sprintf(
            'a page of these is bounded: %d records cost %d queries, %d records cost %d',
            self::FEW,
            $small,
            self::MANY,
            $large,
        ));

        [$unprimedNames, $unprimed] = $this->namesOf($few, prime: false);

        self::assertSame($smallNames, $unprimedNames, 'the same names either way, which is what must not move');
        self::assertGreaterThan($small, $unprimed, 'and without priming it really is one lookup per value');
    }

    /**
     * A marker prints the names, separated by commas, in a document and in an
     * email alike.
     *
     * §5.13.1 gave a *collection* a table because its rows have columns; a set of
     * names has one column, so this needs no grammar of its own and is an
     * ordinary record marker filled through `display()`. Asserted through
     * {@see DocumentMarkers}, which is the one class that fills both (the email
     * renderer takes its markers from it rather than keeping a second list), so
     * this is one assertion about both destinations rather than the same claim
     * made twice.
     */
    public function testAMarkerPrintsTheNamesRatherThanTheIds(): void
    {
        $articles = $this->someArticles(2);

        $contact = $this->write(ContactModule::KEY, [
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => $articles,
        ]);

        $markers = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($contact): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $contact);
            self::assertInstanceOf(Record::class, $record);

            return self::service(DocumentMarkers::class)->dataFor($module, $record);
        });

        self::assertSame('Article 1, Article 2', $markers[self::SEVERAL] ?? null);
    }

    /**
     * Export, re-import, nothing changes: §5.6's standard, with several ids in
     * one cell.
     */
    public function testAMultiValueSurvivesTheRoundTripThroughASpreadsheet(): void
    {
        $articles = $this->someArticles(3);

        $contact = $this->write(ContactModule::KEY, [
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => [$articles[0], $articles[2]],
        ]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(RecordExporter::class)
            ->toFile($this->module(), new RecordQuery(), RecordAccess::unrestricted(), $this->path));

        // The cell itself, because "it round-trips" would also be true of a file
        // holding something nobody could read, and §5.6 says the file is the
        // interchange format.
        self::assertSame(
            $articles[0] . ',' . $articles[2],
            $this->cellOf('contact', self::SEVERAL),
            'the ids go into one cell, separated by a comma',
        );

        $report = $this->import(commit: true);

        self::assertTrue($report->applied, implode(' | ', $this->messages($report)));
        self::assertSame(0, $report->created, 'a re-import is not a second copy');
        self::assertSame([$articles[0], $articles[2]], $this->payloadOf(ContactModule::KEY, $contact)[self::SEVERAL]);
    }

    /**
     * An item nobody can read is an error naming the field and the item, not a
     * link that quietly went missing.
     *
     * The defect this is against is the tempting implementation: split on the
     * separator, keep what parses, drop the rest. A cell reading `12,tuesday,34`
     * would then import as two links and say nothing, which is exactly what
     * §5.6's all-or-nothing exists to prevent: the person who ran the import
     * cannot tell you what is in the database, and neither can anybody else.
     */
    public function testAnItemThatIsNotARecordIdIsAnActionableErrorRatherThanASilentDrop(): void
    {
        $articles = $this->someArticles(2);

        $this->file(['contact' => [
            ['id', ...$this->module()->getFieldKeys()],
            $this->rowFor(['kind' => ContactModule::COMPANY, 'company_name' => 'Acme AG', self::SEVERAL => $articles[0] . ',tuesday,' . $articles[1]]),
        ]]);

        $report = $this->import(commit: true);

        self::assertFalse($report->applied, 'nothing is written while the file says something unreadable');

        $said = implode(' | ', $this->messages($report));

        self::assertStringContainsString('Articles', $said, 'the field is named');
        self::assertStringContainsString('tuesday', $said, 'and so is the item that could not be read');

        self::assertSame([], $this->find(new RecordQuery()), 'and no half-read record survives');
    }

    /**
     * The reverse-link card sees a record named among several, not only one
     * named on its own (XIV-52).
     *
     * The failure this guards is silent by construction: a card that counted
     * only `reference` fields would show an article with four contacts tagged
     * with it as an article nothing points at, which reads exactly like the
     * truth about an article nothing points at.
     */
    public function testTheReverseLinkCardCountsRecordsThatNameThisOneAmongSeveral(): void
    {
        [$article] = $this->someArticles(1);

        $this->write(ContactModule::KEY, [
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Tagged With It',
            self::SEVERAL => [$article],
        ]);

        $this->client->request('GET', $this->url('/m/article/' . $article));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Tagged With It', 'a contact naming this article among several is counted');
    }

    // -- fixtures and helpers ------------------------------------------------

    /**
     * `$count` articles, named so that a display assertion can be written down.
     *
     * @return list<int>
     */
    private function someArticles(int $count): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($count): array {
            $articles = self::service(MetadataRepository::class)->get(ArticleModule::KEY);
            $writer = self::service(RecordWriter::class);
            $ids = [];

            for ($n = 1; $n <= $count; ++$n) {
                $record = $writer->save($articles, new Record(data: [
                    'title' => sprintf('Article %d', $n),
                    'price' => '19.90',
                ]));

                $ids[] = (int) $record->id;
            }

            return $ids;
        });
    }

    /**
     * `$count` contacts, each naming {@see self::LINKS} articles of its own.
     *
     * Distinct articles per contact on purpose: records all naming the same ones
     * would be bounded by the memo whatever this ticket did, and would prove
     * nothing about priming.
     *
     * @return list<Record>
     */
    private function contactsNamingArticles(int $count): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($count): array {
            $articles = self::service(MetadataRepository::class)->get(ArticleModule::KEY);
            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $writer = self::service(RecordWriter::class);

            $records = [];

            for ($n = 1; $n <= $count; ++$n) {
                $links = [];

                for ($link = 0; $link < self::LINKS; ++$link) {
                    $article = $writer->save($articles, new Record(data: [
                        'title' => sprintf('Article %d.%d', $n, $link),
                        'price' => '19.90',
                    ]));

                    $links[] = (int) $article->id;
                }

                $records[] = $writer->save($contacts, new Record(data: [
                    'kind' => ContactModule::COMPANY,
                    'company_name' => sprintf('Company %d', $n),
                    self::SEVERAL => $links,
                ]));
            }

            return $records;
        });
    }

    /**
     * What a page would print for these records, and what it cost.
     *
     * Rendered through the field type one value at a time, which is the point:
     * nothing during rendering knows the whole set, so priming has to have
     * happened before the loop starts.
     *
     * @param list<Record> $records
     *
     * @return array{list<string>, int}
     */
    private function namesOf(array $records, bool $prime): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($records, $prime): array {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $field = $this->fieldOf(ContactModule::KEY, self::SEVERAL);
            $type = self::service(FieldTypeRegistry::class)->get($field->getType());

            // Everything read once per request and not the subject, which is
            // the target module's own definitions, happens before the counting
            // starts, so what is left is the lookups themselves.
            self::service(MetadataRepository::class)->get(ArticleModule::KEY);

            // And a memo left over from another test in this class would answer
            // for free and measure nothing.
            self::service(ReferenceTargets::class)->reset();
            self::service(ReferenceFieldType::class)->reset();

            return self::countingQueries(static function () use ($module, $records, $field, $type, $prime): array {
                if ($prime) {
                    self::getContainer()->get(RecordPrimer::class)->prime($module, $records);
                }

                return array_map(
                    static fn (Record $record): string => $type->display($record->get(self::SEVERAL), $field),
                    $records,
                );
            });
        });
    }

    /**
     * The ids matching these filters, oldest last, which is the order every list
     * is in.
     *
     * @return list<int>
     */
    private function matching(Filter ...$filters): array
    {
        return array_map(
            static fn (Record $record): int => (int) $record->id,
            $this->find(new RecordQuery(array_values($filters))),
        );
    }

    /** @return list<Record> */
    private function find(RecordQuery $query): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(RecordRepository::class)
                ->findBy($this->module(), $query, RecordAccess::unrestricted()),
        );
    }

    /**
     * A record written straight through the writer.
     *
     * Deliberately not through the record form: what most of these tests are
     * about is what the engine does with values that are *there*, and driving a
     * live component to put them there would make the setup longer than the
     * claim. The one claim that is about the write path goes through the form.
     *
     * @param array<string, mixed> $data
     */
    private function write(string $module, array $data, ?int $id = null): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $data, $id): int {
            $shape = self::service(MetadataRepository::class)->get($module);
            $record = $id === null
                ? new Record(data: $data)
                : new Record(data: $data, id: $id);

            return (int) self::service(RecordWriter::class)->save($shape, $record)->id;
        });
    }

    /**
     * The row's payload as JSON gave it back, rather than as the repository
     * hydrated it.
     *
     * The difference is the whole point of the storage criterion: a repository
     * that read a joined string into a list would make the two spellings
     * indistinguishable from anywhere above it.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(string $module, int $id): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $id): array {
            $shape = self::service(MetadataRepository::class)->get($module);
            $connection = self::tenantConnection();

            $json = $connection->fetchOne(
                sprintf('SELECT data FROM %s WHERE id = :id', $shape->getTableName()),
                ['id' => $id],
            );

            $decoded = json_decode(\is_string($json) ? $json : '{}', true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded));

            return $decoded;
        });
    }

    /**
     * The tenant's own connection, not the default one.
     *
     * `default_connection` is `control` (§3.1), so asking the container for a
     * `Connection` in a test gives the control plane's, and a customer's records
     * are not in it. The repository gets the right one by autowiring inside the
     * tenant context; a test reading a column by hand has to say which.
     */
    private static function tenantConnection(): Connection
    {
        $registry = self::getContainer()->get('doctrine');
        \assert($registry instanceof \Doctrine\Persistence\ManagerRegistry);

        $connection = $registry->getConnection('tenant');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /** @return list<mixed> */
    private function historyOf(int $id): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(HistoryRepository::class)->findFor($this->module(), $id),
        );
    }

    private function onlyContact(): int
    {
        $records = $this->find(new RecordQuery());
        self::assertCount(1, $records);

        return (int) $records[0]->id;
    }

    private function module(): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(ContactModule::KEY);
    }

    private function fieldOf(string $module, string $key): FieldDefinition
    {
        $field = self::service(MetadataRepository::class)->get($module)->getField($key);
        self::assertInstanceOf(FieldDefinition::class, $field);

        return $field;
    }

    private function shapeId(): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => (int) $this->module()->getId(),
        );
    }

    /**
     * One row of the module sheet, in the shape's own field order.
     *
     * @param array<string, mixed> $values
     *
     * @return list<mixed>
     */
    private function rowFor(array $values): array
    {
        $row = [''];

        foreach ($this->module()->getFieldKeys() as $key) {
            $row[] = $values[$key] ?? '';
        }

        return $row;
    }

    private function cellOf(string $sheet, string $field): string
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($sheet, $field): string {
            $reader = new \OpenSpout\Reader\XLSX\Reader();
            $reader->open($this->path);

            $header = [];
            $value = '';

            foreach ($reader->getSheetIterator() as $page) {
                if (mb_strtolower($page->getName()) !== $sheet) {
                    continue;
                }

                foreach ($page->getRowIterator() as $index => $row) {
                    if ($index === 1) {
                        $header = array_map(self::asText(...), $row->toArray());

                        continue;
                    }

                    $column = array_search($field, $header, true);
                    self::assertIsInt($column, sprintf('the sheet has a "%s" column', $field));

                    $value = self::asText($row->toArray()[$column] ?? '');

                    break;
                }
            }

            $reader->close();

            return $value;
        });
    }

    /**
     * One cell as the text a header or a value reads as.
     *
     * A spreadsheet cell is any of a dozen things, and every one of them either
     * is a scalar or is not the sort of cell this test writes. Anything else
     * reads as blank rather than being cast, which would be a fatal on a rich
     * text run nobody put there.
     */
    private static function asText(mixed $cell): string
    {
        return \is_scalar($cell) ? (string) $cell : '';
    }

    /** @param array<string, list<list<mixed>>> $sheets */
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

    private function import(bool $commit): ImportReport
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ImportReport => $commit
                ? self::service(RecordImporter::class)->apply($this->module(), $this->path)
                : self::service(RecordImporter::class)->check($this->module(), $this->path),
        );
    }

    /**
     * The problems as somebody would read them, translated rather than raw: a
     * problem carries a key (XIV-8), and asserting on the key would stop
     * noticing whether the sentence it names still exists.
     *
     * @return list<string>
     */
    private function messages(ImportReport $report): array
    {
        $translator = self::service(TranslatorInterface::class);

        return array_map(
            static fn (ImportProblem $problem): string => (string) $translator->trans(
                $problem->translatable()->getMessage(),
                $problem->translatable()->getParameters(),
                $problem->translatable()->getDomain(),
            ),
            $report->problems,
        );
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
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

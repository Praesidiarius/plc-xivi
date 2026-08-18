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
use App\Tests\Support\Module\JobModule;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Import\ImportProblem;
use Xivi\Core\Import\ImportReport;
use Xivi\Core\Import\RecordImporter;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\CollectionLimit;
use Xivi\Core\Record\CollectionTooLong;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Record\SubmittedRows;

/**
 * A collection is capped, and the cap is refused rather than truncated (XIV-68).
 *
 * §5.1 measured what a long collection costs and the answer was that the edit
 * form is linear in the row count at about 0.34 MB a row — so a document long
 * enough answers 500 out of the middle of Twig rather than getting slow. The cap
 * is where that stops being possible; the memory limit beside it
 * (`frankenphp/conf.d/10-app.ini`) is what makes the capped size renderable.
 *
 * **Three write paths, one number, and this file exercises all three.** The
 * record form, the importer and {@see RecordWriter::save()} itself. They meet at
 * the writer — which is why the check lives there — but each of the first two
 * asks first, because what a person should meet is a sentence on the page they
 * are looking at rather than an exception.
 *
 * **The form asks before the form exists** (XIV-90). Asking after it was built
 * meant building four hundred and one row forms to discover that four hundred and
 * one is too many, twice over, which is how a readable limit turns into the 500 it
 * was written to prevent. So the rows are counted from the submitted values —
 * {@see SubmittedRows} — and the tests below hold down that the refusal is the
 * same sentence from the same {@see CollectionLimit} whichever layer produced it,
 * that not one row form is built to reach it, and that a submission nobody could
 * count is refused as itself rather than as a long one.
 *
 * **What is deliberately not tested here is the read view**, because it
 * deliberately has no bound: 18 queries flat and about 15 KB a row means it
 * survives to roughly 9 500, and with writes capped at 400 it is never near it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CollectionLimitTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_collection_cap';
    private const string HOST = 'collectioncap.localhost';
    private const string EMAIL = 'cap@example.test';
    private const string PASSWORD = 'cap-password';

    private KernelBrowser $client;
    private Tenant $tenant;
    private string $path;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(JobModule::KEY),
            ),
        );

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Cap', self::PASSWORD, ['ROLE_ADMIN']);

        $this->path = (string) tempnam(sys_get_temp_dir(), 'xivi-cap-test-');

        $this->signIn();
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    /**
     * The cap itself is a supported length, not the first refused one.
     *
     * Worth its own test because an off-by-one here is invisible: everything
     * would still work, and the supported size would quietly be 399.
     */
    public function testTheCapItselfIsWritten(): void
    {
        $id = $this->writeJobWith(CollectionLimit::MAX_ROWS);

        self::assertCount(CollectionLimit::MAX_ROWS, $this->linesOf($id));
    }

    /** The backstop: a caller holding the writer gets a refusal and no record. */
    public function testTheWriterRefusesMoreThanTheCap(): void
    {
        $before = \count($this->allJobs());

        try {
            $this->writeJobWith(CollectionLimit::MAX_ROWS + 1);
            self::fail('a collection over the cap was written');
        } catch (CollectionTooLong $refusal) {
            $message = $refusal->translatable()->trans(self::service(TranslatorInterface::class), 'en');

            self::assertStringContainsString((string) CollectionLimit::MAX_ROWS, $message, 'it names the limit');
            self::assertStringContainsString((string) (CollectionLimit::MAX_ROWS + 1), $message, 'and what was attempted');
            self::assertStringContainsString('Lines', $message, 'and which collection, as the customer calls it');
        }

        self::assertCount($before, $this->allJobs(), 'and nothing at all was written');
    }

    /**
     * The form answers with the refusal on it rather than with a 500.
     *
     * Which is the whole point of capping at write time: the page a person is
     * looking at tells them the number and what to do, and the record they were
     * editing is untouched.
     */
    public function testTheFormRefusesMoreThanTheCap(): void
    {
        $response = $this->saveRecord(
            JobModule::KEY,
            ['title' => 'Far too long', 'status' => JobModule::DRAFT],
            ['lines' => $this->formRows(CollectionLimit::MAX_ROWS + 1)],
        );

        self::assertNull($response->headers->get('Location'), 'the save was refused rather than redirecting');

        $shown = strip_tags((string) $response->getContent());

        self::assertStringContainsString((string) CollectionLimit::MAX_ROWS, $shown, 'the page names the limit');
        self::assertStringContainsString((string) (CollectionLimit::MAX_ROWS + 1), $shown, 'and what was typed');
        self::assertSame([], $this->allJobs(), 'and no record was created');
    }

    /**
     * And it refuses without drawing a row form per submitted row (XIV-90).
     *
     * **The point of the whole ticket, in one assertion.** The refusal used to
     * arrive after the submission had been built as four hundred and one row
     * forms — twice, because the Live Component builds the form and a throwaway
     * one beside it — which is about 2 × 140 MB against the 256M a request is
     * allowed. A limit that can only be reported by first doing the thing it
     * forbids is not a limit.
     *
     * The page that comes back is the record as it stands, which for a new record
     * is a form with no rows on it at all. That is what is asserted here: not
     * "this was fast", which would be a timing test and therefore a flaky one,
     * but that not one row was drawn.
     */
    public function testTheRefusedFormDrawsNoneOfTheSubmittedRows(): void
    {
        $response = $this->saveRecord(
            JobModule::KEY,
            ['title' => 'Far too long', 'status' => JobModule::DRAFT],
            ['lines' => $this->formRows(CollectionLimit::MAX_ROWS + 1)],
        );

        $body = (string) $response->getContent();

        self::assertStringContainsString((string) CollectionLimit::MAX_ROWS, strip_tags($body), 'the refusal is on the page');
        self::assertSame(
            0,
            substr_count($body, 'row-of-collection'),
            'and not one of the submitted rows was built as a form to discover that',
        );
    }

    /**
     * The other shape a record form is posted in reaches the same refusal
     * (XIV-90).
     *
     * A Live Component action sends the whole model at once; an ordinary form
     * post sends one entry per control, keyed by the path its `name` makes. They
     * are decoded by different code inside the library, so "the check reads the
     * values after both have been applied" is a claim worth holding down rather
     * than assuming — see {@see SavesRecords::postRecordForm()} for what each
     * looks like on the wire.
     */
    public function testAnOrdinaryFormPostIsCountedTheSameWay(): void
    {
        $response = $this->postRecordForm(JobModule::KEY, [
            'fields' => ['title' => 'Far too long', 'status' => JobModule::DRAFT],
            'collections' => ['lines' => $this->formRows(CollectionLimit::MAX_ROWS + 1)],
        ]);

        $shown = strip_tags((string) $response->getContent());

        self::assertNull($response->headers->get('Location'), 'the save was refused rather than redirecting');
        self::assertStringContainsString((string) CollectionLimit::MAX_ROWS, $shown, 'the page names the limit');
        self::assertStringContainsString((string) (CollectionLimit::MAX_ROWS + 1), $shown, 'and what was sent');
        self::assertSame([], $this->allJobs(), 'and no record was created');
    }

    /**
     * A submission that cannot be counted is refused as itself, not as a long one
     * (XIV-90).
     *
     * Nothing a browser sends can put a string where a collection's rows belong,
     * so what arrives that way was written by hand. It is still refused — a
     * submission nobody can read is not one anybody should write — but with a
     * sentence of its own, because the alternative is a message naming a limit
     * and a count where the count was invented. The two sentences are asserted
     * against each other rather than merely for their own presence: "refused
     * distinguishably" is the claim, and it fails the moment one collapses into
     * the other.
     */
    public function testASubmissionThatCannotBeCountedIsRefusedAsItself(): void
    {
        $response = $this->saveRecord(
            JobModule::KEY,
            ['title' => 'Unreadable', 'status' => JobModule::DRAFT],
            // Not rows: a value standing where the rows belong.
            ['lines' => 'not rows at all'], // @phpstan-ignore argument.type
        );

        $shown = strip_tags((string) $response->getContent());
        $translator = self::service(TranslatorInterface::class);

        self::assertNull($response->headers->get('Location'), 'the save was refused rather than redirecting');
        self::assertStringContainsString(
            $translator->trans(SubmittedRows::UNREADABLE, [], 'xivi', 'en'),
            $shown,
            'it says the values could not be read',
        );
        self::assertStringNotContainsString(
            (string) CollectionLimit::MAX_ROWS,
            $shown,
            'and names no limit, because there was no count to hold against one',
        );
        self::assertSame([], $this->allJobs(), 'and no record was created');
    }

    /**
     * The importer answers with a problem against the sheet (XIV-26).
     *
     * An import writes in bulk with nobody clicking, so this is the path a cap
     * most needs: a file of five hundred lines is one paste in a spreadsheet, and
     * the refusal has to arrive as a line in the report beside every other reason
     * a file was refused.
     */
    public function testTheImporterRefusesMoreThanTheCap(): void
    {
        $lines = [['id', 'parent_id', 'kind', 'text']];

        for ($i = 1; $i <= CollectionLimit::MAX_ROWS + 1; ++$i) {
            $lines[] = ['', 'job-1', JobModule::ITEM, sprintf('Line %d', $i)];
        }

        $this->file([
            'job' => [['id', 'title', 'status'], ['job-1', 'Imported', JobModule::DRAFT]],
            'lines' => $lines,
        ]);

        $report = $this->import();
        $messages = implode(' | ', $this->messages($report));

        self::assertFalse($report->applied, 'the file was refused');
        self::assertStringContainsString((string) CollectionLimit::MAX_ROWS, $messages, 'it names the limit');
        self::assertStringContainsString((string) (CollectionLimit::MAX_ROWS + 1), $messages, 'and what the file held');
        self::assertSame([], $this->allJobs(), 'and one bad sheet refuses the whole file (§5.6)');
    }

    // -- helpers ------------------------------------------------------------

    /** A job of exactly this many lines, written the way the engine writes one. */
    private function writeJobWith(int $rows): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($rows): int {
            $record = new Record(data: ['title' => 'Long job', 'status' => JobModule::DRAFT]);

            self::service(RecordWriter::class)->save(self::module(), $record, ['lines' => array_map(
                static fn (int $i): array => ['id' => null, 'data' => [
                    'kind' => JobModule::ITEM,
                    'text' => sprintf('Line %d', $i),
                ]],
                range(1, $rows),
            )]);

            return (int) $record->id;
        });
    }

    /**
     * The same rows, shaped the way the record form submits them.
     *
     * **Comment lines rather than item lines**, which used to be the difference
     * between this class running and this class exhausting the suite's 512M. An
     * over-long form was built before it could be refused — the values arrived
     * through the form, so there was no counting them without it — and in the test
     * environment, with the profiler collecting a node per template, an item row's
     * four controls put this class at the ceiling. A comment row carries two
     * (§5.5: a row's fields follow from its kind) and exercised the same path for
     * half the weight.
     *
     * **XIV-90 removed the reason and the numbers say so**: the whole class peaked
     * at 350 MB before it and at 80 MB after, with three more tests in it. The
     * comment rows stay because there is no argument for making a test heavier
     * than it needs to be, and this note stays because the next person to wonder
     * why should find the answer rather than the workaround.
     *
     * @return list<array<string, mixed>>
     */
    private function formRows(int $rows): array
    {
        return array_map(
            static fn (int $i): array => self::row(['kind' => JobModule::COMMENT, 'text' => sprintf('Line %d', $i)]),
            range(1, $rows),
        );
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

    private function import(): ImportReport
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ImportReport => self::service(RecordImporter::class)->apply(self::module(), $this->path),
        );
    }

    /**
     * The problems as somebody would read them, translated rather than read raw
     * — asserting on the key would stop noticing whether the sentence it names
     * still exists.
     *
     * @return list<string>
     */
    private function messages(ImportReport $report): array
    {
        $translator = self::service(TranslatorInterface::class);

        return array_map(
            static fn (ImportProblem $problem): string => $problem->translatable()->trans($translator, 'en'),
            $report->problems,
        );
    }

    /** @return list<Record> */
    private function allJobs(): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(RecordRepository::class)
                ->findBy(self::module(), new RecordQuery(), RecordAccess::unrestricted()),
        );
    }

    /** @return list<Record> */
    private function linesOf(int $id): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): array {
            $lines = self::module()->getCollection('lines');
            self::assertNotNull($lines);

            return self::service(RecordRepository::class)->findChildren($lines, $id);
        });
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]));
    }

    private static function module(): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(JobModule::KEY);
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

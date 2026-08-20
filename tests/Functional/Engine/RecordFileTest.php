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
use App\Tenant\Attachment\AttachmentStore;
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\PermissionGroup;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Xivi\Contact\ContactModule;
use Xivi\ControlPlane\Command\CheckTenantFilesCommand;
use Xivi\Core\Field\AttachmentLimit;
use Xivi\Core\Field\StoredFile;
use Xivi\Core\Form\RecordFileType;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * A record carrying a file (XIV-115).
 *
 * The criteria this class is answerable for are the ones about **one tenant's
 * own records**: that a 10 MB PDF goes in and comes back byte for byte, that the
 * database holds metadata and the disk holds bytes, that a download is a
 * permission question rather than a guessing game, and that a file too large is
 * refused with the real limit in the sentence. The two that need a second
 * customer or a real `DROP DATABASE` are in
 * {@see \App\Tests\Functional\Tenancy\AttachmentIsolationTest}, which provisions
 * its own tenants for the reason that class's docblock gives.
 *
 * **The subject is a contract on a contact**, which is the ticket's own example,
 * on a module that already exists rather than a fixture invented here.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordFileTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_record_file';
    private const string HOST = 'recordfile.localhost';
    private const string ADMIN = 'admin@recordfile.test';
    /** Whose session a record is saved under (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string PASSWORD = 'recordfile-password';

    /** The field under test. */
    private const string FIELD = 'contract';

    /**
     * How much more a 10 MB upload may cost than a 4 KB one, in bytes.
     *
     * One megabyte, against a measurement of 0.32: generous, because what it has
     * to tell apart is a version of this code that holds the file, which lands
     * above ten.
     */
    private const int MEMORY_DIFFERENCE = 1024 * 1024;

    /**
     * And how much a whole 10 MB file may cost to read back.
     *
     * Two megabytes, against a measurement of 0.14. This one can be absolute
     * because the operation it measures is the copy and nothing else.
     */
    private const int MEMORY_CEILING = 2 * 1024 * 1024;

    private KernelBrowser $client;
    private Tenant $tenant;

    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            self::service(ModuleInstaller::class)->install(self::service(ModuleRegistry::class)->get(ContactModule::KEY));

            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            if ($contacts->getField(self::FIELD) === null) {
                self::service(MetadataEditor::class)->addField(
                    shape: $contacts,
                    key: self::FIELD,
                    label: 'Contract',
                    type: 'file',
                    listed: true,
                );
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        // Whatever the tests wrote, off the disk. DAMA rolls the database back
        // and cannot roll back a filesystem, so a class that did not clean up
        // after itself would leave orphans for every run, and the drift check
        // test below would eventually be measuring the ones it did not make.
        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            self::service(AttachmentStore::class)->removeEverything(...),
        );

        parent::tearDown();
    }

    // -- the acceptance criteria, in order -----------------------------------

    /**
     * Ten megabytes in, and the same ten megabytes back.
     *
     * The whole feature in one test, and every step of it is somewhere a large
     * file goes wrong: the upload has to reach the Live Component's action, the
     * bytes have to leave PHP's temporary file, the record has to hold a value
     * that survives a save, the list has to show it, and the download has to
     * hand back exactly what went in. The comparison is a hash of the whole body
     * rather than a length, because a stream copied in chunks fails by dropping
     * or duplicating one chunk, and a truncated file has the wrong length only
     * if the truncation is not the last chunk.
     */
    public function testATenMegabytePdfRoundTrips(): void
    {
        $path = $this->aFileOf(AttachmentLimit::MAX_BYTES, 'Contract 2026.pdf');
        $id = $this->saveWithFile($path, 'Contract 2026.pdf', 'application/pdf');

        $stored = StoredFile::parse($this->payloadOf($id)[self::FIELD] ?? null);
        self::assertInstanceOf(StoredFile::class, $stored, 'the record holds a file');
        self::assertSame(AttachmentLimit::MAX_BYTES, $stored->size);
        self::assertSame('Contract 2026.pdf', $stored->name);

        // Listed: the record page names the file and offers the download.
        $page = $this->client->request('GET', $this->url(sprintf('/m/%s/%d', ContactModule::KEY, $id)));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Contract 2026.pdf', $page->text());
        self::assertCount(
            1,
            $page->filter(sprintf('a[href$="/m/%s/%d/file/%s"]', ContactModule::KEY, $id, self::FIELD)),
            'the record page links to the download',
        );

        $body = $this->download($id);

        self::assertSame(hash_file('sha256', $path), hash('sha256', $body), 'byte-identical');
        self::assertSame((string) AttachmentLimit::MAX_BYTES, $this->client->getResponse()->headers->get('Content-Length'));
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString(
            'attachment',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
            'a file is offered as a download rather than rendered',
        );
    }

    /**
     * The bytes are on the filesystem and the database holds a reference.
     *
     * Read as text out of the JSONB column rather than through the repository,
     * because the criterion is about **what is stored**: a column holding the
     * file itself and a column holding a reference to one are indistinguishable
     * from any layer above this. The `pg_column_size` assertion is the half that
     * would catch a well-meaning change putting the bytes in a bytea beside the
     * document, which is exactly what §5.7 did for templates and §5.30 declines
     * to do here.
     */
    public function testTheDatabaseHoldsMetadataAndTheDiskHoldsBytes(): void
    {
        $path = $this->aFileOf(512 * 1024, 'notes.pdf');
        $id = $this->saveWithFile($path, 'notes.pdf', 'application/pdf');

        $row = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): int {
            $shape = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            return (int) self::tenantConnection()->fetchOne(
                sprintf('SELECT pg_column_size(data) FROM %s WHERE id = :id', $shape->getTableName()),
                ['id' => $id],
            );
        });

        self::assertLessThan(4096, $row, 'the row is a row, not half a megabyte of PDF');

        $stored = StoredFile::parse($this->payloadOf($id)[self::FIELD] ?? null);
        self::assertInstanceOf(StoredFile::class, $stored);

        self::assertTrue(
            self::service(TenantSwitcher::class)->runFor(
                $this->tenant,
                fn (): bool => self::service(AttachmentStore::class)->has($stored),
            ),
            'and the bytes are on the filesystem',
        );
    }

    /**
     * A file over the limit is refused, and the sentence has the real limit in
     * it.
     *
     * The ticket's requirement, and the reason it is worth a test rather than a
     * glance: the number in the message comes from the constant that enforces it,
     * so a limit raised in one place and not the other would fail here rather
     * than reaching somebody as an apology about 10 MB from a form that accepts
     * 20.
     */
    public function testAFileOverTheLimitIsRefusedWithTheLimitNamed(): void
    {
        $path = $this->aFileOf(AttachmentLimit::MAX_BYTES + 1024, 'huge.pdf');
        $response = $this->submitFile($path, 'huge.pdf', 'application/pdf');

        self::assertFalse($response->isRedirect(), 'the save was refused');

        $body = (string) $response->getContent();
        self::assertStringContainsString('huge.pdf', $body);
        self::assertStringContainsString(AttachmentLimit::shown(AttachmentLimit::MAX_BYTES), $body);

        self::assertSame([], $this->tokensOnDisk(), 'and nothing it refused was left on the disk');
    }

    /**
     * A link is not a credential.
     *
     * The reader here may view *their own* records and this one is not theirs, so
     * the record page 404s and the download has to answer the same way: §8.4's
     * rule is that a record somebody may not view answers 404 rather than 403, and
     * a download that answered 403 would confirm the file exists to somebody who
     * was told the record does not.
     */
    public function testADownloadIsRefusedForSomebodyWhoMayNotOpenTheRecord(): void
    {
        $path = $this->aFileOf(2048, 'private.pdf');
        $id = $this->saveWithFile($path, 'private.pdf', 'application/pdf');

        $this->signInAs($this->scopedReader());

        $this->client->request('GET', $this->url(sprintf('/m/%s/%d', ContactModule::KEY, $id)));
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode(), 'the record page');

        $this->client->request('GET', $this->url(sprintf('/m/%s/%d/file/%s', ContactModule::KEY, $id, self::FIELD)));
        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->client->getResponse()->getStatusCode(),
            'and the file, at an address they hold',
        );
    }

    /**
     * A field that is not a file field, and a record that has no file, both
     * answer 404 rather than something worse.
     *
     * The addresses somebody reaches by editing a URL. Neither is an error: there
     * is nothing at either address, which is what 404 means.
     */
    public function testAnAddressWithNoFileBehindItIsANotFound(): void
    {
        $id = (int) $this->savedId($this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
        ], variant: ContactModule::COMPANY));

        foreach ([self::FIELD, 'company_name', 'no_such_field'] as $field) {
            $this->client->request('GET', $this->url(sprintf('/m/%s/%d/file/%s', ContactModule::KEY, $id, $field)));

            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $this->client->getResponse()->getStatusCode(),
                sprintf('"%s" holds no file', $field),
            );
        }
    }

    /**
     * Replacing a file takes the old bytes off the disk.
     *
     * The half of the design that keeps orphans rare enough for the check
     * command to be worth reading. The old file's *name* survives in the
     * record's history, which is what "versioning is out of scope" costs and is
     * asserted here so that it is a decision rather than a surprise.
     */
    public function testReplacingAFileRemovesTheBytesItReplaced(): void
    {
        $first = $this->aFileOf(4096, 'first.pdf');
        $id = $this->saveWithFile($first, 'first.pdf', 'application/pdf');

        $before = StoredFile::parse($this->payloadOf($id)[self::FIELD] ?? null);
        self::assertInstanceOf(StoredFile::class, $before);

        $second = $this->aFileOf(8192, 'second.pdf');
        $this->saveWithFile($second, 'second.pdf', 'application/pdf', $id);

        $after = StoredFile::parse($this->payloadOf($id)[self::FIELD] ?? null);
        self::assertInstanceOf(StoredFile::class, $after);
        self::assertNotSame($before->token, $after->token, 'a new upload is a new file');

        self::assertSame([$after->token], $this->tokensOnDisk(), 'and only one of them is on the disk');
    }

    /**
     * The tick that takes a file off a record.
     *
     * Removing is part of saving the record, so it is undone by not saving,
     * like every other change on the form.
     */
    public function testTickingRemoveTakesTheFileOffTheRecord(): void
    {
        $path = $this->aFileOf(4096, 'gone.pdf');
        $id = $this->saveWithFile($path, 'gone.pdf', 'application/pdf');

        $stored = StoredFile::parse($this->payloadOf($id)[self::FIELD] ?? null);
        self::assertInstanceOf(StoredFile::class, $stored);

        $response = $this->saveRecord(ContactModule::KEY, [
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::FIELD => [
                RecordFileType::STORED => $stored->stored(),
                RecordFileType::REMOVE => true,
            ],
        ], recordId: $id, variant: ContactModule::COMPANY);

        self::assertTrue($response->isRedirect(), 'the save went through');
        self::assertArrayNotHasKey(self::FIELD, $this->payloadOf($id), 'and the record holds nothing');
    }

    /**
     * Ten megabytes go in without ten megabytes being in memory.
     *
     * The ticket asks for this proved with a genuinely large file rather than a
     * 4 KB fixture, and measured. **The measurement is a difference rather than a
     * ceiling**, and that is the only way to ask this question honestly here: a
     * save through this harness renders the component twice and costs about 18 MB
     * whatever it is carrying, so an absolute limit would either be so loose it
     * proved nothing or would be measuring Twig. What the difference isolates is
     * exactly the thing under test, because the two saves are the same save with
     * a file 2,500 times larger.
     *
     * **Measured on PHP 8.5 (2026-08-20): a 4 KB upload cost 8.95 MB and a 10 MB
     * upload cost 8.63 MB**, a difference of 0.32 MB with the *larger* file on
     * the cheaper side, which is the shape of a number that is noise rather than
     * signal. A version of this code that read the upload with
     * `file_get_contents()` lands at over 10 MB of difference, and one that asked
     * `finfo` for the media type by *path* rather than from a sample landed at
     * 14.6 MB, which is how that line in {@see \App\Record\RecordUploads} came to
     * be written the way it is.
     */
    public function testNothingReadsAWholeFileIntoMemoryOnTheWayIn(): void
    {
        // **One save is thrown away before anything is measured.** The first
        // one through this component pays for lazy services, a compiled Twig
        // template and a form type it has never built, which measures at about
        // eleven megabytes more than the second and has nothing to do with what
        // is being carried. Comparing a cold save against a warm one would say
        // the small file cost more than the large one, which is true and is not
        // an answer to the question.
        $this->costOfStoring(4096, 'warm-up.pdf');

        $small = $this->costOfStoring(4096, 'small.pdf');
        $large = $this->costOfStoring(AttachmentLimit::MAX_BYTES, 'heavy.pdf');

        self::assertLessThan(
            self::MEMORY_DIFFERENCE,
            abs($large - $small),
            sprintf(
                'a 4 KB upload cost %s and a %s one cost %s',
                AttachmentLimit::shown($small),
                AttachmentLimit::shown(AttachmentLimit::MAX_BYTES),
                AttachmentLimit::shown($large),
            ),
        );
    }

    /**
     * And they come out again without being in memory either.
     *
     * Measured at the seam rather than through the browser, and the reason is the
     * harness: `HttpKernelBrowser::filterResponse()` buffers a streamed response
     * whole so that a test can assert on the body, so a measurement taken around
     * a request would be measuring PHPUnit's copy of the file. What the *route*
     * does is asserted in {@see self::testATenMegabytePdfRoundTrips()}, which
     * requires the response to be a {@see StreamedResponse}: a plain `Response`
     * there would mean the body had been built as a string before anything was
     * sent, whatever this measures.
     *
     * **Measured at 0.14 MB against a 10 MB file** on PHP 8.5 (2026-08-20), which
     * is two chunks and the objects around them.
     */
    public function testNothingReadsAWholeFileIntoMemoryOnTheWayOut(): void
    {
        $path = $this->aFileOf(AttachmentLimit::MAX_BYTES, 'heavy.pdf');
        $id = $this->saveWithFile($path, 'heavy.pdf', 'application/pdf');

        $stored = StoredFile::parse($this->payloadOf($id)[self::FIELD] ?? null);
        self::assertInstanceOf(StoredFile::class, $stored);

        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();

        $copied = self::service(TenantSwitcher::class)->runFor($this->tenant, static function () use ($stored): int {
            $stream = self::service(AttachmentStore::class)->readStream($stored);
            $sink = fopen('/dev/null', 'wb');
            \assert(\is_resource($sink));
            $written = 0;

            // The download route's own loop, chunk for chunk. Copying somewhere
            // that keeps nothing is what leaves only the buffer on the scales.
            while (!feof($stream)) {
                $chunk = fread($stream, AttachmentLimit::CHUNK_BYTES);

                if ($chunk === false) {
                    break;
                }

                $written += (int) fwrite($sink, $chunk);
            }

            fclose($stream);
            fclose($sink);

            return $written;
        });

        $grew = memory_get_peak_usage() - $before;

        self::assertSame(AttachmentLimit::MAX_BYTES, $copied, 'the whole file came out');
        self::assertLessThan(
            self::MEMORY_CEILING,
            $grew,
            sprintf('reading a %s file grew memory by %s', AttachmentLimit::shown(AttachmentLimit::MAX_BYTES), AttachmentLimit::shown($grew)),
        );
    }

    /**
     * The drift check finds a record whose file is gone.
     *
     * The finding an operator most wants: a restore that brought the database
     * back without the volume produces this in bulk, and until this command
     * existed the only way to learn about it was somebody clicking a download.
     */
    public function testTheCheckReportsARecordWhoseFileIsMissing(): void
    {
        $path = $this->aFileOf(4096, 'vanishing.pdf');
        $id = $this->saveWithFile($path, 'vanishing.pdf', 'application/pdf');

        $stored = StoredFile::parse($this->payloadOf($id)[self::FIELD] ?? null);
        self::assertInstanceOf(StoredFile::class, $stored);

        // Taken off the disk behind the record's back, which is what a bad
        // restore does and what nothing in the application does.
        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            static fn () => self::service(AttachmentStore::class)->delete($stored),
        );

        $tester = $this->check();

        self::assertSame(CheckTenantFilesCommand::DRIFTED, $tester->getStatusCode());
        self::assertStringContainsString('point at a file that is not there', $tester->getDisplay());
        self::assertStringContainsString('vanishing.pdf', $tester->getDisplay());
    }

    /**
     * And a file no record claims.
     *
     * Ordinary in small numbers: an upload is written before the save that would
     * name it is validated, so a refused save leaves one. The command says so
     * beside the finding rather than treating it as damage, which is what keeps
     * it worth reading.
     */
    public function testTheCheckReportsAFileNoRecordClaims(): void
    {
        $orphan = self::service(TenantSwitcher::class)->runFor($this->tenant, static function (): StoredFile {
            $stream = fopen('php://temp', 'r+b');
            \assert(\is_resource($stream));
            fwrite($stream, 'nobody claims this');
            rewind($stream);

            return self::service(AttachmentStore::class)->store($stream, 'orphan.pdf', 'application/pdf');
        });

        $tester = $this->check();

        self::assertSame(CheckTenantFilesCommand::DRIFTED, $tester->getStatusCode());
        self::assertStringContainsString('no record claims', $tester->getDisplay());
        self::assertStringContainsString($orphan->token, $tester->getDisplay());
    }

    /** And says so plainly when the two agree. */
    public function testTheCheckIsQuietWhenRecordsAndFilesAgree(): void
    {
        $path = $this->aFileOf(4096, 'accounted.pdf');
        $this->saveWithFile($path, 'accounted.pdf', 'application/pdf');

        $tester = $this->check();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('all accounted for', $tester->getDisplay());
    }

    /**
     * A file on a collection row is refused, in the editor and on the page that
     * offers types.
     *
     * Both halves matter and they are different failures. The engine's refusal is
     * what makes the rule true for the importer and the console; the page not
     * offering the type is what keeps somebody from meeting that refusal after
     * filling in a form, which is §8.3.1's rule about a control that looks like it
     * works.
     *
     * The reason is the download route: it is addressed by module and record id,
     * and a collection row has no address of its own. Building the field first
     * and the address later would mean a control that takes a customer's contract
     * and gives nobody a way back to it.
     */
    public function testACollectionRowCannotHoldAFile(): void
    {
        $collection = self::service(TenantSwitcher::class)->runFor($this->tenant, function (): int {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $addresses = $module->getCollections()[0] ?? null;
            self::assertNotNull($addresses);

            try {
                self::service(MetadataEditor::class)->addField(
                    shape: $addresses,
                    key: 'scan',
                    label: 'Scan',
                    type: 'file',
                );
                self::fail('a file field was added to a collection');
            } catch (MetadataChangeRefused $refused) {
                self::assertStringContainsString('no address of its own', $refused->getMessage());
            }

            return (int) $addresses->getId();
        });

        // And the page that asks which type to add does not offer one.
        $page = $this->client->request(
            'GET',
            $this->url(sprintf('/m/%s/fields/%d/add', ContactModule::KEY, $collection)),
        );
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('>file<', $page->html());

        // On the module itself it is offered, or the assertion above would pass
        // for the wrong reason.
        $module = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => (int) self::service(MetadataRepository::class)->get(ContactModule::KEY)->getId(),
        );

        $page = $this->client->request(
            'GET',
            $this->url(sprintf('/m/%s/fields/%d/add', ContactModule::KEY, $module)),
        );
        self::assertStringContainsString('>file<', $page->html());
    }

    // -- helpers -------------------------------------------------------------

    /**
     * What one save costs in memory, from just before the request to the peak
     * inside it.
     *
     * The fixture is written before the measurement starts, in chunks, so the
     * number is about storing the file rather than about making it.
     */
    private function costOfStoring(int $bytes, string $name): int
    {
        $path = $this->aFileOf($bytes, $name);

        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();

        $id = $this->saveWithFile($path, $name, 'application/pdf');
        self::assertGreaterThan(0, $id);

        return memory_get_peak_usage() - $before;
    }

    /**
     * A file of exactly this many bytes, written once and deleted in tearDown.
     *
     * Written in chunks rather than with `str_repeat`, because a test that
     * allocated 10 MB to make its fixture would be measuring PHP's memory
     * against a number it had itself put there, and
     * {@see \App\Tests\Measurement\AttachmentMemoryTest} reads that number.
     */
    private function aFileOf(int $bytes, string $name): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-file-');
        self::assertIsString($path);
        $this->paths[] = $path;

        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);

        // **It really is a PDF**, at least as far as anything that looks at a
        // file decides: `finfo` reads `%PDF-` at offset zero and answers
        // `application/pdf`. That matters to the round trip, which asserts the
        // media type came out of the bytes rather than out of what the upload
        // claimed (§8.6 took the same decision about a logo).
        $written = (int) fwrite($handle, "%PDF-1.7\n");

        // Not a repeated byte after that: a run of zeroes compresses to nothing
        // and would let a middleware that quietly gzipped the response look like
        // a byte-identical download. The name seeds it so two fixtures differ.
        $chunk = str_repeat(hash('sha256', $name, true), (int) ceil(AttachmentLimit::CHUNK_BYTES / 32));

        while ($written < $bytes) {
            $written += (int) fwrite($handle, substr($chunk, 0, min(\strlen($chunk), $bytes - $written)));
        }

        fclose($handle);
        self::assertSame($bytes, filesize($path));

        return $path;
    }

    /** Saves a contact carrying this file, and gives back its id. */
    private function saveWithFile(string $path, string $name, string $type, ?int $recordId = null): int
    {
        $response = $this->submitFile($path, $name, $type, $recordId);

        return $this->savedId($response);
    }

    /**
     * The record form, submitted with a file beside its values.
     *
     * The harness sends the file exactly where a browser sends it: a Live
     * Component's values travel as JSON and a file cannot, so the library puts
     * it in the same `FormData` under the input's own name, which is
     * `module_record[fields][contract][upload]`.
     */
    private function submitFile(string $path, string $name, string $type, ?int $recordId = null): Response
    {
        return $this->saveRecord(
            ContactModule::KEY,
            [
                'kind' => ContactModule::COMPANY,
                'company_name' => 'Acme AG',
            ],
            recordId: $recordId,
            variant: ContactModule::COMPANY,
            files: [
                'module_record' => [
                    'fields' => [
                        self::FIELD => [
                            // `test: true` because there is no real multipart
                            // upload here and `is_uploaded_file()` would refuse
                            // the fixture, which is the one thing about this
                            // that is not what a browser does.
                            RecordFileType::UPLOAD => new UploadedFile($path, $name, $type, test: true),
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * The whole body of a download.
     *
     * The kernel's own response is asserted to be a {@see StreamedResponse},
     * which is the criterion: a `Response` here would mean the whole file had
     * been built as a string before anything was sent. The *bytes* are read off
     * the browser's response rather than by streaming it again, because
     * `HttpKernelBrowser::filterResponse()` has already run `sendContent()` into
     * a buffer of its own, and a `StreamedResponse` streams exactly once.
     */
    private function download(int $id): string
    {
        $this->client->request('GET', $this->url(sprintf('/m/%s/%d/file/%s', ContactModule::KEY, $id, self::FIELD)));

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertInstanceOf(StreamedResponse::class, $response, 'nothing builds the body in memory');

        return (string) $this->client->getInternalResponse()->getContent();
    }

    /**
     * Every token this tenant has bytes for.
     *
     * @return list<string>
     */
    private function tokensOnDisk(): array
    {
        /** @var list<string> $tokens */
        $tokens = self::service(TenantSwitcher::class)->runFor($this->tenant, static function (): array {
            $found = [];

            foreach (self::service(AttachmentStore::class)->tokens() as $token => $size) {
                $found[] = (string) $token;
            }

            sort($found);

            return $found;
        });

        return $tokens;
    }

    /** @return array<string, mixed> */
    private function payloadOf(int $id): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): array {
            $shape = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            $json = self::tenantConnection()->fetchOne(
                sprintf('SELECT data FROM %s WHERE id = :id', $shape->getTableName()),
                ['id' => $id],
            );

            $decoded = json_decode(\is_string($json) ? $json : '{}', true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded));

            /* @var array<string, mixed> $decoded */
            return $decoded;
        });
    }

    /**
     * Somebody who may see their own records and nobody else's (§8.4).
     *
     * Made rather than reused, because the point of the test it serves is the
     * *scope*: an administrator would see everything and prove nothing. Granted
     * through a group, like every other permission in this suite, so that what is
     * being exercised is the resolver the application actually uses rather than a
     * row written by hand.
     */
    private function scopedReader(): string
    {
        $email = 'reader@recordfile.test';

        self::service(UserCreator::class)->create($this->tenant, $email, 'Reader', self::PASSWORD, []);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);
            $group = new PermissionGroup('readers', 'Readers');
            $manager->persist($group);
            $manager->persist(PermissionGrant::forGroup(
                $group,
                ContactModule::KEY,
                ModuleAction::View,
                PermissionScope::Own,
            ));

            $user = self::service(UserRepository::class)->findOneByEmail($email);
            \assert($user instanceof User);
            $user->addPermissionGroup($group);

            $manager->flush();
        });

        return $email;
    }

    /**
     * The drift check, run against this tenant only.
     *
     * `--slug`, because the registry is shared with every other class in the run
     * (see `SharesATenant`) and a check over the whole of it would be an
     * assertion about somebody else's fixtures.
     */
    private function check(): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        $tester = new CommandTester((new Application($kernel))->find('tenant:files:check'));
        $tester->execute(['--slug' => self::SLUG]);

        return $tester;
    }

    private function signIn(): void
    {
        $this->signInAs(self::ADMIN);
    }

    private function signInAs(string $email): void
    {
        // Out before in: a client that is already somebody is redirected off the
        // sign-in page, and the form this submits would not be there to find.
        $this->client->request('GET', $this->url('/logout'));

        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
            'password' => self::PASSWORD,
        ]));
    }

    private function url(string $path): string
    {
        return sprintf('https://%s%s', self::HOST, $path);
    }

    /**
     * The tenant's own connection, not the default one: `default_connection` is
     * `control` (§3.1).
     */
    private static function tenantConnection(): Connection
    {
        $registry = self::getContainer()->get('doctrine');
        \assert($registry instanceof \Doctrine\Persistence\ManagerRegistry);

        $connection = $registry->getConnection('tenant');
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

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

namespace App\Tests\Functional\Tenancy;

use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Attachment\AttachmentRefused;
use App\Tenant\Attachment\AttachmentStore;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\Core\Field\StoredFile;

/**
 * One customer cannot reach another's files, and a removed customer leaves none
 * (XIV-115).
 *
 * ## What this class is really asserting
 *
 * §4's isolation between customers is structural for records: the connection a
 * query runs on cannot reach another customer's database, so a forgotten
 * `WHERE` cannot leak anything. **A directory is not that boundary** (§5.30),
 * and the mitigation is that one class resolves the tenant itself and no method
 * on it takes a path or a tenant.
 *
 * So the load-bearing test is {@see self::testOneTenantCannotReachAnothersFile()},
 * and it is written to fail if that derivation is removed. It hands the *second*
 * tenant a token belonging to the first, which is the strongest form of the
 * question: not "does a caller pass the right prefix" but "is there anything a
 * caller could pass". Make `AttachmentStore::home()` return a constant, or take
 * the directory from a parameter, and the second tenant reads the first one's
 * bytes and this test goes red on the assertion rather than on an error.
 *
 * ## Outside DAMA's transaction
 *
 * Databases cannot be created or dropped inside one, and the deprovision half of
 * this class asks what is on a *filesystem* after a real removal, which no
 * transaction was ever going to roll back anyway.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[SkipDatabaseRollback]
final class AttachmentIsolationTest extends KernelTestCase
{
    private const string ALPHA = 'test_files_alpha';
    private const string BETA = 'test_files_beta';

    private TenantProvisioner $provisioner;

    protected function setUp(): void
    {
        // See TenantDeprovisionCommandTest: SymfonyStyle wraps to the terminal,
        // so the width is pinned or the assertions below would be about the
        // window the suite happened to run in.
        putenv('COLUMNS=200');

        self::bootKernel();

        $provisioner = self::getContainer()->get(TenantProvisioner::class);
        \assert($provisioner instanceof TenantProvisioner);
        $this->provisioner = $provisioner;

        $this->removeTenants();

        $this->provisioner->provision(self::ALPHA, 'Alpha', ['files-alpha.localhost']);
        $this->provisioner->provision(self::BETA, 'Beta', ['files-beta.localhost']);
    }

    protected function tearDown(): void
    {
        $this->removeTenants();

        putenv('COLUMNS');

        parent::tearDown();
    }

    /**
     * The second tenant, handed the first one's token, has nothing.
     *
     * The class docblock says why this is the whole ticket in one method. Three
     * assertions, in order of how much they claim: the file is *there* for the
     * tenant that wrote it, it is *not there* for the other one, and reading it
     * from the other one is refused rather than answered with something.
     */
    public function testOneTenantCannotReachAnothersFile(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $beta = $this->tenant(self::BETA);

        $written = $this->store($alpha, 'alpha.pdf', 'the first customer');

        self::assertTrue(
            $this->in($alpha, fn (AttachmentStore $store): bool => $store->has($written)),
            'the tenant that wrote it has it',
        );

        self::assertFalse(
            $this->in($beta, fn (AttachmentStore $store): bool => $store->has($written)),
            'and the other one does not, holding the same token',
        );

        $this->expectException(AttachmentRefused::class);
        $this->in($beta, static fn (AttachmentStore $store) => $store->readStream($written));
    }

    /**
     * Two customers with a file each keep two files each, which is the same claim
     * from the other side.
     *
     * Worth its own method because the assertion above could hold for the wrong
     * reason: a store that wrote nothing at all would also answer "not there".
     * This one says the bytes exist, are the right bytes, and are one apiece.
     */
    public function testEachTenantSeesOnlyItsOwn(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $beta = $this->tenant(self::BETA);

        $first = $this->store($alpha, 'alpha.pdf', 'the first customer');
        $second = $this->store($beta, 'beta.pdf', 'the second customer');

        self::assertSame([$first->token], $this->tokensIn($alpha));
        self::assertSame([$second->token], $this->tokensIn($beta));

        self::assertSame('the first customer', $this->read($alpha, $first));
        self::assertSame('the second customer', $this->read($beta, $second));
    }

    /**
     * The directory is derived from the database name, so the two cannot
     * disagree.
     *
     * §4.1 resolves the database and the role out of the stored DSN and never
     * from the slug, because the DSN is where a tenant's identity lives; this is
     * the same string deciding where the files go, which is what makes "the files
     * went with the database" true by construction rather than by both being
     * remembered.
     */
    public function testTheDirectoryIsTheTenantsDatabaseName(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $written = $this->store($alpha, 'alpha.pdf', 'the first customer');

        $parser = self::getContainer()->get(TenantDsnParser::class);
        \assert($parser instanceof TenantDsnParser);

        $home = sprintf('%s/%s', $this->attachmentsDir(), $parser->databaseName($alpha->getDatabaseDsn()));

        self::assertFileExists(sprintf('%s/%s/%s', $home, substr($written->token, 0, 2), $written->token));
    }

    /**
     * A deprovisioned tenant leaves no files behind, and the other one's are
     * untouched.
     *
     * §4.1 asks for the removal to leave no wreckage, and until this ticket
     * "everything in it" meant rows. Proven rather than asserted: the directory
     * is asked of the filesystem after the command has run.
     */
    public function testDeprovisionTakesTheFilesWithTheDatabase(): void
    {
        $alpha = $this->tenant(self::ALPHA);
        $beta = $this->tenant(self::BETA);

        $this->store($alpha, 'alpha.pdf', 'the first customer');
        $survivor = $this->store($beta, 'beta.pdf', 'the second customer');

        $parser = self::getContainer()->get(TenantDsnParser::class);
        \assert($parser instanceof TenantDsnParser);
        $home = sprintf('%s/%s', $this->attachmentsDir(), $parser->databaseName($alpha->getDatabaseDsn()));

        self::assertDirectoryExists($home);

        $tester = $this->command();
        $tester->execute(['slug' => self::ALPHA, '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($home, 'the tenant left no files behind');

        self::assertSame(
            [$survivor->token],
            $this->tokensIn($beta),
            'and the customer next door still has theirs',
        );
    }

    /**
     * The confirmation names the files beside the record count.
     *
     * §4.1's rule about what an operator is told before they say yes, applied to
     * the part of a deprovision that cannot be typed again. The size is asserted
     * as well as the count, because "412 files" and "412 files, 3.1 GB" are
     * different sentences to somebody wondering whether they still have a backup.
     */
    public function testTheConfirmationNamesTheFilesAndTheirWeight(): void
    {
        $alpha = $this->tenant(self::ALPHA);

        $this->store($alpha, 'one.pdf', str_repeat('x', 2048));
        $this->store($alpha, 'two.pdf', str_repeat('y', 1024));

        $tester = $this->command();
        $tester->execute(['slug' => self::ALPHA, '--force' => true]);

        $output = $tester->getDisplay();

        self::assertStringContainsString('2 files', $output);
        self::assertStringContainsString('3 KB', $output, 'and what they weigh');
    }

    // -- helpers -------------------------------------------------------------

    /** Writes one file for a tenant, through the seam, and gives back the value a record would hold. */
    private function store(Tenant $tenant, string $name, string $contents): StoredFile
    {
        /** @var StoredFile $stored */
        $stored = $this->in($tenant, static function (AttachmentStore $store) use ($name, $contents): StoredFile {
            $stream = fopen('php://temp', 'r+b');
            \assert(\is_resource($stream));
            fwrite($stream, $contents);
            rewind($stream);

            return $store->store($stream, $name, 'application/pdf');
        });

        return $stored;
    }

    private function read(Tenant $tenant, StoredFile $file): string
    {
        /** @var string $contents */
        $contents = $this->in($tenant, static function (AttachmentStore $store) use ($file): string {
            $stream = $store->readStream($file);

            return (string) stream_get_contents($stream);
        });

        return $contents;
    }

    /** @return list<string> */
    private function tokensIn(Tenant $tenant): array
    {
        /** @var list<string> $tokens */
        $tokens = $this->in($tenant, static function (AttachmentStore $store): array {
            $found = [];

            foreach ($store->tokens() as $token => $bytes) {
                $found[] = (string) $token;
            }

            sort($found);

            return $found;
        });

        return $tokens;
    }

    /**
     * One question, asked as one tenant.
     *
     * The store takes no tenant, so the only way to ask it about one is to be it,
     * which is the property under test rather than an inconvenience of the
     * harness.
     */
    private function in(Tenant $tenant, callable $ask): mixed
    {
        $switcher = self::getContainer()->get(TenantSwitcher::class);
        \assert($switcher instanceof TenantSwitcher);

        $store = self::getContainer()->get(AttachmentStore::class);
        \assert($store instanceof AttachmentStore);

        return $switcher->runFor($tenant, static fn (): mixed => $ask($store));
    }

    private function attachmentsDir(): string
    {
        $directory = self::getContainer()->getParameter('kernel.project_dir') . '/var/attachments-test';
        \assert(\is_string($directory));

        return $directory;
    }

    private function tenant(string $slug): Tenant
    {
        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);

        $tenant = $tenants->findOneBySlug($slug);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }

    private function command(): CommandTester
    {
        $kernel = self::$kernel;
        \assert($kernel instanceof KernelInterface);

        return new CommandTester((new Application($kernel))->find('tenant:deprovision'));
    }

    private function removeTenants(): void
    {
        $tenants = self::getContainer()->get(TenantRepository::class);
        \assert($tenants instanceof TenantRepository);

        foreach ([self::ALPHA, self::BETA] as $slug) {
            $tenant = $tenants->findOneBySlug($slug);

            if ($tenant !== null) {
                $this->provisioner->deprovision($tenant);
            }
        }
    }
}

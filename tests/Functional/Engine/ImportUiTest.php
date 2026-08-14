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

use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * The import page: uploading a spreadsheet, checking it, and applying it.
 *
 * The engine has its own tests; this is about the half a person touches — that
 * checking really does leave the database alone, and that a refused file says
 * why rather than throwing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ImportUiTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_import_ui';
    private const string HOST = 'importui.localhost';
    private const string ADMIN = 'admin@importui.test';
    private const string MEMBER = 'member@importui.test';
    private const string PASSWORD = 'import-password';

    private const array HEADER = ['id', 'kind', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'birthday', 'company'];

    private KernelBrowser $client;
    private string $path;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));

        $users = self::service(UserCreator::class);
        $users->create($tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($tenant, self::MEMBER, 'Member', self::PASSWORD, []);

        $this->path = (string) tempnam(sys_get_temp_dir(), 'xivi-import-ui-');
    }

    /** A file can rewrite a module in one click, so it is admin-only for now (§7.5). */
    public function testAnOrdinaryUserCannotReachTheImportPage(): void
    {
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url('/m/contact/import'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testTheImportPageOffersBothButtons(): void
    {
        $this->signIn(self::ADMIN);

        $crawler = $this->client->request('GET', $this->url('/m/contact/import'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('button[value="check"]'));
        self::assertCount(1, $crawler->filter('button[value="apply"]'));
    }

    public function testCheckingReportsWhatWouldHappenAndChangesNothing(): void
    {
        $this->signIn(self::ADMIN);
        $this->file([self::HEADER, ['', 'person', '', 'Ada', 'Lovelace', '', '', '', '']]);

        $crawler = $this->submit('Check');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing was changed', $crawler->filter('main')->text());

        // The list is where it would show up if the check had kept anything.
        $list = $this->client->request('GET', $this->url('/m/contact'));
        self::assertStringNotContainsString('Lovelace', $list->filter('main')->text());
    }

    /**
     * The report calls things what this customer calls them. "1 record, 1 child
     * row" is the engine's vocabulary leaking onto a page that should be talking
     * about contacts and addresses — and at the next customer, about something
     * else again.
     */
    public function testTheReportUsesTheCustomersOwnWords(): void
    {
        $this->signIn(self::ADMIN);
        $this->file(
            [self::HEADER, ['ada', 'person', '', 'Ada', 'Lovelace', '', '', '', '']],
            [['id', 'parent_id', 'street'], ['', 'ada', 'Baker Street 1']],
        );

        // The report itself, not the page: the notes underneath explain what a
        // child sheet is, and are allowed to say so.
        $report = $this->submit('Check')->filter('.alert')->text();

        self::assertStringContainsString('Contacts: 1 added, 0 updated', $report);
        self::assertStringContainsString('Addresses: 1 written, 0 removed', $report);
        self::assertStringNotContainsString('child row', $report);
        self::assertStringNotContainsString('record added', $report);
    }

    public function testImportingAddsTheRecordsAndSaysSo(): void
    {
        $this->signIn(self::ADMIN);
        $this->file([self::HEADER, ['', 'person', '', 'Ada', 'Lovelace', '', '', '', '']]);

        $this->submit('Import');

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('1 added', $text);
        self::assertStringContainsString('Lovelace', $text);
    }

    /** A bad row is a page saying which row, not a stack trace. */
    public function testARefusedFileListsItsProblems(): void
    {
        $this->signIn(self::ADMIN);
        $this->file([self::HEADER, ['', 'person', '', 'Grace', '', '', '', '', '']]);

        $crawler = $this->submit('Import');

        self::assertResponseIsSuccessful();

        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('row 2', $text);
        self::assertStringContainsString('Nothing was changed', $text);
    }

    /** Somebody uploading last week's PDF should be told, not shown a 500. */
    public function testAFileThatIsNotASpreadsheetIsRefusedPolitely(): void
    {
        $this->signIn(self::ADMIN);
        file_put_contents($this->path, 'not a spreadsheet');

        $crawler = $this->submit('Import');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('could not be read', $crawler->filter('main')->text());
    }

    /**
     * @param list<list<mixed>>      $rows
     * @param list<list<mixed>>|null $addresses a second sheet, for the tests that need one
     */
    private function file(array $rows, ?array $addresses = null): void
    {
        $writer = new Writer();
        $writer->openToFile($this->path);
        $writer->getCurrentSheet()->setName('contact');

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        if ($addresses !== null) {
            $writer->addNewSheetAndMakeItCurrent()->setName('addresses');

            foreach ($addresses as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();
    }

    private function submit(string $button): Crawler
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/import'));
        $form = $crawler->selectButton($button)->form();

        $file = $form['file'];
        \assert($file instanceof FileFormField);
        $file->upload($this->path);

        return $this->client->submit($form);
    }

    private function signIn(string $email): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
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

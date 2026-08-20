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
use App\Tenant\Payment\PaymentSettings;
use App\Tenant\Payment\ReferenceType;
use App\Tenant\Security\UserCreator;
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;

/**
 * The QR-bill payment part on a real invoice PDF (XIV-152, §5.28).
 *
 * The payload's line-by-line correctness is InvoiceQrBillTest's job and needs
 * no converter. What only this test can prove is the other half of the
 * pipeline: that a PDF leaving the download route gained the slip as a page,
 * that the QR is actually *drawn* in it, and that the skip paths ship a plain
 * invoice plus the sentence saying why. The PDF is proved by inspecting it,
 * the standing §5.7 rule: a page count and an image XObject, not a `%PDF-`
 * prefix.
 *
 * Skipped rather than faked when Gotenberg is not running, like the logo
 * test's PDF half and for its reason: a fake would prove the seam is called
 * and say nothing about what Chromium draws or what the merge produces.
 *
 * Whether a real banking app scans the result is deliberately left to a human
 * with a phone; no assertion here can stand in for that.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class InvoiceQrBillPdfTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_invoice_qr';
    private const string HOST = 'invoice-qr.localhost';
    private const string EMAIL = 'qr@example.test';
    private const string PASSWORD = 'invoice-qr-password';

    private const string IBAN = 'CH9300762011623852957';

    private KernelBrowser $client;
    private Tenant $tenant;

    /** @var list<string> temporary template files to clear up */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'QR', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];

        parent::tearDown();
    }

    /**
     * The ticket, end to end: a configured profile, an ordinary invoice, and
     * the PDF that comes back has one more page than the letter and a picture
     * in it.
     *
     * The template deliberately places no image, so the one image object in
     * the merged file can only be the QR code. The plain generation is run
     * first and asserted image-free, for the reason the logo test runs its
     * negative half: without it, this passes on a converter that puts a
     * picture in everything.
     */
    public function testAnInvoicePdfGainsThePaymentPartPage(): void
    {
        $this->configureProfile('CHF', self::IBAN);

        $pdf = $this->invoicePdf();

        if ($pdf === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(2, $this->pagesIn($pdf), 'the letter, then the payment part');
        self::assertMatchesRegularExpression('#/Subtype\s*/Image#', $pdf, 'the QR code is drawn, not merely intended');
    }

    /**
     * Any currency the standard does not know produces the invoice *without*
     * a payment part, and the reason is on the next page the person sees.
     * Never an invalid QR: there is nothing here to scan at all.
     */
    public function testAnotherCurrencyShipsThePlainInvoiceAndSaysWhy(): void
    {
        $this->configureProfile('USD', self::IBAN);

        $pdf = $this->invoicePdf();

        if ($pdf === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertSame(1, $this->pagesIn($pdf), 'no page was added');
        self::assertDoesNotMatchRegularExpression('#/Subtype\s*/Image#', $pdf, 'and no QR went along');

        // The flash lands on whatever the person looks at next, which after a
        // download is the record they came from.
        $next = $this->client->request('GET', $this->url('/m/invoice/' . $this->invoice))->filter('main')->text();

        self::assertStringContainsString('USD', $next, 'the sentence names the currency in the way');
        self::assertStringContainsString('without a QR payment part', $next);
    }

    /**
     * The acceptance criterion in its own words: a tenant whose profile lacks
     * required creditor data is told what is missing before a broken payment
     * part ships. Nothing broken ships, and the gaps are named.
     */
    public function testMissingCreditorDataIsNamedAndNothingBrokenShips(): void
    {
        // A currency the standard accepts, and no account for it to pay into.
        $this->configureProfile('CHF', iban: '');

        $pdf = $this->invoicePdf();

        if ($pdf === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertSame(1, $this->pagesIn($pdf));
        self::assertDoesNotMatchRegularExpression('#/Subtype\s*/Image#', $pdf);

        $next = $this->client->request('GET', $this->url('/m/invoice/' . $this->invoice))->filter('main')->text();

        self::assertStringContainsString('The company profile is missing', $next);
        self::assertStringContainsString('IBAN', $next, 'the gap is named by its field label');
    }

    // -- helpers ------------------------------------------------------------

    /** The invoice the last invoicePdf() call generated from. */
    private int $invoice = 0;

    /**
     * The tenant as a creditor, through the same manager the profile page
     * writes with, so what is configured here is what a customer can actually
     * reach.
     */
    private function configureProfile(string $currency, string $iban): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($currency, $iban): void {
            $manager = self::service(TenantProfileManager::class);

            $manager->apply('Muster AG', $currency, 'CH');
            $manager->applyPayment(PaymentSettings::from(
                $iban,
                ReferenceType::Scor,
                'Musterstrasse',
                '7',
                '8000',
                'Zürich',
            ));
        });
    }

    /**
     * An order, an invoice for it, a one-marker template, and the PDF from
     * the same download route a person clicks.
     *
     * @return string|null null when the converter is not running, the shape
     *                     the logo test settled for this suite
     */
    private function invoicePdf(): ?string
    {
        $company = $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Acme AG'],
            variant: 'company',
        ));

        $order = $this->savedId($this->saveRecord(
            OrderModule::KEY,
            ['contact' => (string) $company, 'ordered_on' => '2026-08-15', 'status' => OrderModule::DRAFT],
            [OrderModule::LINES => [self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Consulting',
                'quantity' => '2',
                'unit_price' => '150.00',
            ])]],
        ));

        $this->invoice = $this->savedId($this->saveRecord(
            InvoiceModule::KEY,
            [
                InvoiceModule::ORDER => (string) $order,
                InvoiceModule::CONTACT => (string) $company,
                InvoiceModule::ISSUED_ON => '2026-08-15',
                InvoiceModule::STATUS => InvoiceModule::DRAFT,
            ],
            [InvoiceModule::LINES => [self::row([
                InvoiceModule::KIND => InvoiceModule::CUSTOM_LINE,
                InvoiceModule::DESCRIPTION => 'Consulting',
                InvoiceModule::QUANTITY => '2',
                InvoiceModule::UNIT_PRICE => '150.00',
            ])]],
        ));

        $template = $this->uploadTemplate();

        $this->client->request('GET', $this->url(sprintf(
            '/m/invoice/%d/document/download?template=%d&format=pdf',
            $this->invoice,
            $template,
        )));

        if ($this->client->getResponse()->isRedirection()) {
            return null;
        }

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * Page objects in the bytes, the count the reader's page list shows.
     *
     * `/Type /Page` dictionaries cannot live inside compressed object streams
     * in the files both converters here produce, which is what makes counting
     * them in the raw bytes honest; `[^s]` keeps the single `/Type /Pages`
     * tree node out of the count.
     */
    private function pagesIn(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    private function uploadTemplate(): int
    {
        $path = sys_get_temp_dir() . '/' . uniqid('xivi-qr-template-', true) . '.docx';
        file_put_contents($path, self::aDocx());
        $this->files[] = $path;

        $crawler = $this->client->request('GET', $this->url('/m/invoice/templates'));
        $form = $crawler->selectButton('Upload a template')->form(['name' => 'Invoice']);
        self::fileField($form)->upload($path);

        $this->client->submit($form);
        $this->client->followRedirect();

        $actions = $this->client->getCrawler()
            ->filter('form[action*="/templates/"]')
            ->each(static fn ($node): string => (string) $node->attr('action'));

        self::assertNotEmpty($actions, 'the template was accepted');

        return (int) preg_replace('#\D#', '', str_replace('/delete', '', (string) end($actions)));
    }

    /** The one file input on the upload form, typed. */
    private static function fileField(Form $form): FileFormField
    {
        $field = $form['template'];
        \assert($field instanceof FileFormField);

        return $field;
    }

    /**
     * The smallest .docx that is a document: one paragraph, one marker. No
     * images anywhere, which is what lets the tests above treat "an image in
     * the PDF" as "the QR code".
     */
    private static function aDocx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-qr-docx-') . '.docx';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t xml:space="preserve">Invoice [number]</w:t></w:r></w:p></w:body>'
            . '</w:document>');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::EMAIL,
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

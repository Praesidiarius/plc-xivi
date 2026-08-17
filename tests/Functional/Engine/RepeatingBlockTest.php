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

use App\ControlPlane\Entity\Tenant;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Document\DocumentFormat;
use Xivi\Order\OrderModule;

/**
 * A table row that draws itself once per line (XIV-17).
 *
 * The templates are built by hand here rather than uploaded from a fixture,
 * because the interesting cases are all about *where the markers sit* — one row
 * or three, inside a table or outside it, whole or cut in half by Word.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RepeatingBlockTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_blocks';
    private const string HOST = 'blocks.localhost';
    private const string EMAIL = 'blocks@example.test';
    private const string PASSWORD = 'blocks-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(\Xivi\Core\Module\ModuleInstaller::class);
            $registry = self::service(\Xivi\Core\Module\ModuleRegistry::class);

            // Article too, so the form offers a blank row of all four kinds —
            // without it the article line is not offered at all (XIV-23) and the
            // row indices below would shift.
            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Blocks', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /** One row in the template, one row per line in the document, in order. */
    public function testARowWithACollectionMarkerRepeatsPerLine(): void
    {
        $template = $this->upload('Lines', [['[lines.description]', '[lines.line_total]']]);

        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::CUSTOM_LINE, ['description' => 'Travel', 'quantity' => '1', 'unit_price' => '80.00']],
        ]);

        $text = $this->generate($template, $order);

        self::assertStringContainsString('Consulting', $text);
        self::assertStringContainsString('Travel', $text);
        self::assertLessThan(
            strpos($text, 'Travel') ?: 0,
            strpos($text, 'Consulting') ?: 0,
            'in the order the lines are in',
        );
    }

    /** The values are the ones the field type shows, not the ones stored. */
    public function testTheValuesAreFormattedLikeEverywhereElse(): void
    {
        $template = $this->upload('Lines', [['[lines.description]', '[lines.line_total]']]);

        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
        ]);

        self::assertMatchesRegularExpression('/300[.,]00/', $this->generate($template, $order), 'as money');
    }

    /**
     * The decision this ticket had to make: a template that lays out a row per
     * kind gets a comment line without the money columns beside it.
     */
    public function testARowPerKindDrawsEachKindItsOwnWay(): void
    {
        $template = $this->upload('Lines', [
            ['[lines:custom.description]', 'Qty [lines:custom.quantity]', '[lines:custom.line_total]'],
            ['NOTE [lines:comment.description]', '', ''],
        ]);

        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::COMMENT_LINE, ['description' => 'Everything below is optional']],
            [OrderModule::CUSTOM_LINE, ['description' => 'Travel', 'quantity' => '1', 'unit_price' => '80.00']],
        ]);

        $text = $this->generate($template, $order);

        self::assertStringContainsString('NOTE Everything below is optional', $text, 'its own row');
        self::assertStringNotContainsString('Qty Everything', $text, 'and not the priced one');
        // Interleaved rather than sorted by kind, because a comment is *between*
        // two lines and means nothing anywhere else.
        self::assertLessThan(strpos($text, 'Travel') ?: 0, strpos($text, 'NOTE') ?: 0);
        self::assertLessThan(strpos($text, 'NOTE') ?: 0, strpos($text, 'Consulting') ?: 0);
    }

    /** A kind the template laid out no row for is not drawn at all. */
    public function testALineWithNoRowToDrawItIsLeftOut(): void
    {
        $template = $this->upload('Lines', [['[lines:custom.description]', '[lines:custom.line_total]']]);

        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::COMMENT_LINE, ['description' => 'Not wanted on this document']],
        ]);

        $text = $this->generate($template, $order);

        self::assertStringContainsString('Consulting', $text);
        self::assertStringNotContainsString('Not wanted on this document', $text);
    }

    /** An order with no lines leaves the table's heading and nothing else. */
    public function testAnEmptyCollectionLeavesNoStrayRow(): void
    {
        $template = $this->upload('Lines', [['[lines.description]', '[lines.line_total]']], 'Position');

        $text = $this->generate($template, $this->anOrder([]));

        self::assertStringContainsString('Position', $text, 'the heading is still there');
        self::assertStringNotContainsString('[lines.', $text, 'and no marker printed itself');
    }

    /** The record's own markers sit outside the table and are written once. */
    public function testTheRecordsOwnValuesAreWrittenOnce(): void
    {
        $template = $this->upload(
            'Lines',
            [['[lines.description]', '[lines.line_total]']],
            'Position',
            'Total: [gross_total] for [number]',
        );

        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::CUSTOM_LINE, ['description' => 'Travel', 'quantity' => '1', 'unit_price' => '80.00']],
        ]);

        $text = $this->generate($template, $order);

        self::assertSame(1, substr_count($text, 'Total:'), 'once, however many lines there are');
        self::assertMatchesRegularExpression('/380[.,]00/', $text);
    }

    /** A reference field prints the record it points at. */
    public function testAReferenceFieldPrintsTheRecordItPointsAt(): void
    {
        $template = $this->upload(
            'Lines',
            [['[lines.description]', '[lines.line_total]']],
            'Position',
            'For [contact] on [ordered_on]',
        );

        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        $text = $this->generate($template, $order);

        self::assertStringNotContainsString('[contact]', $text, 'the marker is gone');
        self::assertStringContainsString('Acme AG', $text, 'and the customer is there');
    }

    /** A second collection in the same document draws its own rows. */
    public function testTwoCollectionsEachDrawTheirOwn(): void
    {
        $template = $this->upload('Both', [
            ['[lines.description]', '[lines.line_total]'],
        ], 'Position', null, [
            ['VAT [taxes.rate]', '[taxes.amount]'],
        ]);

        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, [
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '8.10',
            ]],
        ]);

        $text = $this->generate($template, $order);

        self::assertStringContainsString('Consulting', $text);
        self::assertMatchesRegularExpression('/VAT 8[.,]10/', $text, 'the VAT table too');
    }

    /**
     * Word cuts a placeholder somebody typed in one go across several runs, and
     * a marker in a table cell is no different.
     */
    public function testAMarkerWordHasCutInHalfStillWorks(): void
    {
        $template = $this->upload('Split', [[['[lines.', 'description]'], '[lines.line_total]']]);

        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        self::assertStringContainsString('Consulting', $this->generate($template, $order));
    }

    /**
     * And a PDF is the same document.
     *
     * What is in it is asserted on the .docx above, because that is the file the
     * PDF is made from — the rows are multiplied before either format exists.
     */
    public function testThePdfIsMadeFromTheSameExpandedDocument(): void
    {
        $template = $this->upload('Lines', [['[lines.description]', '[lines.line_total]']]);

        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        $this->client->request('GET', $this->url(sprintf(
            '/m/order/%d/document/download?template=%d&format=%s',
            $order,
            $template,
            DocumentFormat::Pdf->value,
        )));

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    /** The reference list says what the collection's markers are called. */
    public function testThePageListsTheCollectionsMarkers(): void
    {
        $page = $this->client->request('GET', $this->url('/m/order/templates'))->filter('main')->text();

        self::assertStringContainsString('[lines:article.description]', $page);
        self::assertStringContainsString('[taxes.rate]', $page, 'a collection with no kinds needs none');
    }

    /**
     * And a template that uses them is told nothing (XIV-25).
     *
     * A collection marker is a marker even though it is never substituted where
     * the flat ones are — the row it sits in is multiplied first and the copies
     * carry values — and the per-kind form is a marker too. A review that only
     * knew the flat vocabulary would report every invoice template in the
     * installation, which is the fastest way to teach somebody to ignore it.
     */
    public function testACollectionsMarkersAreKnownToTheReview(): void
    {
        $this->upload(
            'Lines',
            [['[lines:article.description]', '[lines:article.line_total]'], ['[lines.description]']],
            paragraph: 'Order [number] on [today]: [gross_total], [taxes.rate].',
        );

        self::assertStringNotContainsString(
            'printed just as',
            $this->client->getCrawler()->filter('main')->text(),
        );
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Uploads a template built around a table, and returns its id.
     *
     * @param list<array<int, string|list<string>>> $rows   one template row per entry, its
     *                                                      cells inside; a cell given as a
     *                                                      list is split across runs the way
     *                                                      Word does
     * @param list<array<int, string>>              $second a second table, for a document
     *                                                      that lists two collections
     */
    private function upload(
        string $name,
        array $rows,
        ?string $heading = null,
        ?string $paragraph = null,
        array $second = [],
    ): int {
        $path = self::aDocx($rows, $heading, $paragraph, $second);

        $crawler = $this->client->request('GET', $this->url('/m/order/templates'));
        $form = $crawler->selectButton('Upload a template')->form(['name' => $name]);
        self::fileField($form)->upload($path);
        $this->client->submit($form);
        $this->client->followRedirect();

        @unlink($path);

        $ids = $this->client->getCrawler()
            ->filter('form[action*="/templates/"]')
            ->each(static fn ($node): string => (string) $node->attr('action'));

        self::assertNotEmpty($ids, 'the template was accepted');

        return (int) preg_replace('#\D#', '', str_replace('/delete', '', (string) end($ids)));
    }

    /** The words of the generated .docx, with the XML taken off. */
    private function generate(int $template, int $order): string
    {
        $this->client->request('GET', $this->url(sprintf(
            '/m/order/%d/document/download?template=%d&format=%s',
            $order,
            $template,
            DocumentFormat::Docx->value,
        )));

        self::assertResponseIsSuccessful();

        $path = tempnam(sys_get_temp_dir(), 'xivi-generated-') . '.docx';
        file_put_contents($path, (string) $this->client->getResponse()->getContent());

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true, 'the document opens');

        // Paragraph and cell ends become spaces, so words from neighbouring
        // cells do not run into each other and read as one.
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            strip_tags(str_replace(['</w:p>', '</w:tc>', '</w:tr>'], ' ', $xml)),
        ));
    }

    /**
     * A .docx with a table in it: three parts is all Word needs to open a file.
     *
     * @param list<array<int, string|list<string>>> $rows
     * @param list<array<int, string>>              $second
     */
    private static function aDocx(array $rows, ?string $heading, ?string $paragraph, array $second): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-test-template-') . '.docx';

        $body = '';

        if ($paragraph !== null) {
            $body .= self::paragraph($paragraph);
        }

        $body .= self::table($rows, $heading);

        if ($second !== []) {
            $body .= self::table(array_map(static fn (array $row): array => $row, $second), null);
        }

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
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $body
            . '</w:body></w:document>');
        $zip->close();

        return $path;
    }

    /** @param list<array<int, string|list<string>>> $rows */
    private static function table(array $rows, ?string $heading): string
    {
        $xml = '<w:tbl>';

        if ($heading !== null) {
            $xml .= '<w:tr><w:tc>' . self::paragraph($heading) . '</w:tc></w:tr>';
        }

        foreach ($rows as $cells) {
            $xml .= '<w:tr>';

            foreach ($cells as $cell) {
                // A cell given as a list is one whose text Word has split across
                // runs, which is what happens to anything typed by hand.
                $runs = \is_array($cell) ? $cell : [$cell];
                $xml .= '<w:tc><w:p>';

                foreach ($runs as $run) {
                    $xml .= '<w:r><w:t xml:space="preserve">' . htmlspecialchars($run, \ENT_XML1) . '</w:t></w:r>';
                }

                $xml .= '</w:p></w:tc>';
            }

            $xml .= '</w:tr>';
        }

        return $xml . '</w:tbl>';
    }

    private static function paragraph(string $text): string
    {
        return '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($text, \ENT_XML1) . '</w:t></w:r></w:p>';
    }

    private static function fileField(Form $form): FileFormField
    {
        $field = $form['template'];
        \assert($field instanceof FileFormField);

        return $field;
    }

    /** @param list<array{0: string, 1: array<string, string>}> $lines */
    private function anOrder(array $lines): int
    {
        // Every line in one save (XIV-33): a component takes the whole
        // collection, where the old form had to be given a row at a time.
        $rows = [];

        foreach ($lines as [$kind, $values]) {
            $rows[] = self::row([OrderModule::KIND => $kind, ...$values]);
        }

        return $this->savedId($this->saveRecord(
            OrderModule::KEY,
            [
                'contact' => (string) $this->aCompany(),
                'ordered_on' => '2026-08-15',
                'status' => OrderModule::DRAFT,
            ],
            $rows === [] ? [] : [OrderModule::LINES => $rows],
        ));
    }

    private function aCompany(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Acme AG'],
            variant: 'company',
        ));
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

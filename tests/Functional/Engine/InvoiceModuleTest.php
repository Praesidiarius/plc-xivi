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
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;

/**
 * Invoicing an order, once or twice (XIV-19).
 *
 * The fourth module and the measure of the six tickets before it: it names an
 * order, carries a number, a lifecycle, totals and a VAT table, and is made out
 * of another module's record — and it is a declaration and a translation file.
 * Everything asserted here goes through the same generic controller and form the
 * contact module uses.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class InvoiceModuleTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_invoices';
    private const string HOST = 'invoices.localhost';
    private const string EMAIL = 'invoices@example.test';
    private const string PASSWORD = 'invoices-password';

    private KernelBrowser $client;
    private Tenant $tenant;

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

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Invoices', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /** An invoice cannot be installed without an order module to invoice from. */
    public function testTheModuleNeedsAnOrderModule(): void
    {
        $module = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => [...self::service(ModuleRegistry::class)->get(InvoiceModule::KEY)->requires],
        );

        self::assertSame([OrderModule::KEY, ContactModule::KEY], $module);
    }

    /** The order's page offers to invoice it, and the button leads to a filled-in form. */
    public function testAnOrderOffersToBeInvoiced(): void
    {
        $order = $this->anOrderWith([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
        ]);

        $page = $this->client->request('GET', $this->url('/m/order/' . $order));

        self::assertStringContainsString('Invoice what is left', $page->filter('main')->text());

        $this->client->click($page->selectLink('Invoice what is left')->link());

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Consulting',
            (string) $this->client->getCrawler()
                ->filter('[name="module_record[collections][lines][0][fields][description]"]')->attr('value'),
            'the order line is already on the form',
        );
    }

    /** The invoice keeps its own copy of what was ordered, and names the order. */
    public function testAnInvoiceCarriesItsOwnLines(): void
    {
        $order = $this->anOrderWith([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
        ]);

        $invoice = $this->invoice($order);
        $lines = $this->linesOf($invoice);

        self::assertCount(1, $lines);
        self::assertSame('Consulting', $lines[0]->get(InvoiceModule::DESCRIPTION));
        self::assertSame('150.00', $lines[0]->get(InvoiceModule::UNIT_PRICE));
        self::assertSame($order, (int) $this->invoiceRecord($invoice)->get(InvoiceModule::ORDER));
    }

    /** And it stops following the order the moment it exists. */
    public function testAnInvoiceDoesNotFollowTheOrderAfterwards(): void
    {
        $order = $this->anOrderWith([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
        ]);

        $invoice = $this->invoice($order);

        // The order is renegotiated after the invoice went out.
        $values = self::formValuesOn($this->client->request('GET', $this->url('/m/order/' . $order . '/edit')));
        $values['collections'][OrderModule::LINES][0]['fields']['unit_price'] = '99.00';

        $this->saveRecord(OrderModule::KEY, $values['fields'], $values['collections'], $order);

        self::assertSame('150.00', $this->linesOf($invoice)[0]->get(InvoiceModule::UNIT_PRICE));
    }

    /** Comment and subtotal lines come along, in the order they were in. */
    public function testEveryKindOfLineComesAlongInOrder(): void
    {
        $order = $this->anOrderWith([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::COMMENT_LINE, ['description' => 'Delivered together']],
            [OrderModule::CUSTOM_LINE, ['description' => 'Travel', 'quantity' => '1', 'unit_price' => '80.00']],
            [OrderModule::SUBTOTAL_LINE, ['description' => 'Services']],
        ]);

        $kinds = array_map(
            static fn (Record $line): ?string => $line->get(InvoiceModule::KIND),
            $this->linesOf($this->invoice($order)),
        );

        self::assertSame(
            [
                InvoiceModule::CUSTOM_LINE,
                InvoiceModule::COMMENT_LINE,
                InvoiceModule::CUSTOM_LINE,
                InvoiceModule::SUBTOTAL_LINE,
            ],
            $kinds,
        );
    }

    /**
     * A seeded subtotal is worked out from the invoice's own lines, never copied
     * — on an invoice for half the order it would be the most convincing wrong
     * number in the system.
     */
    public function testASeededSubtotalIsRecomputed(): void
    {
        $order = $this->anOrderWith([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::CUSTOM_LINE, ['description' => 'Travel', 'quantity' => '1', 'unit_price' => '80.00']],
            [OrderModule::SUBTOTAL_LINE, ['description' => 'Services']],
        ]);

        self::assertSame('380.00', $this->linesOf($order, OrderModule::KEY)[2]->get(OrderModule::LINE_TOTAL));

        // Invoice it, dropping the travel line — the subtotal must not still
        // claim 380.
        $invoice = $this->invoice($order, [
            [1, 'description', ''],
            [1, 'quantity', ''],
            [1, 'unit_price', ''],
        ]);

        $lines = $this->linesOf($invoice);

        self::assertCount(2, $lines, 'consulting and the subtotal');
        self::assertSame('300.00', $lines[1]->get(InvoiceModule::LINE_TOTAL), 'restated, not copied');
    }

    /** Its totals are its own, and come out of the same arithmetic an order's do. */
    public function testAnInvoiceHasItsOwnTotalsAndVatTable(): void
    {
        $order = $this->anOrderWith([
            [OrderModule::CUSTOM_LINE, [
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '8.10',
            ]],
        ]);

        $invoice = $this->invoice($order);
        $record = $this->invoiceRecord($invoice);

        self::assertSame('100.00', $record->get(InvoiceModule::NET_TOTAL));
        self::assertSame('8.10', $record->get(InvoiceModule::TAX_TOTAL));
        self::assertSame('108.10', $record->get(InvoiceModule::GROSS_TOTAL));
        self::assertCount(1, $this->linesOf($invoice, InvoiceModule::KEY, InvoiceModule::TAXES));
    }

    /** It carries a number of its own, from its own sequence. */
    public function testAnInvoiceIsNumbered(): void
    {
        $order = $this->anOrderWith([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
        ]);

        self::assertSame(
            sprintf('INV-%s-0001', date('Y')),
            $this->invoiceRecord($this->invoice($order))->get(InvoiceModule::NUMBER),
        );
    }

    /** A sent invoice is a document: no way back to draft, and no way to edit it. */
    public function testASentInvoiceCannotBeEditedBackIntoADraft(): void
    {
        $order = $this->anOrderWith([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
        ]);

        $invoice = $this->invoice($order);

        self::assertSame(['Mark sent', 'Cancel'], $this->transitionsOn($invoice));

        $this->transition($invoice, 'send');

        self::assertSame(['Mark paid', 'Cancel'], $this->transitionsOn($invoice), 'and never back to draft');

        // Not a 403: the route says why and sends you back to the record, which
        // is the same courtesy the button's absence already paid (§5.8).
        $this->client->request('GET', $this->url('/m/invoice/' . $invoice . '/edit'));
        self::assertResponseRedirects();

        $page = $this->client->followRedirect()->filter('main')->text();
        self::assertStringNotContainsString('Save', $page, 'a document the customer has is not edited');
    }

    /**
     * The point of an invoice carrying its own lines: an order can be invoiced
     * in parts, and the second invoice is offered only what is left.
     */
    public function testAnOrderCanBeInvoicedTwice(): void
    {
        $order = $this->anOrderWith([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '10', 'unit_price' => '100.00']],
        ]);

        // A deposit: four of the ten.
        $this->invoice($order, [[0, 'quantity', '4']]);

        $page = $this->client->request('GET', $this->url('/m/order/' . $order))->filter('main')->text();
        self::assertStringContainsString('6.00 left', $page, 'the order says what is still to invoice');

        $second = $this->invoice($order);

        self::assertSame('6.00', $this->linesOf($second)[0]->get(InvoiceModule::QUANTITY), 'the rest, offered');
        self::assertSame('600.00', $this->invoiceRecord($second)->get(InvoiceModule::NET_TOTAL));
    }

    /** A line with nothing left is not offered again. */
    public function testAFullyInvoicedLineIsNotOfferedAgain(): void
    {
        $order = $this->anOrderWith([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::CUSTOM_LINE, ['description' => 'Travel', 'quantity' => '1', 'unit_price' => '80.00']],
        ]);

        // Invoice the consulting in full and nothing of the travel.
        $this->invoice($order, [
            [1, 'description', ''],
            [1, 'quantity', ''],
            [1, 'unit_price', ''],
        ]);

        $this->client->click(
            $this->client->request('GET', $this->url('/m/order/' . $order))
                ->selectLink('Invoice what is left')
                ->link(),
        );

        $descriptions = $this->client->getCrawler()
            ->filter('[name$="[fields][description]"]')
            ->each(static fn (Crawler $node): string => (string) $node->attr('value'));

        self::assertContains('Travel', $descriptions);
        self::assertNotContains('Consulting', $descriptions, 'nothing of it is left');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Makes an invoice from an order through the button and the form, and
     * returns its id.
     *
     * @param list<array{0: int, 1: string, 2: string}> $adjust row index, field and value
     *                                                          somebody changes before saving
     */
    private function invoice(int $order, array $adjust = []): int
    {
        // Through the button, so the seeding is exercised: the page comes back
        // already filled in, and what it is showing is what a save would send.
        $page = $this->client->request('GET', $this->url('/m/order/' . $order));
        $seeded = $this->client->click($page->selectLink('Invoice what is left')->link());

        $values = self::formValuesOn($seeded);

        // What the seeding put there, before anybody typed: the order and the
        // customer. Taken now because the component is later mounted with them
        // and only with them — handing it the whole edited form would give a
        // date field a string where it wants a date.
        $seedFields = array_filter($values['fields'], static fn (mixed $v): bool => $v !== '');

        $values['fields']['issued_on'] = '2026-08-15';
        $values['fields']['status'] = InvoiceModule::DRAFT;

        foreach ($adjust as [$index, $field, $value]) {
            $values['collections'][InvoiceModule::LINES][$index]['fields'][$field] = $value;
        }

        $rows = $values['collections'] ?? [];

        return $this->savedId($this->saveRecord(
            InvoiceModule::KEY,
            $values['fields'],
            $rows,
            // Empty controls are values nobody set, and the seeding that really
            // produced this page would have passed null rather than "" — which
            // a decimal field refuses.
            seeded: array_map(
                static fn (array $lines): array => array_values(array_map(
                    static fn (array $row): array => array_filter(
                        (array) $row['fields'],
                        static fn (mixed $v): bool => $v !== '',
                    ),
                    $lines,
                )),
                $rows,
            ),
            seededFields: $seedFields,
        ));
    }

    /** @param list<array{0: string, 1: array<string, string>}> $lines */
    private function anOrderWith(array $lines): int
    {
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

    /** @return list<Record> */
    private function linesOf(int $id, string $module = InvoiceModule::KEY, string $collection = InvoiceModule::LINES): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function () use ($id, $module, $collection): array {
                $rows = self::service(MetadataRepository::class)->get($module)->getCollection($collection);
                self::assertNotNull($rows);

                return self::service(RecordRepository::class)->findChildren($rows, $id);
            },
        );
    }

    private function invoiceRecord(int $id): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): Record {
            $module = self::service(MetadataRepository::class)->get(InvoiceModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $id);
            self::assertInstanceOf(Record::class, $record);

            return $record;
        });
    }

    /** @return list<string> */
    private function transitionsOn(int $invoice): array
    {
        return $this->client->request('GET', $this->url('/m/invoice/' . $invoice))
            ->filter('form[action*="/transition/"] button')
            ->each(static fn (Crawler $node): string => trim($node->text()));
    }

    private function transition(int $invoice, string $name): void
    {
        $tokens = $this->client->request('GET', $this->url('/m/invoice/' . $invoice))
            ->filter('input[name="_token"]')
            ->each(static fn (Crawler $node): string => (string) $node->attr('value'));

        $this->client->request(
            'POST',
            $this->url(sprintf('/m/invoice/%d/transition/%s', $invoice, $name)),
            ['_token' => $tokens[0] ?? 'no-token'],
        );
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

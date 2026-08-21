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
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleInstallOrder;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;
use Xivi\Voucher\VoucherModule;

/**
 * Billing a discounted order in parts ([XIV-147]).
 *
 * [XIV-104] made a voucher a **line** on the order, and §5.12 copies lines onto
 * the invoice made from it. Those two sentences met badly. A discount line has
 * quantity 1, the drawdown that decides what a second invoice is offered counts
 * quantity, and so the first invoice took the whole voucher and every later one
 * took none. The total across the invoices was right and each document was
 * wrong, which is the worse of the two ways to be wrong about money: nobody
 * checks a figure that adds up.
 *
 * What is asserted here is the rule §5.12 now states. **Each invoice takes the
 * share of the discount that matches what it bills, and the invoice that closes
 * the order out takes the balance**, so the rappen a three-way split leaves over
 * lands somewhere stated rather than wherever the last division happened to put
 * it. The figures are chosen so that it does not divide: a hundred francs over
 * three hundred, in thirds.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PartialInvoiceDiscountTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_partial_discount';
    private const string HOST = 'partialdiscount.localhost';
    private const string EMAIL = 'billing@example.test';
    private const string PASSWORD = 'partial-discount-password';

    /** The three rates this country has, which is what makes a split uneven. */
    private const string STANDARD = '8.10';
    private const string REDUCED = '2.60';
    private const string LODGING = '3.80';

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

            // Through the install order, for `OrderVoucherTest`'s reason: an
            // order installed before vouchers has no voucher field on it at all,
            // so the whole of this class would test nothing.
            $keys = [ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY, VoucherModule::KEY];

            foreach (self::service(ModuleInstallOrder::class)->of($keys) as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Billing', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /**
     * **The defect itself**, in the shape the ticket wrote it down: a thousand
     * francs, a hundred-franc voucher, billed in halves.
     *
     * Before this was fixed the first invoice read 500.00 less 100.00 and the
     * second read 500.00 less nothing at all.
     */
    public function testAHalfInvoiceTakesHalfOfTheDiscount(): void
    {
        $order = $this->aDiscountedOrder('GIVE-100', '100.00', '10', '100.00');

        $first = $this->invoiceOf($order, [[0, 'quantity', '5']]);

        self::assertSame(
            [['custom', 'Consulting', '500.00'], ['discount', 'GIVE-100', '-50.00']],
            $this->billedOn($first),
            'half the order, and half the voucher with it',
        );
        self::assertSame('450.00', $this->invoiceRecord($first)->get(InvoiceModule::NET_TOTAL));

        $second = $this->invoiceOf($order);

        self::assertSame(
            [['custom', 'Consulting', '500.00'], ['discount', 'GIVE-100', '-50.00']],
            $this->billedOn($second),
            'and the other half reads exactly like the first',
        );
        self::assertSame('450.00', $this->invoiceRecord($second)->get(InvoiceModule::NET_TOTAL));
    }

    /**
     * **What the split must never do is lose or invent a rappen**, which is
     * exactly what an even division of it would do.
     *
     * Three invoices over a hundred francs is 33.33 three times, which is 99.99.
     * The invoice that finishes the order takes what is left rather than its own
     * third, which is XIV-116's remainder rule one document further out.
     */
    public function testTheDiscountAcrossEveryInvoiceComesToTheOrdersOwn(): void
    {
        $order = $this->aDiscountedOrder('SPLIT-100', '100.00', '3', '100.00');

        $invoices = [
            $this->invoiceOf($order, [[0, 'quantity', '1']]),
            $this->invoiceOf($order, [[0, 'quantity', '1']]),
            $this->invoiceOf($order),
        ];

        $taken = array_map(fn (int $invoice): string => $this->discountOn($invoice), $invoices);

        self::assertSame(['-33.33', '-33.34', '-33.33'], $taken, 'each its share, and the last the balance');

        self::assertSame(-100.0, array_sum(array_map(floatval(...), $taken)), 'they come to the voucher exactly');

        self::assertSame(
            200.0,
            array_sum(array_map(
                fn (int $invoice): float => (float) (string) $this->invoiceRecord($invoice)->get(InvoiceModule::NET_TOTAL),
                $invoices,
            )),
            'and what was billed comes to what the order says it comes to',
        );
    }

    /** An order billed in one go is unchanged by any of this. */
    public function testAnInvoiceForTheWholeOrderTakesTheWholeDiscount(): void
    {
        $order = $this->aDiscountedOrder('WHOLE-100', '100.00', '10', '100.00');

        $invoice = $this->invoiceOf($order);

        self::assertSame(
            [['custom', 'Consulting', '1000.00'], ['discount', 'WHOLE-100', '-100.00']],
            $this->billedOn($invoice),
        );
        self::assertSame('900.00', $this->invoiceRecord($invoice)->get(InvoiceModule::NET_TOTAL));
    }

    /** And nothing here restates the order, which is the ticket's own condition. */
    public function testTheOrderIsNotRestatedByBillingPartOfIt(): void
    {
        $order = $this->aDiscountedOrder('KEEP-100', '100.00', '10', '100.00');

        $before = $this->orderRecord($order);

        $this->invoiceOf($order, [[0, 'quantity', '5']]);

        $after = $this->orderRecord($order);

        self::assertSame('900.00', $after->get(OrderModule::NET_TOTAL));
        self::assertSame($before->get(OrderModule::GROSS_TOTAL), $after->get(OrderModule::GROSS_TOTAL));
        self::assertSame(
            [['custom', 'Consulting', '1000.00'], ['discount', 'KEEP-100', '-100.00']],
            $this->linesOn($order, OrderModule::KEY, OrderModule::LINES, OrderModule::KIND, 'description', OrderModule::LINE_TOTAL),
            'and it still says what was agreed',
        );
    }

    /**
     * **The discount line is the engine's on the invoice too**, which is the half
     * of this ticket that is about protection rather than about arithmetic.
     *
     * Tested the way [XIV-104] tested the order: a save submitting a different
     * price for the generated row, with that row's own id, which is the request a
     * browser with a modified page would send.
     */
    public function testTheDiscountLineOnAnInvoiceCannotBeEditedByHand(): void
    {
        $order = $this->aDiscountedOrder('FIXED-100', '100.00', '10', '100.00');
        $invoice = $this->invoiceOf($order, [[0, 'quantity', '5']]);

        $rows = $this->rowsOf($invoice);

        $this->saveInvoice($invoice, [
            self::row([
                InvoiceModule::KIND => InvoiceModule::CUSTOM_LINE,
                InvoiceModule::DESCRIPTION => 'Consulting',
                InvoiceModule::QUANTITY => '5',
                InvoiceModule::UNIT_PRICE => '100.00',
                InvoiceModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[0]->id),
            self::row([
                InvoiceModule::KIND => InvoiceModule::DISCOUNT_LINE,
                InvoiceModule::DESCRIPTION => 'A better deal',
                InvoiceModule::QUANTITY => '1',
                InvoiceModule::UNIT_PRICE => '-500.00',
                InvoiceModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[1]->id),
        ]);

        self::assertSame(
            [['custom', 'Consulting', '500.00'], ['discount', 'FIXED-100', '-50.00']],
            $this->billedOn($invoice),
            'what was typed over it is gone; the order still decides',
        );
    }

    /**
     * **Saving a bill again does not shrink what it was given**, which is the
     * case the whole record-id plumbing exists for.
     *
     * The share is what is left over the other bills, so a bill that finds
     * *itself* among the others sees a voucher already spent and an order already
     * billed, and hands the document nothing. It is not hypothetical: the second
     * of two halves closes the order out, so this is what happens the first time
     * anybody corrects a draft.
     */
    public function testABillSavedAgainKeepsTheShareItWasGiven(): void
    {
        $order = $this->aDiscountedOrder('AGAIN-100', '100.00', '10', '100.00');

        $this->invoiceOf($order, [[0, 'quantity', '5']]);
        $second = $this->invoiceOf($order);

        self::assertSame('-50.00', $this->discountOn($second), 'the half it was billed for');

        $rows = $this->rowsOf($second);

        $this->saveInvoice($second, [self::row([
            InvoiceModule::KIND => InvoiceModule::CUSTOM_LINE,
            InvoiceModule::DESCRIPTION => 'Consulting',
            InvoiceModule::QUANTITY => '5',
            InvoiceModule::UNIT_PRICE => '100.00',
            InvoiceModule::TAX_RATE => self::STANDARD,
        ], (int) $rows[0]->id)]);

        self::assertSame('-50.00', $this->discountOn($second), 'and it still has it');
        self::assertSame('450.00', $this->invoiceRecord($second)->get(InvoiceModule::NET_TOTAL));
    }

    /**
     * **An order that has been deleted takes nothing back**, which is the third
     * of {@see \Xivi\Core\Money\Discount}'s three answers doing its job.
     *
     * There is a difference between "this document is discounted by nothing" and
     * "I cannot tell what discounts it", and only the first may take a line off a
     * bill. Deleting the order behind a draft is the second.
     */
    public function testABillWhoseOrderIsGoneKeepsWhatItWasGiven(): void
    {
        $order = $this->aDiscountedOrder('GONE-100', '100.00', '10', '100.00');
        $invoice = $this->invoiceOf($order, [[0, 'quantity', '5']]);

        self::assertSame('-50.00', $this->discountOn($invoice));

        $this->deleteOrder($order);

        $rows = $this->rowsOf($invoice);

        $this->saveInvoice($invoice, [
            self::row([
                InvoiceModule::KIND => InvoiceModule::CUSTOM_LINE,
                InvoiceModule::DESCRIPTION => 'Consulting',
                InvoiceModule::QUANTITY => '5',
                InvoiceModule::UNIT_PRICE => '100.00',
                InvoiceModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[0]->id),
            self::row([
                InvoiceModule::KIND => InvoiceModule::DISCOUNT_LINE,
                InvoiceModule::DESCRIPTION => 'GONE-100',
                InvoiceModule::QUANTITY => '1',
                InvoiceModule::UNIT_PRICE => '-50.00',
                InvoiceModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[1]->id),
        ]);

        self::assertSame('-50.00', $this->discountOn($invoice), 'nothing here may take it away');
    }

    /**
     * **The share lands on the rates the bill itself carries**, not on the rates
     * the order did.
     *
     * §5.24 splits a discount pro rata across the rates present and gives the
     * balance to the highest, because a discount outside the VAT grouping is a
     * document whose tax was computed on nets nobody was charged. A partial bill
     * has its own mix of rates — it may carry one of the order's three — so its
     * share is split over that mix and not over the order's. A hundred francs
     * over three hundred, in a two-and-one split that does not divide.
     */
    public function testTheShareIsSplitAcrossTheRatesTheBillItselfCarries(): void
    {
        $order = $this->aThreeRateOrder('MIXED-100', '100.00');

        // Only the standard-rated line, which is the first of the three. A row
        // is dropped by being emptied of everything somebody typed into it, so
        // the rate has to go with the rest: what the engine put there does not
        // count, and a rate is not one of those.
        $first = $this->invoiceOf($order, [
            [1, InvoiceModule::DESCRIPTION, ''],
            [1, 'quantity', ''],
            [1, 'unit_price', ''],
            [1, 'tax_rate', ''],
            [2, InvoiceModule::DESCRIPTION, ''],
            [2, 'quantity', ''],
            [2, 'unit_price', ''],
            [2, 'tax_rate', ''],
        ]);

        self::assertSame(
            [['custom', 'Dinner', '100.00'], ['discount', 'MIXED-100', '-33.33']],
            $this->billedOn($first),
            'a third of the order, and a third of the voucher, on the one rate it bills',
        );

        $second = $this->invoiceOf($order);

        self::assertSame(
            [
                ['custom', 'Guidebook', '100.00'],
                ['custom', 'Room', '100.00'],
                ['discount', 'MIXED-100', '-33.34'],
                ['discount', 'MIXED-100', '-33.33'],
            ],
            $this->billedOn($second),
            'the rest of the voucher over the two rates that are left, the higher taking the balance',
        );

        self::assertSame('66.67', $this->invoiceRecord($first)->get(InvoiceModule::NET_TOTAL));
        self::assertSame('133.33', $this->invoiceRecord($second)->get(InvoiceModule::NET_TOTAL));
    }

    /** And nobody is invited to add one to an invoice either. */
    public function testTheDiscountKindIsNotOfferedOnAnInvoice(): void
    {
        $page = (string) $this->client->request('GET', $this->url('/m/invoice/new'))->html();

        self::assertStringContainsString('data-live-kind-param="subtotal"', $page, 'the precedent is offered');
        self::assertStringNotContainsString('data-live-kind-param="discount"', $page, 'and this one is not');
    }

    /**
     * **A line voucher is shared the same way**, which the ticket asked to be
     * checked and which turned out to be broken in the louder direction.
     *
     * §5.25's mode reduces the line rather than adding one of its own, and the
     * reduction is an amount on the **line** rather than a price per unit. So
     * copying it onto an invoice for half the line copied the whole of it, and
     * two invoices came to twice the voucher — money nobody granted. The rule is
     * the order mode's rule: each invoice takes the share of the reduction that
     * matches what it bills.
     */
    public function testALineVoucherIsSharedAcrossPartialInvoicesToo(): void
    {
        $voucher = $this->savedId($this->saveRecord(
            VoucherModule::KEY,
            ['code' => 'LINE-100', 'kind' => VoucherModule::LINE_AMOUNT, 'amount' => '100.00'],
            variant: VoucherModule::LINE_AMOUNT,
        ));

        $order = $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-20',
            'status' => OrderModule::DRAFT,
        ], [OrderModule::LINES => [self::row([
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Consulting',
            OrderModule::QUANTITY => '10',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => self::STANDARD,
            OrderModule::LINE_VOUCHER => (string) $voucher,
        ])]]));

        self::assertSame('900.00', $this->orderRecord($order)->get(OrderModule::NET_TOTAL), 'the order, for the record');

        $first = $this->invoiceOf($order, [[0, 'quantity', '5']]);
        $second = $this->invoiceOf($order);

        self::assertSame('50.00', $this->rowsOf($first)[0]->get(InvoiceModule::LINE_DISCOUNT));
        self::assertSame('50.00', $this->rowsOf($second)[0]->get(InvoiceModule::LINE_DISCOUNT));

        self::assertSame('450.00', $this->invoiceRecord($first)->get(InvoiceModule::NET_TOTAL));
        self::assertSame('450.00', $this->invoiceRecord($second)->get(InvoiceModule::NET_TOTAL));
    }

    // -- helpers ------------------------------------------------------------

    /** An order of one line, with an absolute order voucher on it. */
    private function aDiscountedOrder(string $code, string $worth, string $quantity, string $price): int
    {
        $voucher = $this->savedId($this->saveRecord(
            VoucherModule::KEY,
            ['code' => $code, 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => $worth],
            variant: VoucherModule::ORDER_AMOUNT,
        ));

        return $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-20',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) $voucher,
        ], [OrderModule::LINES => [self::row([
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Consulting',
            OrderModule::QUANTITY => $quantity,
            OrderModule::UNIT_PRICE => $price,
            OrderModule::TAX_RATE => self::STANDARD,
        ])]]));
    }

    /** The awkward one: three rates, a hundred francs each, and a voucher that does not divide by three. */
    private function aThreeRateOrder(string $code, string $worth): int
    {
        $voucher = $this->savedId($this->saveRecord(
            VoucherModule::KEY,
            ['code' => $code, 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => $worth],
            variant: VoucherModule::ORDER_AMOUNT,
        ));

        $line = static fn (string $description, string $rate): array => self::row([
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => $description,
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => $rate,
        ]);

        return $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-20',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) $voucher,
        ], [OrderModule::LINES => [
            $line('Dinner', self::STANDARD),
            $line('Guidebook', self::REDUCED),
            $line('Room', self::LODGING),
        ]]));
    }

    /** Throw an order away, so that a draft billed from it is left naming nothing. */
    private function deleteOrder(int $order): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($order): void {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $order);
            self::assertInstanceOf(Record::class, $record);

            self::service(RecordRepository::class)->delete($module, $record);
        });
    }

    /**
     * Bill an order through the button on its page and the form that comes back
     * filled in, changing what somebody would change before pressing save.
     *
     * Lifted from `InvoiceModuleTest`, including its two subtleties: the
     * component has to be mounted with the seeding it was drawn from (XIV-19),
     * and an empty control is a value nobody set rather than an empty string,
     * which a decimal field refuses.
     *
     * @param list<array{0: int, 1: string, 2: string}> $adjust row index, field and value
     */
    private function invoiceOf(int $order, array $adjust = []): int
    {
        $page = $this->client->request('GET', $this->url('/m/order/' . $order));
        $seeded = $this->client->click($page->selectLink('Invoice what is left')->link());

        $values = self::formValuesOn($seeded);

        /** @var array<string, string> $fields */
        $fields = $values['fields'];
        $seedFields = array_filter($fields, static fn (mixed $value): bool => $value !== '');

        $fields['issued_on'] = '2026-08-20';
        $fields['status'] = InvoiceModule::DRAFT;

        /** @var array<string, list<array<string, mixed>>> $rows */
        $rows = $values['collections'] ?? [];

        foreach ($adjust as [$index, $field, $value]) {
            $rows[InvoiceModule::LINES][$index]['fields'][$field] = $value;
        }

        return $this->savedId($this->saveRecord(
            InvoiceModule::KEY,
            $fields,
            $rows,
            seeded: array_map(
                static fn (array $lines): array => array_values(array_map(
                    static fn (array $row): array => array_filter(
                        (array) $row['fields'],
                        static fn (mixed $value): bool => $value !== '',
                    ),
                    $lines,
                )),
                $rows,
            ),
            seededFields: $seedFields,
        ));
    }

    /**
     * Save an existing invoice with the given lines, as the record form does.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function saveInvoice(int $invoice, array $rows): void
    {
        $record = $this->invoiceRecord($invoice);

        $this->saveRecord(InvoiceModule::KEY, [
            InvoiceModule::ORDER => (string) $record->get(InvoiceModule::ORDER),
            InvoiceModule::CONTACT => (string) $record->get(InvoiceModule::CONTACT),
            'issued_on' => '2026-08-20',
            'status' => InvoiceModule::DRAFT,
        ], [InvoiceModule::LINES => $rows], $invoice);
    }

    private function aCompany(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Regal AG'],
            variant: 'company',
        ));
    }

    /**
     * What an invoice bills: kind, description and line total, one entry per row.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function billedOn(int $invoice): array
    {
        return $this->linesOn(
            $invoice,
            InvoiceModule::KEY,
            InvoiceModule::LINES,
            InvoiceModule::KIND,
            InvoiceModule::DESCRIPTION,
            InvoiceModule::LINE_TOTAL,
        );
    }

    /** What the one discount line of an invoice says, as stored. */
    private function discountOn(int $invoice): string
    {
        foreach ($this->billedOn($invoice) as [$kind, , $total]) {
            if ($kind === InvoiceModule::DISCOUNT_LINE) {
                return $total;
            }
        }

        self::fail(sprintf('invoice %d carries no discount line at all', $invoice));
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function linesOn(int $id, string $module, string $collection, string $kind, string $description, string $total): array
    {
        return array_map(
            static fn (Record $row): array => [
                (string) $row->get($kind),
                (string) $row->get($description),
                (string) $row->get($total),
            ],
            $this->rowsOf($id, $module, $collection),
        );
    }

    /** @return list<Record> */
    private function rowsOf(int $id, string $module = InvoiceModule::KEY, string $collection = InvoiceModule::LINES): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function () use ($id, $module, $collection): array {
                $shape = self::service(MetadataRepository::class)->get($module)->getCollection($collection);
                self::assertNotNull($shape);

                return self::service(RecordRepository::class)->findChildren($shape, $id);
            },
        );
    }

    private function invoiceRecord(int $id): Record
    {
        return $this->recordOf(InvoiceModule::KEY, $id);
    }

    private function orderRecord(int $id): Record
    {
        return $this->recordOf(OrderModule::KEY, $id);
    }

    private function recordOf(string $module, int $id): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $id): Record {
            $definition = self::service(MetadataRepository::class)->get($module);
            $record = self::service(RecordRepository::class)->find($definition, $id);
            self::assertInstanceOf(Record::class, $record);

            return $record;
        });
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

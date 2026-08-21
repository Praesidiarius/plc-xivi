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
use Symfony\Component\HttpFoundation\Response;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleInstallOrder;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRefused;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;
use Xivi\Voucher\Redemption\VoucherRedemptions;
use Xivi\Voucher\VoucherModule;

/**
 * What a line voucher costs the counter, and where it may be applied (XIV-122).
 *
 * The counter half of the line mode, split from `OrderLineVoucherTest` the same
 * way [XIV-104] split `OrderVoucherRedemptionTest` from `OrderVoucherTest` — and
 * for the same practical reason as well as the tidy one: a class holding both
 * runs two dozen full save cycles in one process and exhausts its memory limit
 * before the last of them.
 *
 * **The question this class settles is the one the ticket left open**: a voucher
 * put on three lines of one order is *one* use, because the count is the number
 * of documents that carry the voucher and that is one document. Everything below
 * is that sentence read from a different side — a single-use voucher covering a
 * whole order, a second order refused, a voucher moved between two lines costing
 * nothing, and a deleted order giving back exactly what it took and not twice.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OrderLineVoucherRedemptionTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_line_redeem';
    private const string HOST = 'lineredeem.localhost';
    private const string EMAIL = 'shop@example.test';
    private const string PASSWORD = 'line-redeem-password';

    /** Two of the three rates this country has, so one grouping holds two lines. */
    private const string STANDARD = '8.10';
    private const string REDUCED = '2.60';

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

            // Through the install order rather than the order they are written
            // here (XIV-72, XIV-104): an order installed before vouchers is an
            // order with no voucher field on its header *and none on its lines*,
            // which since XIV-122 is the same rule asked one shape further down.
            $keys = [ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY, VoucherModule::KEY];

            foreach (self::service(ModuleInstallOrder::class)->of($keys) as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Shop', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }
    // -- the mode decides where a voucher may be applied ---------------------

    /**
     * A line voucher on the order is refused at the write, with the fix in the
     * sentence.
     *
     * **Asked of the writer rather than of the record form, and that changed
     * with [XIV-172]**, though the assertion is the same one it always was. Since the
     * pickers narrowed to the kinds they can actually hold, a misplaced voucher
     * cannot be submitted through the form at all: it is not among the choices,
     * so the value never reaches the engine and this sentence is never reached
     * either. `VoucherPickerTest` is where *that* is under test.
     *
     * What is under test here is the rule itself, which is not a property of any
     * picker. An import, a copy of another document and anything else calling
     * {@see RecordWriter} write vouchers onto orders with no form in front of
     * them, and the refusal is what makes the mode true for them rather than
     * merely presented.
     */
    public function testALineVoucherPutOnTheOrderIsRefused(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'LINE-ONLY', 'kind' => VoucherModule::LINE_AMOUNT, 'amount' => '5.00'],
            VoucherModule::LINE_AMOUNT,
        );

        $refusal = $this->refusalOfWriting([
            'contact' => $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => $voucher,
        ], [self::aDesk()]);

        self::assertStringContainsString('applies to a single line and was put on the document', $refusal);
        self::assertSame(0, $this->redeemed($voucher), 'and a refused save takes no use');
    }

    /** And an order voucher on a line is refused the other way round. */
    public function testAnOrderVoucherPutOnALineIsRefused(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'ORDER-ONLY', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '5.00'],
            VoucherModule::ORDER_AMOUNT,
        );

        $refusal = $this->refusalOfWriting([
            'contact' => $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
        ], [[...self::aDesk(), OrderModule::LINE_VOUCHER => $voucher]]);

        self::assertStringContainsString('applies to the whole document and was put on a line', $refusal);
        self::assertSame(0, $this->redeemed($voucher));
    }
    // -- one voucher, several lines, and the counter -------------------------

    /**
     * **One voucher on three lines is one use**, which is the question [XIV-122]
     * left open and the answer this class exists to pin down.
     *
     * The invariant is [XIV-104]'s, unchanged: *the count is the number of
     * documents that carry the voucher*. An order with `SPREAD` on three of its
     * lines is one order carrying `SPREAD`. Counting per line would spend a
     * five-use promotion on the first customer who bought five things.
     */
    public function testAVoucherOnThreeLinesTakesOneUse(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'SPREAD', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '10'],
            VoucherModule::LINE_PERCENTAGE,
        );

        $order = $this->anOrder([
            ['description' => 'One', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
            ['description' => 'Two', 'quantity' => '1', 'unit_price' => '55.55', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
            ['description' => 'Three', 'quantity' => '1', 'unit_price' => '33.33', 'tax_rate' => self::REDUCED,
                OrderModule::LINE_VOUCHER => (string) $voucher],
        ]);

        self::assertSame(1, $this->redeemed($voucher), 'three lines, one document, one use');

        // And every line is reduced, so "one use" is not one line quietly getting
        // the discount. 10% of 55.55 is 5.555, which is 5.56 half-up.
        self::assertSame(
            [['One', '1.00', '100.00', '10.00', '90.00'],
                ['Two', '1.00', '55.55', '5.56', '49.99'],
                ['Three', '1.00', '33.33', '3.33', '30.00']],
            $this->linesOf($order),
        );
    }

    /**
     * **A single-use voucher therefore covers a whole order**, which is the same
     * decision seen from the side a shop would notice.
     *
     * Under per-line counting this save would be refused by its own second line.
     */
    public function testASingleUseVoucherCanCoverEveryLineOfOneOrder(): void
    {
        $voucher = $this->aVoucher([
            'code' => 'ONCE-ONLY',
            'kind' => VoucherModule::LINE_PERCENTAGE,
            'percentage' => '10',
            'max_redemptions' => '1',
        ], VoucherModule::LINE_PERCENTAGE);

        $order = $this->anOrder([
            ['description' => 'One', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
            ['description' => 'Two', 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
        ]);

        self::assertGreaterThan(0, $order);
        self::assertSame(1, $this->redeemed($voucher));
    }

    /**
     * **And a second order is refused**, so "one per document" is a limit rather
     * than a way of never reaching one.
     */
    public function testASecondOrderCannotTakeASingleUseVoucherAgain(): void
    {
        $voucher = $this->aVoucher([
            'code' => 'ONCE-ONLY-2',
            'kind' => VoucherModule::LINE_PERCENTAGE,
            'percentage' => '10',
            'max_redemptions' => '1',
        ], VoucherModule::LINE_PERCENTAGE);

        $this->anOrder([
            ['description' => 'One', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
        ]);

        $second = $this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => '',
        ], [OrderModule::LINES => [self::row([
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Two',
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => self::STANDARD,
            OrderModule::LINE_VOUCHER => (string) $voucher,
        ])]]);

        self::assertStringContainsString('already been used', (string) $second->getContent());
        self::assertSame(1, $this->redeemed($voucher), 'still one — the refused save took nothing');
    }

    /**
     * **Moving it from one line to another costs nothing**, which is what the set
     * buys and a per-field diff could not.
     *
     * Two field changes, no change to the document: it carried `SHIFTED` before and
     * carries `SHIFTED` after. A reading that released and re-took would be
     * arithmetically back where it started on a good day, and on a single-use
     * voucher at its limit would refuse a save that changed nothing about how many
     * times the voucher had been used.
     */
    public function testMovingAVoucherBetweenLinesConsumesNothing(): void
    {
        $voucher = $this->aVoucher([
            'code' => 'SHIFTED',
            'kind' => VoucherModule::LINE_PERCENTAGE,
            'percentage' => '10',
            'max_redemptions' => '1',
        ], VoucherModule::LINE_PERCENTAGE);

        $order = $this->anOrder([
            ['description' => 'One', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
            ['description' => 'Two', 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => self::STANDARD],
        ]);

        $rows = $this->rowsOf($order, OrderModule::LINES);

        $this->saveOrder($order, [
            self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'One',
                OrderModule::QUANTITY => '1',
                OrderModule::UNIT_PRICE => '100.00',
                OrderModule::TAX_RATE => self::STANDARD,
                OrderModule::LINE_VOUCHER => '',
            ], (int) $rows[0]->id),
            self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Two',
                OrderModule::QUANTITY => '1',
                OrderModule::UNIT_PRICE => '200.00',
                OrderModule::TAX_RATE => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher,
            ], (int) $rows[1]->id),
        ]);

        self::assertSame(1, $this->redeemed($voucher), 'the document still carries it, so it still costs one');
        self::assertSame(
            [['One', '1.00', '100.00', '', '100.00'], ['Two', '1.00', '200.00', '20.00', '180.00']],
            $this->linesOf($order),
            'and the money moved with it',
        );
    }

    /** Deleting the order gives every use it held back. */
    public function testDeletingTheOrderGivesTheUseBack(): void
    {
        $voucher = $this->aVoucher(
            ['code' => 'GONE-WITH-IT', 'kind' => VoucherModule::LINE_PERCENTAGE, 'percentage' => '10'],
            VoucherModule::LINE_PERCENTAGE,
        );

        $order = $this->anOrder([
            ['description' => 'One', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
            ['description' => 'Two', 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => self::STANDARD,
                OrderModule::LINE_VOUCHER => (string) $voucher],
        ]);

        self::assertSame(1, $this->redeemed($voucher));

        $this->deleteOrder($order);

        self::assertSame(0, $this->redeemed($voucher), 'exactly what it took, and not twice');
    }
    // -- helpers ------------------------------------------------------------

    // -- helpers ------------------------------------------------------------

    /**
     * An order, whose lines are custom unless a row says otherwise.
     *
     * @param list<array<string, string>> $lines
     */
    private function anOrder(array $lines, ?int $voucher = null): int
    {
        $rows = [];

        foreach ($lines as $values) {
            $rows[] = self::row([OrderModule::KIND => OrderModule::CUSTOM_LINE, ...$values]);
        }

        return $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => $voucher === null ? '' : (string) $voucher,
        ], [OrderModule::LINES => $rows]));
    }

    /**
     * Save an existing order with the given lines, as the record form does.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function saveOrder(int $order, array $rows): Response
    {
        $record = $this->orderRecord($order);

        return $this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $record->get('contact'),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) ($record->get(OrderModule::VOUCHER) ?? ''),
        ], [OrderModule::LINES => $rows], $order);
    }

    /**
     * What the engine says when it refuses to write this order.
     *
     * The English sentence rather than the translated one: `RecordRefused`
     * carries both, a `TranslatableMessage` for the person and a plain message
     * for whoever is reading a log or a stack, and the plain one is the half
     * that does not move when a catalogue is retranslated.
     *
     * @param array<string, mixed>       $fields
     * @param list<array<string, mixed>> $lines
     */
    private function refusalOfWriting(array $fields, array $lines): string
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($fields, $lines): string {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);

            try {
                self::service(RecordWriter::class)->save($module, new Record(data: $fields), [
                    OrderModule::LINES => array_map(
                        static fn (array $row): array => ['id' => null, 'data' => $row],
                        $lines,
                    ),
                ]);
            } catch (RecordRefused $refused) {
                return $refused->getMessage();
            }

            self::fail('the write was expected to be refused');
        });
    }

    /**
     * One ordinary line to hang the assertion on.
     *
     * @return array<string, string>
     */
    private static function aDesk(): array
    {
        return [
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Desk',
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => self::STANDARD,
        ];
    }

    /** @param array<string, string> $fields */
    private function aVoucher(array $fields, string $variant): int
    {
        return $this->savedId($this->saveRecord(VoucherModule::KEY, $fields, variant: $variant));
    }

    private function aCompany(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Regal AG'],
            variant: 'company',
        ));
    }

    /** How many times the counter says this voucher has been used. */
    private function redeemed(int $voucher): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => self::service(VoucherRedemptions::class)->countFor($voucher),
        );
    }

    /**
     * The lines as stored, in the columns this ticket is about: what it says, how
     * many at what each, what came off it, and what is left.
     *
     * The discount and the total are read together on purpose — a reduction and a
     * line total that disagree is the one failure this feature can have that still
     * looks plausible on the page.
     *
     * @return list<array{string, string, string, string, string}>
     */
    private function linesOf(int $order): array
    {
        return array_map(
            static fn (Record $row): array => [
                (string) $row->get('description'),
                (string) $row->get(OrderModule::QUANTITY),
                (string) $row->get(OrderModule::UNIT_PRICE),
                (string) $row->get(OrderModule::LINE_DISCOUNT),
                (string) $row->get(OrderModule::LINE_TOTAL),
            ],
            $this->rowsOf($order, OrderModule::LINES),
        );
    }

    /** @return list<Record> */
    private function rowsOf(int $record, string $collection, string $module = OrderModule::KEY): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function () use ($record, $collection, $module): array {
                $rows = self::service(MetadataRepository::class)->get($module)->getCollection($collection);
                self::assertNotNull($rows);

                return self::service(RecordRepository::class)->findChildren($rows, $record);
            },
        );
    }

    private function orderRecord(int $order): Record
    {
        return $this->recordOf(OrderModule::KEY, $order);
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

    /** Delete an order the way its own page does, token and all. */
    private function deleteOrder(int $id): void
    {
        $path = sprintf('/m/%s/%d', OrderModule::KEY, $id);
        $page = $this->client->request('GET', $this->url($path));
        $token = $page->filter('form[action$="/delete"] input[name="_token"]')->first()->attr('value');

        self::assertNotNull($token, 'the record page offers a delete button');

        $this->client->request('POST', $this->url($path . '/delete'), ['_token' => $token]);
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

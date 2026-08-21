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
use Symfony\Contracts\Translation\TranslatorInterface;
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
 * Taking a use of a voucher, and being refused one (XIV-104).
 *
 * The counter half of `OrderVoucherTest`, in a class of its own because a
 * functional test class keeps one kernel for its whole length and pays for it in
 * memory — the note in `phpunit.dist.xml` says so, and thirty browser requests in
 * one class is where that stops being free.
 *
 * What is under test is **when** a use is taken rather than what it is worth:
 * [XIV-103] built the counter and its guarded statement, and this ticket is its
 * caller. The seam is a subscriber on `RecordChanged`, which is dispatched inside
 * the writer's transaction — every assertion below is really about that one fact
 * (§5.24).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OrderVoucherRedemptionTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_order_redeem';
    private const string HOST = 'orderredeem.localhost';
    private const string EMAIL = 'shop@example.test';
    private const string PASSWORD = 'order-voucher-password';

    /** One rate is enough here: what is under test is the counter, not the money. */
    private const string STANDARD = '8.10';

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

            // **Through the install order rather than in the order they are
            // written here** (XIV-72, XIV-104). An order installed before
            // vouchers is an order with no voucher field on it, because a field
            // pointing at a module the customer has not got is not installed —
            // so the whole of this test class depends on the sort, and asking
            // for it here is what a `tenant:reset` or a store purchase does too.
            $keys = [ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY, VoucherModule::KEY];

            foreach (self::service(ModuleInstallOrder::class)->of($keys) as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Shop', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- the counter --------------------------------------------------------

    /** A use is taken when the order commits, and not before. */
    public function testAUseIsTakenWhenTheOrderCommits(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);

        self::assertSame(0, $this->redeemed($voucher), 'nothing yet');

        $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        self::assertSame(1, $this->redeemed($voucher));
    }

    /**
     * **Typing the code costs nothing.** The live form re-derives on every
     * keystroke (XIV-32), so a redemption on that path would burn a voucher per
     * character — and somebody who types a code and wanders off has not used it.
     */
    public function testTypingTheCodeIntoAFormTakesNothing(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);

        $rendered = $this->recordForm(OrderModule::KEY)
            ->set('module_record', [
                'fields' => [OrderModule::VOUCHER => (string) $voucher],
                'collections' => [OrderModule::LINES => [self::row([
                    OrderModule::KIND => OrderModule::CUSTOM_LINE,
                    'description' => 'Desk lamp',
                    OrderModule::QUANTITY => '1',
                    OrderModule::UNIT_PRICE => '100.00',
                    OrderModule::TAX_RATE => self::STANDARD,
                ])]],
            ])
            ->render()
            ->toString();

        self::assertStringContainsString('value="90.00"', $rendered, 'the total follows the voucher while typing');
        self::assertSame(0, $this->redeemed($voucher), 'and nothing has been used');
    }

    /** A save that fails takes nothing either — the redemption is in the transaction that failed. */
    public function testAFailedSaveTakesNothing(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);

        // No customer, which the module requires: the save is refused after the
        // deriver has run and before anything is written.
        $response = $this->saveRecord(OrderModule::KEY, [
            'contact' => '',
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) $voucher,
        ], [OrderModule::LINES => [self::row([
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Desk lamp',
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => self::STANDARD,
        ])]]);

        self::assertNull($response->headers->get('Location'), 'the save was refused');
        self::assertSame(0, $this->redeemed($voucher));
    }

    /** Taking the voucher off a draft gives the use back. */
    public function testRemovingTheVoucherFromADraftGivesTheUseBack(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);
        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        $rows = $this->rowsOf($order, OrderModule::LINES);

        $this->saveOrder($order, null, [self::row([
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Desk lamp',
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => self::STANDARD,
        ], (int) $rows[0]->id)]);

        self::assertSame(0, $this->redeemed($voucher), 'the use came back');
        self::assertSame(
            [['custom', 'Desk lamp', '1.00', '100.00', self::STANDARD, '100.00']],
            $this->linesOf($order),
            'and the discount line went with it',
        );
        self::assertSame('100.00', $this->orderRecord($order)->get(OrderModule::NET_TOTAL));
    }

    /** Deleting the order gives it back too: the count is how many documents carry the voucher. */
    public function testDeletingTheOrderGivesTheUseBack(): void
    {
        $voucher = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);
        $order = $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        $this->deleteRecord(OrderModule::KEY, $order);

        self::assertSame(0, $this->redeemed($voucher));
    }

    /**
     * **A save that fails after taking a use gives it back**, which is the half of
     * "a failed save consumes nothing" that a validation failure cannot prove.
     *
     * A refused validation never reaches the writer, so nothing is redeemed and
     * nothing has to be undone. This is the other case and the one the design
     * depends on: swapping an order from one voucher to an exhausted one *does*
     * reach the write — the first voucher's use is given back, the second one's
     * take is refused, and the transaction rolls back over both. If the release
     * were not inside it, the first voucher would quietly lose a use to a save
     * that never happened.
     */
    public function testASaveThatIsRefusedAfterReleasingPutsTheUseBack(): void
    {
        $mine = $this->aVoucher(['code' => 'GIVE-10', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);
        $order = $this->anOrder($mine, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        $spent = $this->aVoucher([
            'code' => 'ONCE-ONLY',
            'kind' => VoucherModule::ORDER_AMOUNT,
            'amount' => '5.00',
            'max_redemptions' => '1',
        ]);
        $this->anOrder($spent, [
            ['description' => 'Notebook', 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => self::STANDARD],
        ]);

        self::assertSame(1, $this->redeemed($mine));
        self::assertSame(1, $this->redeemed($spent));

        $rows = $this->rowsOf($order, OrderModule::LINES);

        $response = $this->saveOrder($order, $spent, [
            self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Desk lamp',
                OrderModule::QUANTITY => '1',
                OrderModule::UNIT_PRICE => '100.00',
                OrderModule::TAX_RATE => self::STANDARD,
            ], (int) $rows[0]->id),
        ]);

        self::assertNull($response->headers->get('Location'), 'the swap was refused');
        self::assertStringContainsString(
            'has already been used as many times as it allows',
            strip_tags((string) $response->getContent()),
        );

        self::assertSame(1, $this->redeemed($mine), 'the use it had is still its');
        self::assertSame(1, $this->redeemed($spent), 'and the exhausted one took nothing further');
        self::assertSame(
            $mine,
            $this->orderRecord($order)->get(OrderModule::VOUCHER),
            'the order still names the voucher it was saved with',
        );
    }

    // -- refusals, each naming itself ---------------------------------------

    /** A voucher with no uses left is refused, and the sentence says which way. */
    public function testAnExhaustedVoucherIsRefused(): void
    {
        $voucher = $this->aVoucher([
            'code' => 'ONCE-ONLY',
            'kind' => VoucherModule::ORDER_AMOUNT,
            'amount' => '10.00',
            'max_redemptions' => '1',
        ]);

        $this->anOrder($voucher, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => self::STANDARD],
        ]);

        self::assertStringContainsString(
            'has already been used as many times as it allows',
            $this->refusalOf($voucher),
        );
        self::assertSame(1, $this->redeemed($voucher), 'and the refused order took nothing');
    }

    /**
     * An expired one says so, rather than saying it is not valid.
     *
     * **Asked of the writer rather than of the record form, and that changed
     * with XIV-175**, though the assertion is the one it always was. Since the
     * picker narrowed to the vouchers that can be used today, an expired one
     * cannot be submitted through the form at all: it is not among the choices,
     * so the value never reaches the engine and this sentence is never reached
     * from there. `VoucherValidityPickerTest` is where *that* is under test.
     *
     * What is under test here is the rule, which is not a property of any
     * picker. An import, a copy of another document and anything else calling
     * {@see RecordWriter} name vouchers on orders with no form in front of them,
     * and the refusal is what makes the rule true for them rather than merely
     * presented. It is also still the only thing standing between a stale form
     * and a discount: the narrowing is a convenience in front of it.
     */
    public function testAnExpiredVoucherIsRefusedAtTheWriter(): void
    {
        $voucher = $this->aVoucher([
            'code' => 'LAST-YEAR',
            'kind' => VoucherModule::ORDER_AMOUNT,
            'amount' => '10.00',
            'valid_until' => '2020-12-31',
        ]);

        self::assertStringContainsString('is past its last valid day', $this->refusalOfWriting($voucher));
        self::assertSame(0, $this->redeemed($voucher), 'and a refused save takes no use');
    }

    /** And so does one whose promotion has not started. */
    public function testAVoucherThatHasNotStartedIsRefusedAtTheWriter(): void
    {
        $voucher = $this->aVoucher([
            'code' => 'NEXT-YEAR',
            'kind' => VoucherModule::ORDER_AMOUNT,
            'amount' => '10.00',
            'valid_from' => '2099-01-01',
        ]);

        self::assertStringContainsString('is not valid yet', $this->refusalOfWriting($voucher));
    }

    /**
     * A voucher that is gone cannot be put on an order at all — through the form,
     * the picker simply does not have it.
     *
     * **This is not the refusal below, and the difference is worth writing down.**
     * A `reference` control offers the records that exist and nothing else
     * (XIV-13), so an id naming a deleted record does not survive the submit: the
     * field arrives empty and the order is saved without a voucher. That is the
     * engine's own answer to §7.6 and it is the right one — there is nothing to
     * refuse, because nothing was accepted.
     *
     * What it must not do is give the discount away anyway, which is what this
     * asserts.
     */
    public function testAVoucherThatIsGoneCannotBeNamedThroughTheForm(): void
    {
        $voucher = $this->aVoucher(['code' => 'VANISHED', 'kind' => VoucherModule::ORDER_AMOUNT, 'amount' => '10.00']);

        $this->deleteRecord(VoucherModule::KEY, $voucher);

        $order = $this->savedId($this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) $voucher,
        ], [OrderModule::LINES => [self::row([
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Desk lamp',
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => self::STANDARD,
        ])]]));

        self::assertNull($this->orderRecord($order)->get(OrderModule::VOUCHER), 'the picker never took it');
        self::assertSame('100.00', $this->orderRecord($order)->get(OrderModule::NET_TOTAL), 'and nothing came off');
        self::assertSame(0, $this->redeemed($voucher));
    }

    /**
     * And a caller that is not the form is refused, with the sentence that names
     * which way it went wrong.
     *
     * **Not every path is the record form.** The importer and the demo generator
     * hold {@see RecordWriter} directly and neither goes
     * through a picker, so an order can arrive here naming a voucher that has
     * since been deleted or whose module has since been uninstalled. Silently
     * proceeding would write a document claiming a discount nothing can explain;
     * refusing is what the writer's transaction is for.
     *
     * It is asserted at the writer rather than through a request because the
     * request cannot reach it — see the test above, which is the honest statement
     * of that.
     */
    public function testAVoucherThatCannotBeReadIsRefusedAtTheWriter(): void
    {
        $contact = $this->aCompany();

        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($contact): void {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);

            $this->expectException(RecordRefused::class);

            self::service(RecordWriter::class)->save($module, new Record(data: [
                'contact' => $contact,
                'ordered_on' => '2026-08-19',
                'status' => OrderModule::DRAFT,
                // A voucher that never existed, which is the same thing to
                // everything downstream as one that was deleted.
                OrderModule::VOUCHER => 987654,
            ]), [OrderModule::LINES => [['id' => null, 'data' => [
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Desk lamp',
                OrderModule::QUANTITY => '1',
                OrderModule::UNIT_PRICE => '100.00',
                OrderModule::TAX_RATE => self::STANDARD,
            ]]]]);
        });
    }

    /** And the sentence it carries names the voucher rather than the rule. */
    public function testTheUnreadableRefusalSaysWhichWayItWentWrong(): void
    {
        $contact = $this->aCompany();

        $refusal = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function () use ($contact): RecordRefused {
                $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);

                try {
                    self::service(RecordWriter::class)->save($module, new Record(data: [
                        'contact' => $contact,
                        'ordered_on' => '2026-08-19',
                        'status' => OrderModule::DRAFT,
                        OrderModule::VOUCHER => 987654,
                    ]), [OrderModule::LINES => [['id' => null, 'data' => [
                        OrderModule::KIND => OrderModule::CUSTOM_LINE,
                        'description' => 'Desk lamp',
                        OrderModule::QUANTITY => '1',
                        OrderModule::UNIT_PRICE => '100.00',
                        OrderModule::TAX_RATE => self::STANDARD,
                    ]]]]);
                } catch (RecordRefused $refused) {
                    return $refused;
                }

                self::fail('the save was not refused');
            },
        );

        self::assertSame(OrderModule::VOUCHER, $refusal->fieldKey, 'on the field it is about');
        self::assertStringContainsString(
            'is no longer available',
            $refusal->translatable()->trans(self::service(TranslatorInterface::class)),
        );
    }

    // -- helpers ------------------------------------------------------------

    /**
     * An order naming a voucher, all lines custom so nothing depends on a
     * catalogue.
     *
     * @param list<array<string, string>> $lines
     */
    private function anOrder(?int $voucher, array $lines): int
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
    private function saveOrder(int $order, ?int $voucher, array $rows): Response
    {
        return $this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->orderRecord($order)->get('contact'),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => $voucher === null ? '' : (string) $voucher,
        ], [OrderModule::LINES => $rows], $order);
    }

    /** @param array<string, string> $fields */
    private function aVoucher(array $fields, string $variant = VoucherModule::ORDER_AMOUNT): int
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

    /**
     * What the sentence is when an order tries to use this voucher and is
     * refused.
     *
     * The refusal is on the field the voucher was named in, which is where the
     * validator would have put one — so this reads it off the page the person is
     * still looking at rather than out of an exception.
     */
    private function refusalOfWriting(int $voucher): string
    {
        $contact = $this->aCompany();

        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($contact, $voucher): string {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);

            try {
                self::service(RecordWriter::class)->save($module, new Record(data: [
                    'contact' => $contact,
                    'ordered_on' => '2026-08-19',
                    'status' => OrderModule::DRAFT,
                    OrderModule::VOUCHER => $voucher,
                ]), [OrderModule::LINES => [['id' => null, 'data' => [
                    OrderModule::KIND => OrderModule::CUSTOM_LINE,
                    'description' => 'Desk lamp',
                    OrderModule::QUANTITY => '1',
                    OrderModule::UNIT_PRICE => '100.00',
                    OrderModule::TAX_RATE => self::STANDARD,
                ]]]]);
            } catch (RecordRefused $refused) {
                // The English half rather than the translated one, like
                // `OrderLineVoucherRedemptionTest`: `RecordRefused` carries both,
                // and the plain sentence is the one that does not move when a
                // catalogue is retranslated.
                return $refused->getMessage();
            }

            self::fail('the write was expected to be refused');
        });
    }

    private function refusalOf(int $voucher): string
    {
        $response = $this->saveRecord(OrderModule::KEY, [
            'contact' => (string) $this->aCompany(),
            'ordered_on' => '2026-08-19',
            'status' => OrderModule::DRAFT,
            OrderModule::VOUCHER => (string) $voucher,
        ], [OrderModule::LINES => [self::row([
            OrderModule::KIND => OrderModule::CUSTOM_LINE,
            'description' => 'Desk lamp',
            OrderModule::QUANTITY => '1',
            OrderModule::UNIT_PRICE => '100.00',
            OrderModule::TAX_RATE => self::STANDARD,
        ])]]);

        self::assertNull($response->headers->get('Location'), 'the save was refused');

        return strip_tags((string) $response->getContent());
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
     * The lines as stored: kind, description, quantity, price, rate, total.
     *
     * @return list<array{string, string, string, string, string, string}>
     */
    private function linesOf(int $order): array
    {
        return array_map(
            static fn (Record $row): array => [
                (string) $row->get(OrderModule::KIND),
                (string) $row->get('description'),
                (string) $row->get(OrderModule::QUANTITY),
                (string) $row->get(OrderModule::UNIT_PRICE),
                (string) $row->get(OrderModule::TAX_RATE),
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

    private function recordOrNull(string $module, int $id): ?Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $id): ?Record {
            $definition = self::service(MetadataRepository::class)->get($module);

            return self::service(RecordRepository::class)->find($definition, $id);
        });
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

    /**
     * Delete a record the way its own page does, token and all.
     *
     * Through the button rather than around it: the controller checks a token
     * scoped to the record, so a request that skipped the page would be redirected
     * having quietly done nothing — which is exactly the shape of test that passes
     * for the wrong reason.
     */
    private function deleteRecord(string $module, int $id): void
    {
        $path = sprintf('/m/%s/%d', $module, $id);
        $page = $this->client->request('GET', $this->url($path));
        $token = $page->filter('form[action$="/delete"] input[name="_token"]')->first()->attr('value');

        self::assertNotNull($token, 'the record page offers a delete button');

        $this->client->request('POST', $this->url($path . '/delete'), ['_token' => $token]);

        self::assertNull(
            $this->recordOrNull($module, $id),
            'the record is gone, rather than the request having been quietly refused',
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

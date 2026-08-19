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
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Money\VatMode;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;

/**
 * A price that already has the VAT in it (XIV-116).
 *
 * The figures are Swiss and deliberately ugly. **19.95 at 8.1% is the case the
 * whole ticket is about**: it does not divide cleanly, so a shop that had to work
 * the net out by hand under the old engine typed 18.46, and 18.46 plus 8.1% of
 * itself is 19.96 — a rappen above the price on the shelf, printed on the
 * customer's own document, with nobody able to explain it. Every assertion here
 * about 19.95 is an assertion that the number the shopkeeper typed is the number
 * the recipient reads.
 *
 * `OrderTotalsTest` beside this one is the net half and is unchanged, which is
 * itself part of the claim: the mode is an addition and not a rewrite.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class VatIncludedPricesTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_vat_incl';
    private const string HOST = 'vatincl.localhost';
    private const string EMAIL = 'shop@example.test';
    private const string PASSWORD = 'vat-included-password';

    /** The rate on the shelf, and the one that makes 19.95 awkward. */
    private const string RATE = '8.10';

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

            foreach ([ContactModule::KEY, OrderModule::KEY, InvoiceModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Shop', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- the promise --------------------------------------------------------

    /**
     * **The acceptance criterion**, on the case that does not divide cleanly.
     *
     * One lamp at 19.95 including 8.1%. The gross is 19.95 because 19.95 is what
     * was typed; the net is 19.95 ÷ 1.081 rounded, which is 18.46; and the VAT is
     * what is left, 1.49. Note that 8.1% of 18.46 rounds to 1.49 as well —
     * *adding* those two gives 19.95 only by luck of the second rounding, and the
     * assertion below that matters is the one about the gross, which is true by
     * construction rather than by luck.
     */
    public function testAGrossPriceIsTheGrossThatPrints(): void
    {
        $order = $this->anOrder(VatMode::Included, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '19.95', 'tax_rate' => self::RATE],
        ]);

        $record = $this->orderRecord($order);

        self::assertSame('19.95', $record->get(OrderModule::GROSS_TOTAL), 'the number on the shelf');
        self::assertSame('18.46', $record->get(OrderModule::NET_TOTAL));
        self::assertSame('1.49', $record->get(OrderModule::TAX_TOTAL));
    }

    /**
     * The same order priced the old way, to show what the shopkeeper had to do
     * and why it did not work.
     *
     * Typing the net they would have worked out — 18.46 — into an exclusive
     * document produces a total of 19.96. This is not a bug being asserted; it is
     * the arithmetic being correct in both modes, and the rappen being a property
     * of the *question*, which is why the mode had to exist rather than the
     * rounding being tightened.
     */
    public function testTheSameSaleTypedTheOldWayLandsARappenAbove(): void
    {
        $order = $this->anOrder(VatMode::Excluded, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '18.46', 'tax_rate' => self::RATE],
        ]);

        self::assertSame('19.96', $this->orderRecord($order)->get(OrderModule::GROSS_TOTAL));
    }

    /**
     * A quantity does not reopen the question: the line is rounded once, as it
     * always was, and the split happens on the rounded line total.
     *
     * Six chairs at 24.90 is 149.40, which is another gross that does not divide
     * cleanly — 149.40 ÷ 1.081 is 138.20536…, the net rounds to 138.21 and the VAT
     * is the remaining 11.19. Multiplying back instead gives 8.1% of 138.21 =
     * 11.195…, which rounds to 11.20 and would print **149.41**: a rappen more
     * than six times the price on the shelf, on a line anybody can check with a
     * calculator.
     */
    public function testAQuantityOfSixStillPrintsTheGrossThatWasTyped(): void
    {
        $order = $this->anOrder(VatMode::Included, [
            ['description' => 'Office chair', 'quantity' => '6', 'unit_price' => '24.90', 'tax_rate' => self::RATE],
        ]);

        $record = $this->orderRecord($order);

        self::assertSame('149.40', $record->get(OrderModule::GROSS_TOTAL), '6 × 24.90, untouched');
        self::assertSame('138.21', $record->get(OrderModule::NET_TOTAL));
        self::assertSame('11.19', $record->get(OrderModule::TAX_TOTAL));
    }

    // -- the VAT table ------------------------------------------------------

    /**
     * Two rates on one document, which is an ordinary week in Switzerland and the
     * case a mode on the *line* would have made unreadable.
     *
     * A lamp at 19.95 including 8.1% and a printed manual at 32.95 including 2.6%
     * — books and printed matter are reduced-rate here. Each rate is split within
     * itself, so **there is no leftover rappen to place across the two**: the
     * document's gross is the sum of two grosses that were each typed, and its net
     * is the sum of two nets that were each rounded once.
     */
    public function testAGrossPricedOrderSpanningTwoRates(): void
    {
        $order = $this->anOrder(VatMode::Included, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '19.95', 'tax_rate' => self::RATE],
            ['description' => 'Printed manual', 'quantity' => '1', 'unit_price' => '32.95', 'tax_rate' => '2.60'],
        ]);

        self::assertSame(
            [['2.60', '32.12', '0.83'], ['8.10', '18.46', '1.49']],
            $this->vatTableOf($order),
            'sorted by rate, and each row splits its own gross',
        );

        $record = $this->orderRecord($order);

        self::assertSame('52.90', $record->get(OrderModule::GROSS_TOTAL), '19.95 + 32.95, both as typed');
        self::assertSame('2.32', $record->get(OrderModule::TAX_TOTAL));
        self::assertSame('50.58', $record->get(OrderModule::NET_TOTAL));
    }

    /**
     * And the table adds up to the totals beside it, in both modes and across
     * both rates.
     *
     * Asserted as arithmetic over the stored rows rather than as three more
     * literal figures, because the literals above already say what the numbers
     * are; what this says is that they *reconcile*, which is the property a tax
     * inspector checks and the one that a rounding change would break silently.
     */
    public function testTheVatTableSumsToTheTotalsInBothModes(): void
    {
        foreach ([VatMode::Included, VatMode::Excluded] as $mode) {
            $order = $this->anOrder($mode, [
                ['description' => 'Desk lamp', 'quantity' => '3', 'unit_price' => '19.95', 'tax_rate' => self::RATE],
                ['description' => 'Printed manual', 'quantity' => '2', 'unit_price' => '32.95', 'tax_rate' => '2.60'],
            ]);

            $record = $this->orderRecord($order);
            $table = $this->vatTableOf($order);

            $net = array_sum(array_map(static fn (array $row): float => (float) $row[1], $table));
            $tax = array_sum(array_map(static fn (array $row): float => (float) $row[2], $table));

            $where = 'in ' . $mode->value . ' mode';

            self::assertEqualsWithDelta((float) $record->get(OrderModule::NET_TOTAL), $net, 0.0001, "net {$where}");
            self::assertEqualsWithDelta((float) $record->get(OrderModule::TAX_TOTAL), $tax, 0.0001, "VAT {$where}");
            self::assertEqualsWithDelta(
                (float) $record->get(OrderModule::GROSS_TOTAL),
                $net + $tax,
                0.0001,
                "and they come to the total {$where}",
            );
        }
    }

    /**
     * No VAT is still no VAT, and it is still a *rate* rather than a third mode
     * (§5.9).
     *
     * A customer who is not registered charges nothing whichever way their prices
     * are quoted, so the two modes have to agree exactly here — which is also the
     * cheapest possible statement that "no VAT", "VAT excluded" and "VAT
     * included" are three distinguishable things and not two.
     */
    public function testWithoutRatesBothModesAgreeAndNeitherShowsATable(): void
    {
        foreach ([VatMode::Included, VatMode::Excluded] as $mode) {
            $order = $this->anOrder($mode, [
                ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00'],
            ]);

            $record = $this->orderRecord($order);

            self::assertSame([], $this->vatTableOf($order), 'no table in ' . $mode->value . ' mode');
            self::assertSame('100.00', $record->get(OrderModule::NET_TOTAL));
            self::assertSame('0.00', $record->get(OrderModule::TAX_TOTAL));
            self::assertSame('100.00', $record->get(OrderModule::GROSS_TOTAL));
        }
    }

    /** A discount is still a line with a negative price, and it comes off the gross. */
    public function testADiscountLineComesOffTheGross(): void
    {
        $order = $this->anOrder(VatMode::Included, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '19.95', 'tax_rate' => self::RATE],
            ['description' => 'Goodwill', 'quantity' => '1', 'unit_price' => '-4.95', 'tax_rate' => self::RATE],
        ]);

        $record = $this->orderRecord($order);

        // 15.00 gross, which divides to 13.88 net and leaves 1.12 of tax.
        self::assertSame('15.00', $record->get(OrderModule::GROSS_TOTAL));
        self::assertSame('13.88', $record->get(OrderModule::NET_TOTAL));
        self::assertSame('1.12', $record->get(OrderModule::TAX_TOTAL));
    }

    // -- nothing that exists moves ------------------------------------------

    /**
     * **A record written before this feature** reads exactly as it always did.
     *
     * An order that predates XIV-116 has nothing in `vat_mode`, because the field
     * did not exist when it was saved — so this saves one with the field empty,
     * which is byte for byte the state every order in every tenant is in today.
     * The figures asserted are the ones `OrderTotalsTest` has asserted since
     * XIV-16, copied deliberately rather than recomputed.
     */
    public function testAnOrderWithNoModeReadsAsPricesExcludingVat(): void
    {
        $order = $this->anOrder(null, [
            ['description' => 'Desk lamp', 'quantity' => '3', 'unit_price' => '19.90', 'tax_rate' => self::RATE],
        ]);

        $record = $this->orderRecord($order);

        self::assertNull($record->get(OrderModule::VAT_MODE), 'nothing stored, as on every existing record');
        self::assertSame('59.70', $record->get(OrderModule::NET_TOTAL));
        self::assertSame('4.84', $record->get(OrderModule::TAX_TOTAL));
        self::assertSame('64.54', $record->get(OrderModule::GROSS_TOTAL));
    }

    /**
     * **And switching the installation to inclusive pricing** does not restate it.
     *
     * This is the failure this design exists to make impossible, and the reason
     * the mode is copied onto the document instead of being read from the profile
     * while deriving. A shop that flips the setting has *not* told the system that
     * the net prices already in their order book were gross all along; those
     * documents were agreed, and §5.9's rule is that a stored total is a fact.
     *
     * The order is saved a second time afterwards with identical lines, because a
     * record nobody touches is trivially unchanged — totals are stored and nothing
     * recomputes on read. What has to hold is that the *derivation* still produces
     * the same figures when the record is edited, which is the only path by which
     * a stored total could move.
     */
    public function testFlippingTheInstallationToInclusiveLeavesAnExistingOrderAlone(): void
    {
        $order = $this->anOrder(null, [
            ['description' => 'Desk lamp', 'quantity' => '3', 'unit_price' => '19.90', 'tax_rate' => self::RATE],
        ]);

        $before = $this->totalsOf($order);

        $this->installationPrices(VatMode::Included);

        $this->saveRecord(
            OrderModule::KEY,
            [
                'contact' => (string) $this->orderRecord($order)->get('contact'),
                'ordered_on' => '2026-08-15',
                'status' => OrderModule::DRAFT,
                OrderModule::VAT_MODE => '',
            ],
            [OrderModule::LINES => [self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Desk lamp',
                OrderModule::QUANTITY => '3',
                OrderModule::UNIT_PRICE => '19.90',
                OrderModule::TAX_RATE => self::RATE,
            ], (int) $this->linesOf($order)[0]->id)]],
            $order,
        );

        self::assertSame($before, $this->totalsOf($order), 'the same three figures, to the rappen');
        self::assertNull($this->orderRecord($order)->get(OrderModule::VAT_MODE), 'and still nothing stored');
    }

    // -- where the default comes from ---------------------------------------

    /**
     * A new document starts out priced the way the installation prices things
     * (§8.6).
     *
     * The setting reaches a *blank form* and nothing else, which is what the two
     * assertions here are about together: the option is preselected in the page
     * somebody is about to fill in, and it is a value on the record from that
     * moment rather than a rule consulted later.
     */
    public function testANewOrderStartsInTheInstallationsMode(): void
    {
        $this->installationPrices(VatMode::Included);

        $html = self::liveService(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): string => $this->recordForm(OrderModule::KEY)->render()->toString(),
        );

        self::assertMatchesRegularExpression(
            '#<option value="included"[^>]*selected#',
            $html,
            'the shop does not restate it on every order',
        );
    }

    /**
     * An installation that has never been asked writes nothing, which is the
     * state every tenant is in the day this ships.
     *
     * Distinct from answering "excluded": both produce a net-priced document, and
     * only this one leaves the field empty, which is what keeps an existing
     * customer's orders indistinguishable from the ones they had yesterday.
     */
    public function testAnInstallationWithNoOpinionPreselectsNothing(): void
    {
        $this->installationPrices(null);

        $html = self::liveService(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): string => $this->recordForm(OrderModule::KEY)->render()->toString(),
        );

        self::assertDoesNotMatchRegularExpression('#<option value="(in|ex)cluded"[^>]*selected#', $html);
    }

    /**
     * An invoice comes out priced like the order it was made from, not like
     * today's settings page (§5.12).
     *
     * The mode travels on the seed beside the customer, so a bill for a
     * shelf-priced order is shelf-priced even if the shop switched back in the
     * meantime — which this asserts by switching back before making the invoice.
     * An invoice quotes what was agreed on the day; a price column that changed
     * meaning because somebody edited a settings page would be the one figure on
     * a sent document that kept moving.
     */
    public function testAnInvoiceIsPricedLikeTheOrderItWasMadeFrom(): void
    {
        $order = $this->anOrder(VatMode::Included, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '19.95', 'tax_rate' => self::RATE],
        ]);

        $this->installationPrices(VatMode::Excluded);

        $orderRecord = $this->orderRecord($order);
        $line = $this->linesOf($order)[0];

        $invoice = $this->savedId($this->saveRecord(
            InvoiceModule::KEY,
            [
                InvoiceModule::ORDER => (string) $order,
                InvoiceModule::CONTACT => (string) $orderRecord->get('contact'),
                InvoiceModule::ISSUED_ON => '2026-08-16',
                InvoiceModule::STATUS => InvoiceModule::DRAFT,
                InvoiceModule::VAT_MODE => (string) $orderRecord->get(OrderModule::VAT_MODE),
            ],
            [InvoiceModule::LINES => [self::row([
                InvoiceModule::KIND => InvoiceModule::CUSTOM_LINE,
                InvoiceModule::DESCRIPTION => 'Desk lamp',
                InvoiceModule::QUANTITY => '1',
                InvoiceModule::UNIT_PRICE => '19.95',
                InvoiceModule::TAX_RATE => self::RATE,
            ])]],
            seededFields: [
                InvoiceModule::ORDER => $order,
                InvoiceModule::CONTACT => $orderRecord->get('contact'),
                InvoiceModule::VAT_MODE => $orderRecord->get(OrderModule::VAT_MODE),
            ],
            seeded: [InvoiceModule::LINES => [[
                InvoiceModule::ORDER_LINE => $line->id,
            ]]],
        ));

        $record = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($invoice): Record {
            $module = self::service(MetadataRepository::class)->get(InvoiceModule::KEY);
            $found = self::service(RecordRepository::class)->find($module, $invoice);
            self::assertInstanceOf(Record::class, $found);

            return $found;
        });

        self::assertSame(VatMode::Included->value, $record->get(InvoiceModule::VAT_MODE));
        self::assertSame('19.95', $record->get(InvoiceModule::GROSS_TOTAL), 'the same figure the order carried');
        self::assertSame('18.46', $record->get(InvoiceModule::NET_TOTAL));
        self::assertSame('1.49', $record->get(InvoiceModule::TAX_TOTAL));
    }

    // -- what the recipient reads -------------------------------------------

    /**
     * The document can say which it is, and it says it in a sentence.
     *
     * A template prints `[vat_mode]` and gets the *option's* label with no field
     * name beside it, so "included" alone would leave the reader working out
     * included in what. The label shipped is a whole sentence for that reason, and
     * this asserts it survives being installed into the customer's own
     * definitions — where it is theirs to reword.
     */
    public function testTheDocumentCanSayHowToReadItsPrices(): void
    {
        $page = $this->client->request('GET', $this->url('/m/order/' . $this->anOrder(VatMode::Included, [
            ['description' => 'Desk lamp', 'quantity' => '1', 'unit_price' => '19.95', 'tax_rate' => self::RATE],
        ])))->filter('main')->text();

        self::assertStringContainsString('Prices include VAT', $page);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * An order in the given mode, all lines custom so nothing is inherited from a
     * catalogue that would have to be priced one way or the other too.
     *
     * A null mode is the pre-XIV-116 record: the field is submitted empty, which
     * is what a record saved before the field existed carries.
     *
     * @param list<array<string, string>> $lines
     */
    private function anOrder(?VatMode $mode, array $lines): int
    {
        $rows = [];

        foreach ($lines as $values) {
            $rows[] = self::row([OrderModule::KIND => OrderModule::CUSTOM_LINE, ...$values]);
        }

        return $this->savedId($this->saveRecord(
            OrderModule::KEY,
            [
                'contact' => (string) $this->aCompany(),
                'ordered_on' => '2026-08-15',
                'status' => OrderModule::DRAFT,
                OrderModule::VAT_MODE => $mode === null ? '' : $mode->value,
            ],
            [OrderModule::LINES => $rows],
        ));
    }

    private function aCompany(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Regal AG'],
            variant: 'company',
        ));
    }

    /** What this installation prices in, or nothing at all. */
    private function installationPrices(?VatMode $mode): void
    {
        self::liveService(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(TenantProfileManager::class)
                ->apply('Regal AG', 'CHF', 'CH', null, null, $mode === null ? '' : $mode->value),
        );
    }

    /** @return array{string, string, string} net, VAT, gross, as stored */
    private function totalsOf(int $order): array
    {
        $record = $this->orderRecord($order);

        return [
            (string) $record->get(OrderModule::NET_TOTAL),
            (string) $record->get(OrderModule::TAX_TOTAL),
            (string) $record->get(OrderModule::GROSS_TOTAL),
        ];
    }

    /** @return list<array{string, string, string}> rate, net, VAT — one per row */
    private function vatTableOf(int $order): array
    {
        return array_map(
            static fn (Record $row): array => [
                (string) $row->get(OrderModule::RATE),
                (string) $row->get(OrderModule::TAXABLE_NET),
                (string) $row->get(OrderModule::TAX_AMOUNT),
            ],
            $this->rowsOf($order, OrderModule::TAXES),
        );
    }

    /** @return list<Record> */
    private function linesOf(int $order): array
    {
        return $this->rowsOf($order, OrderModule::LINES);
    }

    /** @return list<Record> */
    private function rowsOf(int $order, string $collection): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            function () use ($order, $collection): array {
                $rows = self::service(MetadataRepository::class)->get(OrderModule::KEY)->getCollection($collection);
                self::assertNotNull($rows);

                return self::service(RecordRepository::class)->findChildren($rows, $order);
            },
        );
    }

    private function orderRecord(int $order): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($order): Record {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $order);
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

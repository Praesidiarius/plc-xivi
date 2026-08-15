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
use Xivi\Order\OrderModule;

/**
 * What an order comes to (XIV-16).
 *
 * Everything here goes through the ordinary form and the ordinary save, because
 * that is the claim: a module declared totals it wanted derived, and the engine
 * derived them. There is still no order controller.
 *
 * The figures are Swiss on purpose — 8.1% and 2.6% on one document is an
 * ordinary week here, and it is the case a single rate on the header would have
 * got wrong from the first invoice.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OrderTotalsTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_totals';
    private const string HOST = 'totals.localhost';
    private const string EMAIL = 'totals@example.test';
    private const string PASSWORD = 'totals-password';

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

            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Totals', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /** Quantity times price, worked out rather than typed. */
    public function testALineTotalIsDerivedFromQuantityAndPrice(): void
    {
        $order = $this->anOrder([
            [OrderModule::ARTICLE_LINE, ['article' => (string) $this->anArticle('Desk lamp', '19.90'), 'quantity' => '3']],
        ]);

        self::assertSame('59.70', $this->linesOf($order)[0]->get(OrderModule::LINE_TOTAL));
    }

    /** And nobody can type it: the control is there to read and disabled. */
    public function testALineTotalCannotBeTypedInto(): void
    {
        $html = self::liveService(TenantSwitcher::class)->runFor($this->tenant, fn (): string => $this
            ->recordForm(OrderModule::KEY)
            ->call('addRow', ['collection' => OrderModule::LINES, 'kind' => OrderModule::CUSTOM_LINE])
            ->render()
            ->toString());

        self::assertStringContainsString('[fields][line_total]', $html, 'it is shown');
        self::assertMatchesRegularExpression(
            '#line_total[^>]*disabled#',
            $html,
            'and it is not an input',
        );
    }

    /** Net, VAT and gross land on the order itself, so a list can ask about them. */
    public function testTheOrderStoresItsNetVatAndGrossTotals(): void
    {
        $order = $this->anOrder([
            [OrderModule::ARTICLE_LINE, [
                'article' => (string) $this->anArticle('Desk lamp', '19.90', '8.10'),
                'quantity' => '3',
            ]],
        ]);

        $record = $this->orderRecord($order);

        // 3 × 19.90 = 59.70, of which 8.1% is 4.8357 — 4.84 rounded.
        self::assertSame('59.70', $record->get(OrderModule::NET_TOTAL));
        self::assertSame('4.84', $record->get(OrderModule::TAX_TOTAL));
        self::assertSame('64.54', $record->get(OrderModule::GROSS_TOTAL));
    }

    /** Stored means filterable, which is the reason they are stored. */
    public function testTheTotalsAreFilterable(): void
    {
        $article = $this->anArticle('Desk lamp', '19.90');

        $small = $this->anOrder([[OrderModule::ARTICLE_LINE, ['article' => (string) $article, 'quantity' => '1']]]);
        $large = $this->anOrder([[OrderModule::ARTICLE_LINE, ['article' => (string) $article, 'quantity' => '20']]]);

        $listed = $this->client->request('GET', $this->url(
            '/m/order?filter[0][path]=gross_total&filter[0][op]=gte&filter[0][value]=100',
        ))->filter('main table tbody tr')->each(
            static fn (Crawler $row): string => $row->text(),
        );

        self::assertCount(1, $listed);
        self::assertStringContainsString('398.00', $listed[0], 'the 20 lamps, and not the one');
        self::assertNotSame($small, $large);
    }

    /**
     * A line with no price contributes nothing — and is not a special case in
     * the summing, because it falls out of it for having no price.
     */
    public function testACommentLineContributesNothing(): void
    {
        $order = $this->anOrder([
            [OrderModule::ARTICLE_LINE, ['article' => (string) $this->anArticle('Desk lamp', '19.90'), 'quantity' => '3']],
            [OrderModule::COMMENT_LINE, ['description' => 'Delivered together']],
        ]);

        self::assertSame('59.70', $this->orderRecord($order)->get(OrderModule::NET_TOTAL));
        self::assertNull($this->linesOf($order)[1]->get(OrderModule::LINE_TOTAL), 'and shows no figure');
    }

    /**
     * A subtotal restates the block above it, starts the next one, and adds
     * nothing to the order — double-counting it is the one arithmetic mistake
     * this feature can make.
     */
    public function testASubtotalRestatesTheLinesAboveItWithoutAddingToTheTotal(): void
    {
        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '150.00']],
            [OrderModule::CUSTOM_LINE, ['description' => 'Travel', 'quantity' => '1', 'unit_price' => '80.00']],
            [OrderModule::SUBTOTAL_LINE, ['description' => 'Services']],
            [OrderModule::CUSTOM_LINE, ['description' => 'Cable', 'quantity' => '4', 'unit_price' => '12.50']],
            [OrderModule::SUBTOTAL_LINE, ['description' => 'Materials']],
        ]);

        $totals = array_map(
            static fn (Record $line): ?string => $line->get(OrderModule::LINE_TOTAL),
            $this->linesOf($order),
        );

        self::assertSame(['300.00', '80.00', '380.00', '50.00', '50.00'], $totals);
        self::assertSame('430.00', $this->orderRecord($order)->get(OrderModule::NET_TOTAL), 'counted once');
    }

    /** A discount is a line with a negative price, and it reduces its own rate's base. */
    public function testADiscountIsALineWithANegativePrice(): void
    {
        $order = $this->anOrder([
            [OrderModule::ARTICLE_LINE, [
                'article' => (string) $this->anArticle('Desk lamp', '100.00', '8.10'),
                'quantity' => '1',
            ]],
            [OrderModule::CUSTOM_LINE, [
                'description' => 'Goodwill discount',
                'quantity' => '1',
                'unit_price' => '-10.00',
                'tax_rate' => '8.10',
            ]],
        ]);

        $record = $this->orderRecord($order);

        self::assertSame('90.00', $record->get(OrderModule::NET_TOTAL));
        self::assertSame('7.29', $record->get(OrderModule::TAX_TOTAL), '8.1% of ninety, not of a hundred');
    }

    /** One row per rate, and a document may carry more than one. */
    public function testVatIsBrokenDownPerRate(): void
    {
        $order = $this->anOrder([
            [OrderModule::ARTICLE_LINE, [
                'article' => (string) $this->anArticle('Desk lamp', '100.00', '8.10'),
                'quantity' => '1',
            ]],
            [OrderModule::CUSTOM_LINE, [
                'description' => 'Printed manual',
                'quantity' => '1',
                'unit_price' => '50.00',
                'tax_rate' => '2.60',
            ]],
        ]);

        self::assertSame(
            [['2.60', '50.00', '1.30'], ['8.10', '100.00', '8.10']],
            array_map(
                static fn (Record $row): array => [
                    (string) $row->get(OrderModule::RATE),
                    (string) $row->get(OrderModule::TAXABLE_NET),
                    (string) $row->get(OrderModule::TAX_AMOUNT),
                ],
                $this->taxesOf($order),
            ),
        );

        self::assertSame('9.40', $this->orderRecord($order)->get(OrderModule::TAX_TOTAL), 'and they add up');
    }

    /**
     * Grouped before it is rounded. Two lines of 0.55 at 8.1% are 0.04455 each —
     * four rappen apiece rounds to eight, and the honest answer is nine.
     */
    public function testVatIsRoundedPerRateRatherThanPerLine(): void
    {
        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Screw', 'quantity' => '1', 'unit_price' => '0.55', 'tax_rate' => '8.10']],
            [OrderModule::CUSTOM_LINE, ['description' => 'Washer', 'quantity' => '1', 'unit_price' => '0.55', 'tax_rate' => '8.10']],
        ]);

        self::assertSame('0.09', $this->orderRecord($order)->get(OrderModule::TAX_TOTAL));
    }

    /** A line, though, is rounded as it is computed, so the column adds up. */
    public function testALineTotalIsRoundedToCents(): void
    {
        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Cable', 'quantity' => '2.55', 'unit_price' => '19.99']],
        ]);

        // 50.9745, and nobody is invoiced for a hundredth of a rappen.
        self::assertSame('50.97', $this->linesOf($order)[0]->get(OrderModule::LINE_TOTAL));
        self::assertSame('50.97', $this->orderRecord($order)->get(OrderModule::NET_TOTAL));
    }

    /** A customer with no rates anywhere sees no VAT table and owes no VAT. */
    public function testAnOrderWithoutRatesHasNoVatTable(): void
    {
        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, ['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        self::assertSame([], $this->taxesOf($order));
        self::assertSame('0.00', $this->orderRecord($order)->get(OrderModule::TAX_TOTAL));
        self::assertSame('100.00', $this->orderRecord($order)->get(OrderModule::GROSS_TOTAL));
    }

    /**
     * A save that says nothing about the lines is not a save that emptied them.
     * Confirming an order writes the header alone, and the totals have to
     * survive it.
     */
    public function testATransitionLeavesTheTotalsAlone(): void
    {
        $order = $this->anOrder([
            [OrderModule::ARTICLE_LINE, ['article' => (string) $this->anArticle('Desk lamp', '19.90', '8.10'), 'quantity' => '3']],
        ]);

        $this->transition($order, 'confirm');

        self::assertSame(OrderModule::CONFIRMED, $this->orderRecord($order)->get('status'));
        self::assertSame('64.54', $this->orderRecord($order)->get(OrderModule::GROSS_TOTAL));
    }

    /** The VAT table is worked out, so it is not on the form at all. */
    public function testTheVatTableIsNotOnTheForm(): void
    {
        $this->client->request('GET', $this->url('/m/order/new'));

        self::assertCount(
            0,
            $this->client->getCrawler()->filter('[name*="[collections][taxes]"]'),
            'nothing to type into',
        );
    }

    /** Nor in the timeline: it restates lines whose change is already recorded. */
    public function testTheVatTableIsNotInTheHistory(): void
    {
        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, [
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '8.10',
            ]],
        ]);

        $timeline = $this->client->request('GET', $this->url('/m/order/' . $order . '/history'))->filter('main')->text();

        self::assertStringContainsString('Consulting', $timeline, 'the line is there');
        self::assertStringNotContainsString('VAT breakdown', $timeline, 'and its VAT row is not');
        self::assertCount(1, $this->taxesOf($order), 'though the row exists');
    }

    /** Recomputed, not accumulated: the rows are replaced rather than added to. */
    public function testTheVatTableIsRewrittenOnEverySave(): void
    {
        $order = $this->anOrder([
            [OrderModule::CUSTOM_LINE, [
                'description' => 'Consulting',
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '8.10',
            ]],
        ]);

        $before = array_map(static fn (Record $row): ?int => $row->id, $this->taxesOf($order));

        // Saved again with the quantity doubled, and the row keeps its id.
        $line = $this->linesOf($order)[0];
        $record = $this->orderRecord($order);

        $this->saveRecord(
            OrderModule::KEY,
            [
                'contact' => (string) $record->get('contact'),
                'ordered_on' => '2026-08-15',
                'status' => OrderModule::DRAFT,
            ],
            [OrderModule::LINES => [self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Consulting',
                OrderModule::QUANTITY => '2',
                OrderModule::UNIT_PRICE => '100.00',
                OrderModule::TAX_RATE => '8.10',
            ], (int) $line->id)]],
            $order,
        );

        $after = $this->taxesOf($order);

        self::assertCount(1, $after, 'still one rate');
        self::assertSame('16.20', (string) $after[0]->get(OrderModule::TAX_AMOUNT));
        self::assertSame($before, array_map(static fn (Record $row): ?int => $row->id, $after), 'the same row');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * An order with the lines given, added one save at a time — which is how
     * somebody with no JavaScript adds them, since the form offers one blank row
     * per kind and nothing else.
     *
     * @param list<array{0: string, 1: array<string, string>}> $lines kind, then values
     */
    private function anOrder(array $lines): int
    {
        // Every line in one save (XIV-33). The old form could only be given one
        // row at a time, because a row had to be added by a button before it
        // could be filled in; a component takes the whole collection.
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

    private function anArticle(string $title, string $price, ?string $taxRate = null): int
    {
        return $this->savedId($this->saveRecord(ArticleModule::KEY, [
            'title' => $title,
            'price' => $price,
            'tax_rate' => $taxRate ?? '',
        ]));
    }

    private function transition(int $order, string $name): void
    {
        $tokens = $this->client->request('GET', $this->url('/m/order/' . $order))
            ->filter('input[name="_token"]')
            ->each(static fn (Crawler $node): string => (string) $node->attr('value'));

        $this->client->request(
            'POST',
            $this->url(sprintf('/m/order/%d/transition/%s', $order, $name)),
            ['_token' => $tokens[0] ?? 'no-token'],
        );
    }

    /** @return list<Record> */
    private function linesOf(int $order): array
    {
        return $this->rowsOf($order, OrderModule::LINES);
    }

    /** @return list<Record> */
    private function taxesOf(int $order): array
    {
        return $this->rowsOf($order, OrderModule::TAXES);
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

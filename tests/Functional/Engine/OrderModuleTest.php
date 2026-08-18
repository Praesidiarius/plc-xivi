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
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Field\Units;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Order\OrderModule;

/**
 * The module that is mostly other modules (XIV-18).
 *
 * Contact and article stand on their own; an order stands by pointing at both.
 * Everything asserted here goes through the generic controller and the generic
 * form over the customer's own definitions — there is no order controller, no
 * order entity and no order template, and the day one is needed is the day the
 * engine has a ticket rather than the module (§1).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OrderModuleTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_orders';
    private const string HOST = 'orders.localhost';
    private const string EMAIL = 'orders@example.test';
    private const string PASSWORD = 'orders-password';
    private const string FORM = 'module_record';

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

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Orders', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /** An order names a customer, and that customer's page names the order back. */
    public function testAnOrderNamesItsContactAndTheContactListsItsOrders(): void
    {
        $customer = $this->aCompany('Acme AG');
        $order = $this->anOrder($customer);

        $page = $this->client->request('GET', $this->url('/m/order/' . $order))->filter('main')->text();
        self::assertStringContainsString('Acme AG', $page);

        $contact = $this->client->request('GET', $this->url('/m/contact/' . $customer))->filter('main')->text();
        self::assertStringContainsString('Orders', $contact, 'the reverse list, across modules');
    }

    /** Four kinds of line, and a button for each — and no rows until one is pressed. */
    public function testTheFormOffersEachKindOfLine(): void
    {
        $page = $this->client->request('GET', $this->url('/m/order/new'));

        self::assertSame(
            [
                OrderModule::ARTICLE_LINE,
                OrderModule::CUSTOM_LINE,
                OrderModule::COMMENT_LINE,
                OrderModule::SUBTOTAL_LINE,
            ],
            $page->filter('[data-live-action-param="addRow"][data-live-collection-param="lines"]')
                ->each(static fn (Crawler $node): string => (string) $node->attr('data-live-kind-param')),
        );

        self::assertCount(0, $page->filter('[name$="[fields][kind]"]'), 'and nothing to fill in yet');
    }

    /** Pressing one adds a row of that kind, carrying its fields and no others. */
    public function testAButtonAddsARowOfItsOwnKind(): void
    {
        $html = self::liveService(TenantSwitcher::class)->runFor($this->tenant, fn (): string => $this
            ->recordForm(OrderModule::KEY)
            ->call('addRow', ['collection' => OrderModule::LINES, 'kind' => OrderModule::COMMENT_LINE])
            ->render()
            ->toString());

        self::assertStringContainsString('value="' . OrderModule::COMMENT_LINE . '"', $html);
        self::assertStringNotContainsString('[fields][unit_price]', $html, 'a comment has no price');
    }

    /**
     * The whole point of an article line: it takes the article's words and price
     * and then owns them.
     */
    public function testAnArticleLineInheritsTheArticlesDescriptionAndPrice(): void
    {
        $customer = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', '19.90');

        $order = $this->anOrder($customer, [
            'article' => (string) $article,
            'quantity' => '3',
        ]);

        $line = $this->linesOf($order)[0];

        self::assertSame('Desk lamp', $line->get('description'));
        self::assertSame('19.90', $line->get('unit_price'));
        self::assertSame($article, $line->get('article'), 'and still knows what was sold');
    }

    /** An order confirmed at 19.90 says 19.90 after the article goes up. */
    public function testTheOrderKeepsItsPriceWhenTheArticleChanges(): void
    {
        $customer = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', '19.90');

        $order = $this->anOrder($customer, [
            'article' => (string) $article,
            'quantity' => '3',
        ]);

        // The article goes up.
        $this->client->request('GET', $this->url('/m/article/' . $article . '/edit'));
        $this->client->submitForm('Save', [self::field('price') => '24.90']);

        self::assertSame('19.90', $this->linesOf($order)[0]->get('unit_price'));
    }

    /**
     * And the page says the line no longer matches, because a negotiated price
     * and a stale copy are indistinguishable otherwise.
     */
    public function testALineThatNoLongerMatchesItsArticleSaysSo(): void
    {
        $customer = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', '19.90');

        $order = $this->anOrder($customer, [
            'article' => (string) $article,
            'quantity' => '3',
        ]);

        $before = $this->client->request('GET', $this->url('/m/order/' . $order))
            ->filter('[title*="differs"]')
            ->count();
        self::assertSame(0, $before, 'nothing has drifted yet');

        $this->saveRecord(ArticleModule::KEY, ['title' => 'Desk lamp', 'price' => '24.90'], recordId: $article);

        $after = $this->client->request('GET', $this->url('/m/order/' . $order))
            ->filter('[title*="differs"]')
            ->count();
        self::assertSame(1, $after, 'the unit price has');
    }

    /** Nothing ever copies over something somebody typed. */
    public function testATypedValueIsNotOverwrittenByTheArticles(): void
    {
        $customer = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', '19.90');

        $order = $this->anOrder($customer, [
            'article' => (string) $article,
            'quantity' => '3',
            'unit_price' => '17.90',
        ]);

        self::assertSame('17.90', $this->linesOf($order)[0]->get('unit_price'), 'a negotiated price');
        self::assertSame('Desk lamp', $this->linesOf($order)[0]->get('description'), 'and the rest still inherited');
    }

    /**
     * **The other half of XIV-118**, which is the half this module already
     * believed in: the unit is the article's, and the line takes a copy of it.
     *
     * Nothing here is a mechanism of its own. `unit` is declared with the same
     * `InheritedValue` the description and the price above it use, so an order
     * placed in hours goes on saying hours after the catalogue is re-priced by
     * the day — and the drift marker that already watches the price watches this
     * one too, for free.
     */
    public function testAnArticleLineTakesTheArticlesUnit(): void
    {
        $customer = $this->aCompany('Acme AG');
        $article = $this->anArticle('Consulting', '180.00', Units::HOUR);

        $order = $this->anOrder($customer, [
            'article' => (string) $article,
            'quantity' => '2.5',
        ]);

        self::assertSame(Units::HOUR, $this->linesOf($order)[0]->get(OrderModule::UNIT));
    }

    /**
     * And the page says it beside the number rather than storing it silently.
     *
     * The label rather than the stored key, which is the whole reason the line's
     * field is a `choice` of the same seven values and not a text box: an
     * inherited `hour` renders as "hours" only because this shape has heard of
     * that value too.
     */
    public function testTheOrderPageShowsTheUnitBesideTheQuantity(): void
    {
        $customer = $this->aCompany('Acme AG');
        $article = $this->anArticle('Consulting', '180.00', Units::HOUR);

        $order = $this->anOrder($customer, [
            'article' => (string) $article,
            'quantity' => '2.5',
        ]);

        $cells = $this->lineCellsOf($order);

        self::assertMatchesRegularExpression('/^2[.,]50$/u', $cells['Quantity']);
        self::assertSame('hours', $cells['Unit'], 'the label, in the next column along');
    }

    /**
     * **The acceptance criterion that is about everything already in a
     * database.** Every article that existed before XIV-118 has no unit, and an
     * order line for one has to read exactly as it read the day before: a
     * quantity, and nothing after it.
     *
     * Asserted on the rendered row rather than on the stored value, because the
     * stored value being empty was never in doubt. What could have broken is the
     * page — it has a column it did not have yesterday, and an empty choice that
     * printed its own key, or the first option of the list, would be a unit this
     * customer never chose appearing on a document they have already sent.
     */
    public function testAnArticleWithNoUnitLeavesTheLineReadingAsItDidBefore(): void
    {
        $customer = $this->aCompany('Acme AG');
        $article = $this->anArticle('Desk lamp', '19.90');

        $order = $this->anOrder($customer, [
            'article' => (string) $article,
            'quantity' => '3',
        ]);

        self::assertNull($this->linesOf($order)[0]->get(OrderModule::UNIT), 'nothing to inherit');

        $cells = $this->lineCellsOf($order);

        self::assertSame('Desk lamp', $cells['Description'], 'the line is drawn');
        self::assertMatchesRegularExpression('/^3[.,]00$/u', $cells['Quantity'], 'and the number is untouched');
        self::assertSame(
            '—',
            $cells['Unit'],
            'the column is empty, which is what every other unanswered field looks like',
        );
    }

    /**
     * A custom line has no article, and it shows a unit somebody types.
     *
     * The decision rather than the accident: a custom line carries a quantity
     * exactly as an article line does, so leaving the unit off it would recreate
     * "2.5 of nothing" on the one kind of line where every other value is being
     * typed anyway.
     */
    public function testACustomLineTakesAUnitSomebodyTypes(): void
    {
        $order = $this->anOrder(
            $this->aCompany('Acme AG'),
            [
                'description' => 'Saturday call-out',
                'quantity' => '4',
                OrderModule::UNIT => Units::HOUR,
                'unit_price' => '95.00',
            ],
            OrderModule::CUSTOM_LINE,
        );

        self::assertSame(Units::HOUR, $this->linesOf($order)[0]->get(OrderModule::UNIT));
        self::assertSame('hours', $this->lineCellsOf($order)['Unit'], 'and it reaches the page like any other');
    }

    /** A comment line has no quantity, so there is nothing for a unit to qualify. */
    public function testACommentLineIsNotOfferedAUnit(): void
    {
        $html = self::liveService(TenantSwitcher::class)->runFor($this->tenant, fn (): string => $this
            ->recordForm(OrderModule::KEY)
            ->call('addRow', ['collection' => OrderModule::LINES, 'kind' => OrderModule::COMMENT_LINE])
            ->render()
            ->toString());

        self::assertStringNotContainsString('[fields][unit]', $html);
    }

    /** A comment line carries words and nothing else. */
    public function testACommentLineHasNoPrice(): void
    {
        $customer = $this->aCompany('Acme AG');

        $order = $this->anOrder($customer, [
            'description' => 'Everything below is optional',
        ], OrderModule::COMMENT_LINE);

        $lines = $this->linesOf($order);

        self::assertCount(1, $lines);
        self::assertSame(OrderModule::COMMENT_LINE, $lines[0]->get('kind'));
        self::assertNull($lines[0]->get('unit_price'));
    }

    /**
     * The bug XIV-110 was about, in the shape it was found in.
     *
     * Before the guard, this exact record confirmed: the button was drawn, the
     * POST went through, and an order with nothing on it and a total of zero
     * became a confirmed sale. Nothing else in the engine was ever going to stop
     * it — field validation is per field and could only have demanded the line of
     * a *draft* as well, and the writer validates nothing.
     */
    public function testAnOrderWithNoLinesIsNotOfferedConfirmation(): void
    {
        $order = $this->anOrder($this->aCompany('Acme AG'));

        self::assertSame([], $this->linesOf($order), 'nothing on it');
        self::assertNotContains('Confirm', $this->transitionsOn($order));
        self::assertContains(
            'Cancel',
            $this->transitionsOn($order),
            'and the way out is still open, because a guard on the only exit is a trap',
        );
    }

    /**
     * And the page says why, rather than quietly having one fewer button.
     *
     * The sentence is the module's own and lives in its catalogue, next to the
     * label of the button it is standing in for.
     */
    public function testTheOrderSaysWhyItCannotBeConfirmed(): void
    {
        $order = $this->anOrder($this->aCompany('Acme AG'));

        self::assertStringContainsString(
            'An order needs at least one line before it can be confirmed',
            $this->client->request('GET', $this->url('/m/order/' . $order))->filter('main')->text(),
        );
    }

    /**
     * **The enforcement, as opposed to the courtesy.** Hiding a button is a
     * kindness to whoever is reading the page; a POST is a URL, and a URL can be
     * retyped. So the same guard is asked again when the request arrives, and it
     * is that answer which decides.
     */
    public function testARetypedPostCannotConfirmAnOrderWithNoLines(): void
    {
        $order = $this->anOrder($this->aCompany('Acme AG'));

        $this->transition($order, 'confirm');

        $page = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('An order needs at least one line', $page, 'and it says why');
        self::assertStringContainsString('Draft', $page, 'the order did not move');
        self::assertNotContains('Mark delivered', $this->transitionsOn($order), 'nor did anything follow from it');
    }

    /** Put a line on it and the same order confirms. */
    public function testAnOrderWithALineIsConfirmed(): void
    {
        $order = $this->anOrderWithALine($this->aCompany('Acme AG'));

        self::assertContains('Confirm', $this->transitionsOn($order));

        $this->transition($order, 'confirm');

        self::assertStringContainsString('Confirmed', $this->client->followRedirect()->filter('main')->text());
    }

    /** An order moves through its lifecycle, and stops. */
    public function testAnOrderIsConfirmedAndDelivered(): void
    {
        $order = $this->anOrderWithALine($this->aCompany('Acme AG'));

        self::assertSame(['Confirm', 'Cancel'], $this->transitionsOn($order));

        $this->transition($order, 'confirm');
        self::assertSame(['Mark delivered', 'Cancel'], $this->transitionsOn($order));

        $this->transition($order, 'deliver');
        self::assertSame([], $this->transitionsOn($order), 'a delivered order is a record of what happened');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * An order, and one line of the given kind if any values are offered for it.
     *
     * @param array<string, string> $lines values for the row, keyed by field
     */
    private function anOrder(int $customer, array $lines = [], string $kind = OrderModule::ARTICLE_LINE): int
    {
        return $this->savedId($this->saveRecord(
            OrderModule::KEY,
            [
                'contact' => (string) $customer,
                'ordered_on' => '2026-08-15',
                'status' => OrderModule::DRAFT,
            ],
            $lines === [] ? [] : [OrderModule::LINES => [self::row([OrderModule::KIND => $kind, ...$lines])]],
        ));
    }

    /**
     * An order with something on it, which since XIV-110 is what "an order that
     * can be confirmed" means. A custom line, so that the fixture does not have
     * to invent an article as well.
     */
    private function anOrderWithALine(int $customer): int
    {
        return $this->anOrder(
            $customer,
            ['description' => 'A day of work', 'quantity' => '1.00', 'unit_price' => '250.00'],
            OrderModule::CUSTOM_LINE,
        );
    }

    private function aCompany(string $name): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => $name],
            variant: 'company',
        ));
    }

    /** An article, and a unit on it only when the test is about one (XIV-118). */
    private function anArticle(string $title, string $price, ?string $unit = null): int
    {
        $values = ['title' => $title, 'price' => $price];

        if ($unit !== null) {
            $values['unit'] = $unit;
        }

        return $this->savedId($this->saveRecord(ArticleModule::KEY, $values));
    }

    /**
     * The first line on an order's page, as column heading => what is in the
     * cell (XIV-118).
     *
     * By heading rather than by index on purpose: the assertions using it are
     * about a unit landing *next to* a quantity, and an index would keep passing
     * if the two swapped places. The headings are the customer's own labels,
     * which is also the thing being asserted — a page reading the definitions
     * rather than a template that knows what an order line is.
     *
     * @return array<string, string>
     */
    private function lineCellsOf(int $order): array
    {
        $table = $this->client->request('GET', $this->url('/m/order/' . $order))
            ->filter('table')
            ->first();

        $headings = $table->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text()));
        $cells = $table->filter('tbody tr')->first()->filter('td')
            ->each(static fn (Crawler $td): string => trim($td->text()));

        self::assertSameSize($headings, $cells, 'a cell per column');

        return array_combine($headings, $cells);
    }

    /** @return list<Record> */
    private function linesOf(int $order): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($order): array {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $lines = $module->getCollection('lines');
            self::assertNotNull($lines);

            return self::service(RecordRepository::class)->findChildren($lines, $order);
        });
    }

    /** @return list<string> */
    private function transitionsOn(int $order): array
    {
        return $this->client->request('GET', $this->url('/m/order/' . $order))
            ->filter('form[action*="/transition/"] button')
            ->each(static fn (Crawler $node): string => trim($node->text()));
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

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
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

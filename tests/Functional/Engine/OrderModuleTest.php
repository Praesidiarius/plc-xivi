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

    /** Four kinds of line, and one blank row for each. */
    public function testTheFormOffersEachKindOfLine(): void
    {
        $this->client->request('GET', $this->url('/m/order/new'));

        self::assertSame(
            [
                OrderModule::ARTICLE_LINE,
                OrderModule::CUSTOM_LINE,
                OrderModule::COMMENT_LINE,
                OrderModule::SUBTOTAL_LINE,
            ],
            $this->client->getCrawler()->filter('[name$="[fields][kind]"]')->each(
                static fn (Crawler $node): string => (string) $node->attr('value'),
            ),
        );
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
            self::line(0, 'fields][article') => (string) $article,
            self::line(0, 'fields][quantity') => '3',
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
            self::line(0, 'fields][article') => (string) $article,
            self::line(0, 'fields][quantity') => '3',
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
            self::line(0, 'fields][article') => (string) $article,
            self::line(0, 'fields][quantity') => '3',
        ]);

        $before = $this->client->request('GET', $this->url('/m/order/' . $order))
            ->filter('[title*="differs"]')
            ->count();
        self::assertSame(0, $before, 'nothing has drifted yet');

        $this->client->request('GET', $this->url('/m/article/' . $article . '/edit'));
        $this->client->submitForm('Save', [self::field('price') => '24.90']);

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
            self::line(0, 'fields][article') => (string) $article,
            self::line(0, 'fields][quantity') => '3',
            self::line(0, 'fields][unit_price') => '17.90',
        ]);

        self::assertSame('17.90', $this->linesOf($order)[0]->get('unit_price'), 'a negotiated price');
        self::assertSame('Desk lamp', $this->linesOf($order)[0]->get('description'), 'and the rest still inherited');
    }

    /** A comment line carries words and nothing else. */
    public function testACommentLineHasNoPrice(): void
    {
        $customer = $this->aCompany('Acme AG');

        $order = $this->anOrder($customer, [
            self::line(2, 'fields][description') => 'Everything below is optional',
        ]);

        $lines = $this->linesOf($order);

        self::assertCount(1, $lines);
        self::assertSame(OrderModule::COMMENT_LINE, $lines[0]->get('kind'));
        self::assertNull($lines[0]->get('unit_price'));
    }

    /** An order moves through its lifecycle, and stops. */
    public function testAnOrderIsConfirmedAndDelivered(): void
    {
        $order = $this->anOrder($this->aCompany('Acme AG'));

        self::assertSame(['Confirm', 'Cancel'], $this->transitionsOn($order));

        $this->transition($order, 'confirm');
        self::assertSame(['Mark delivered', 'Cancel'], $this->transitionsOn($order));

        $this->transition($order, 'deliver');
        self::assertSame([], $this->transitionsOn($order), 'a delivered order is a record of what happened');
    }

    // -- helpers ------------------------------------------------------------

    /** @param array<string, string> $lines */
    private function anOrder(int $customer, array $lines = []): int
    {
        $this->client->request('GET', $this->url('/m/order/new'));
        $this->client->submitForm('Save', [
            self::field('contact') => (string) $customer,
            self::field('ordered_on') => '2026-08-15',
            self::field('status') => OrderModule::DRAFT,
            ...$lines,
        ]);

        $this->client->followRedirect();

        return (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));
    }

    private function aCompany(string $name): int
    {
        $this->client->request('GET', $this->url('/m/contact/new?variant=company'));
        $this->client->submitForm('Save', [self::field('company_name') => $name]);
        $this->client->followRedirect();

        return (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));
    }

    private function anArticle(string $title, string $price): int
    {
        $this->client->request('GET', $this->url('/m/article/new'));
        $this->client->submitForm('Save', [
            self::field('title') => $title,
            self::field('price') => $price,
        ]);
        $this->client->followRedirect();

        return (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));
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

    private static function line(int $index, string $key): string
    {
        return sprintf('%s[collections][lines][%d][%s]', self::FORM, $index, $key);
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

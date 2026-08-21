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
 * An article sold in more than one variant ([XIV-133], §5.32).
 *
 * **The whole feature is three kinds of article record and one self-reference**,
 * so what this file is really asserting is that nothing else had to move: an
 * order line naming the large T-shirt is an order line doing exactly what it did
 * before, and the engine cannot tell the difference between a variant and any
 * other article. Every test here is therefore about a *consequence* rather than
 * about a mechanism. The mechanisms are §5.5's variants and XIV-18's
 * inheritance, both of which have their own tests already.
 *
 * The word: the code says SKU and never says variant, because §5.5 has that word
 * (see `ArticleModule`). The labels a customer reads say "Variant", which is why
 * the assertions below read the keys rather than the screen.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ArticleSkuTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_article_sku';
    private const string HOST = 'article-sku.localhost';
    private const string EMAIL = 'sku@example.test';
    private const string PASSWORD = 'sku-password';
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

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Sam Sku', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /**
     * A base holds the description and is not asked for a price; a variant is
     * asked for a price and is not asked for a description.
     *
     * That asymmetry is the whole of what "a decided, short list of what a
     * variant overrides" comes to, and it is enforced by the form rather than by
     * anybody's discipline: §5.5 builds a form from the fields of one kind.
     */
    public function testTheKindsAskForDifferentThings(): void
    {
        $base = $this->newForm(ArticleModule::BASE);

        self::assertCount(1, $base->filter($this->input('description')), 'a base carries the description');
        self::assertCount(0, $base->filter($this->input('price')), 'and is not sold as itself');

        $sku = $this->newForm(ArticleModule::SKU);

        self::assertCount(1, $sku->filter($this->input('price')), 'a variant has its own price');
        self::assertCount(0, $sku->filter($this->input('description')), 'and shares the base description');
        self::assertCount(1, $sku->filter($this->input(ArticleModule::SKU_OF)), 'and says which base');
    }

    /** A plain article is asked for both, exactly as it always was. */
    public function testAPlainArticleIsUnchanged(): void
    {
        $plain = $this->newForm(ArticleModule::PLAIN);

        self::assertCount(1, $plain->filter($this->input('description')));
        self::assertCount(1, $plain->filter($this->input('price')));
        self::assertCount(0, $plain->filter($this->input(ArticleModule::SKU_OF)), 'and names no base');
    }

    /**
     * A variant takes the unit and the VAT rate from its base, and keeps its own
     * price.
     *
     * XIV-18's inheritance doing the work, which is why there is no code behind
     * this: the two fields carry `InheritedValue::from('sku_of', …)` and the save
     * fills whatever was left empty. The price is deliberately not on that list,
     * because a base has none to give.
     */
    public function testAVariantTakesTheUnitAndTheRateFromItsBase(): void
    {
        $base = $this->base('T-Shirt', unit: Units::PIECE, taxRate: '8.10');
        $large = $this->sku('T-Shirt, L', $base, price: '29.00');

        $stored = $this->record(ArticleModule::KEY, $large);

        self::assertSame(Units::PIECE, $stored->get('unit'), 'taken from the base');
        self::assertSame('8.10', $stored->get('tax_rate'), 'so is the rate, which matters more: empty means no VAT');
        self::assertSame('29.00', $stored->get('price'), 'and the price is the variant own');
        self::assertSame($base, $stored->get(ArticleModule::SKU_OF));
    }

    /**
     * An order line naming a variant says what was sold, at the variant's price.
     *
     * Nothing in the order module knows this feature exists. The id it stores is
     * the variant's record id, and the description and price it inherits are the
     * variant's, because inheritance follows the reference rather than knowing
     * what is at the end of it.
     */
    public function testAnOrderLineSellsTheVariantAndInheritsItsPrice(): void
    {
        $base = $this->base('T-Shirt', unit: Units::PIECE, taxRate: '8.10');
        $large = $this->sku('T-Shirt, L', $base, price: '29.00');

        $order = $this->orderNaming($large);
        $line = $this->firstLineOf($order);

        self::assertSame($large, $line->get('article'), 'the line records which variant was sold');
        self::assertSame('T-Shirt, L', $line->get('description'), 'and inherits its title');
        self::assertSame('29.00', $line->get(OrderModule::UNIT_PRICE), 'and its price');
        self::assertSame('8.10', $line->get(OrderModule::TAX_RATE), 'and the rate the variant took from the base');
    }

    /**
     * The base is not offered on an order line, which is the answer to whether it
     * stays sellable: it does not.
     *
     * An order for "T-Shirt ×3" is an order nobody in a warehouse can fulfil, so
     * the picker narrows to the kinds that are things ([XIV-172]'s list form of
     * the option, `ArticleModule::SELLABLE`).
     */
    public function testTheBaseIsNotOfferedOnAnOrderLine(): void
    {
        $base = $this->base('T-Shirt', unit: Units::PIECE, taxRate: '8.10');
        $large = $this->sku('T-Shirt, L', $base, price: '29.00');
        $plain = $this->plain('Desk lamp', price: '19.90');

        $offered = $this->articlesOfferedOnALine($this->orderNaming($large));

        self::assertContains($large, $offered, 'a variant is a thing somebody can buy');
        self::assertContains($plain, $offered, 'and so is a plain article');
        self::assertNotContains($base, $offered, 'the base is not');
    }

    /**
     * The base's own page lists its variants, and nothing was written to make
     * that happen.
     *
     * XIV-52's reverse-link card, which draws every record of every installed
     * module pointing at this one. `sku_of` points at articles, so a base gets a
     * card of articles: the answer to "where are my sizes?" arrives with the
     * link rather than after it.
     */
    public function testABasePageListsItsVariants(): void
    {
        $base = $this->base('T-Shirt', unit: Units::PIECE, taxRate: '8.10');
        $this->sku('T-Shirt, L', $base, price: '29.00');
        $this->sku('T-Shirt, M', $base, price: '27.00');

        $page = $this->client->request('GET', $this->url('/m/article/' . $base))->filter('main')->text();

        self::assertStringContainsString('T-Shirt, L', $page);
        self::assertStringContainsString('T-Shirt, M', $page);
    }

    /** And the other way round: only a base may be picked as a variant parent. */
    public function testOnlyABaseMayBeAVariantParent(): void
    {
        $base = $this->base('T-Shirt', unit: Units::PIECE, taxRate: '8.10');
        $large = $this->sku('T-Shirt, L', $base, price: '29.00');
        $plain = $this->plain('Desk lamp', price: '19.90');

        $crawler = $this->newForm(ArticleModule::SKU);
        $offered = $crawler
            ->filter(sprintf('select[name="%s"] option', self::field(ArticleModule::SKU_OF)))
            ->each(static fn ($node): string => (string) $node->attr('value'));

        self::assertContains((string) $base, $offered);
        self::assertNotContains((string) $plain, $offered, 'a plain article would be left sellable beside its own variants');
        self::assertNotContains((string) $large, $offered, 'and a chain of variants is impossible rather than discouraged');
    }

    /**
     * A plain article that turns out to sell in sizes becomes a base by changing
     * one dropdown, and every order that already named it is untouched.
     *
     * §5.21's retroactivity question, answered by there being nothing to be
     * retroactive: an order line holds its own copy of the title and the price
     * (XIV-18), so nothing reads through the article at all. The price the base
     * no longer shows is still in storage, which is §5.5's rule about a field
     * another kind does not carry.
     */
    public function testAnExistingOrderSurvivesItsArticleGrowingVariants(): void
    {
        $article = $this->plain('T-Shirt', price: '25.00', taxRate: '8.10');
        $order = $this->orderNaming($article, price: '25.00');

        // The kind changed on the form the record already had, which is what a
        // person does: the component builds the form for the kind in storage, so
        // the price control is still on the page for the save that takes the
        // price away. It is the *next* form that stops asking.
        $this->saveRecord(
            ArticleModule::KEY,
            [
                ArticleModule::KIND => ArticleModule::BASE,
                'title' => 'T-Shirt',
                'description' => 'Cotton.',
                'price' => '25.00',
                'tax_rate' => '8.10',
            ],
            recordId: $article,
        );

        $stored = $this->record(ArticleModule::KEY, $article);
        self::assertSame(ArticleModule::BASE, $stored->get(ArticleModule::KIND));
        self::assertSame('25.00', $stored->get('price'), 'the value stays even though the form stopped asking');
        self::assertCount(
            0,
            $this->client->request('GET', $this->url('/m/article/' . $article . '/edit'))->filter($this->input('price')),
            'and the next form does stop asking',
        );

        $line = $this->firstLineOf($order);
        self::assertSame($article, $line->get('article'), 'the order still points where it pointed');
        self::assertSame('25.00', $line->get(OrderModule::UNIT_PRICE), 'and says what it said');
        self::assertSame('T-Shirt', $line->get('description'));
    }

    // -- fixtures -----------------------------------------------------------

    private function plain(string $title, string $price, string $taxRate = '8.10'): int
    {
        return $this->savedId($this->saveRecord(ArticleModule::KEY, [
            ArticleModule::KIND => ArticleModule::PLAIN,
            'title' => $title,
            'price' => $price,
            'tax_rate' => $taxRate,
        ], variant: ArticleModule::PLAIN));
    }

    private function base(string $title, string $unit, string $taxRate): int
    {
        return $this->savedId($this->saveRecord(ArticleModule::KEY, [
            ArticleModule::KIND => ArticleModule::BASE,
            'title' => $title,
            'description' => 'Cotton, printed here.',
            'unit' => $unit,
            'tax_rate' => $taxRate,
        ], variant: ArticleModule::BASE));
    }

    private function sku(string $title, int $base, string $price): int
    {
        return $this->savedId($this->saveRecord(ArticleModule::KEY, [
            ArticleModule::KIND => ArticleModule::SKU,
            'title' => $title,
            ArticleModule::SKU_OF => $base,
            'price' => $price,
        ], variant: ArticleModule::SKU));
    }

    /** An order of one article line, with everything but the article left to inheritance. */
    private function orderNaming(int $article, string $price = ''): int
    {
        $contact = $this->savedId($this->saveRecord(ContactModule::KEY, [
            'kind' => 'company',
            'company_name' => 'Sku AG',
        ], variant: 'company'));

        return $this->savedId($this->saveRecord(
            OrderModule::KEY,
            ['contact' => $contact, 'ordered_on' => '2026-08-21', 'status' => OrderModule::DRAFT],
            [OrderModule::LINES => [[
                'id' => '',
                'position' => '10',
                'fields' => [
                    OrderModule::KIND => OrderModule::ARTICLE_LINE,
                    'article' => (string) $article,
                    'description' => '',
                    OrderModule::QUANTITY => '1',
                    OrderModule::UNIT_PRICE => $price,
                ],
            ]]],
        ));
    }

    // -- reading ------------------------------------------------------------

    /** @return list<int> */
    private function articlesOfferedOnALine(int $order): array
    {
        $crawler = $this->client->request('GET', $this->url('/m/order/' . $order . '/edit'));
        self::assertResponseIsSuccessful();

        return array_values(array_filter($crawler
            ->filter('select[id*="article"]')
            ->first()
            ->filter('option')
            ->each(static fn (Crawler $node): int => (int) $node->attr('value'))));
    }

    private function firstLineOf(int $order): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($order): Record {
            $module = self::service(MetadataRepository::class)->get(OrderModule::KEY);
            $lines = $module->getCollection(OrderModule::LINES);
            self::assertNotNull($lines);

            $rows = self::service(RecordRepository::class)->findChildren($lines, $order);
            self::assertNotSame([], $rows, 'the order kept its line');

            return $rows[0];
        });
    }

    private function record(string $moduleKey, int $id): Record
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($moduleKey, $id): Record {
            $module = self::service(MetadataRepository::class)->get($moduleKey);
            $record = self::service(RecordRepository::class)->find($module, $id);
            self::assertNotNull($record);

            return $record;
        });
    }

    // -- plumbing -----------------------------------------------------------

    private function newForm(string $variant): Crawler
    {
        $crawler = $this->client->request('GET', $this->url('/m/article/new?variant=' . $variant));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function input(string $key): string
    {
        return sprintf('[name="%s"]', self::field($key));
    }

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
    }

    private function signIn(): void
    {
        $this->client->request('GET', $this->url('/login'));
        $this->client->submitForm('Sign in', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
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

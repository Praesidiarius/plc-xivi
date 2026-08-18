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
use Symfony\Component\HttpFoundation\Response;
use Xivi\Article\ArticleModule;
use Xivi\Core\Field\Units;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * The second module, and the two field types it brought (XIV-11).
 *
 * The point of the class is what is *not* in it: no controller, no form type and
 * no template were written for articles, so every page here is the same generic
 * pair that serves contacts, reading a different set of definitions. A module
 * that needed more than a declaration would mean the engine had been built
 * around the first one (§1).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ArticleModuleTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_article';
    private const string HOST = 'article.localhost';
    private const string EMAIL = 'article@example.test';
    private const string PASSWORD = 'article-password';
    private const string FORM = 'module_record';

    private KernelBrowser $client;
    private ?Response $saved = null;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ArticleModule::KEY),
            ),
        );

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Article', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /** A declaration is the whole module: the form comes out of the definitions. */
    public function testTheFormIsBuiltFromTheArticleDefinitions(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/article/new'));

        self::assertResponseIsSuccessful();

        foreach (['title', 'description', 'price'] as $field) {
            self::assertSelectorExists(sprintf('[name="%s"]', self::field($field)));
        }

        // The widget comes from the field type: a description is a box, not a line.
        self::assertCount(1, $crawler->filter(sprintf('textarea[name="%s"]', self::field('description'))));
        self::assertSelectorTextContains(sprintf('label[for="%s_fields_price"]', self::FORM), 'Price');
    }

    /**
     * The price is drawn as a Bootstrap input group with the currency beside it.
     *
     * Symfony's MoneyType and the Bootstrap theme's own `money_widget` do this;
     * which side the currency lands on is the reader's locale's business, and
     * this asserts the group rather than the side for that reason.
     */
    public function testThePriceCarriesTheInstancesCurrency(): void
    {
        $this->setCurrency('CHF');

        $crawler = $this->client->request('GET', $this->url('/m/article/new'));
        $group = $crawler->filter(sprintf('.input-group:has([name="%s"])', self::field('price')));

        self::assertCount(1, $group, 'the price is drawn as an input group');
        self::assertStringContainsString('CHF', $group->filter('.input-group-text')->text());
    }

    /**
     * Nobody has chosen a currency, which is where every installation starts
     * (§8.6). A plain input then — an empty box in front of the number would
     * read as a rendering fault.
     */
    public function testWithNoCurrencyChosenThePriceIsJustANumber(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/article/new'));

        self::assertSelectorExists(sprintf('[name="%s"]', self::field('price')));
        self::assertCount(0, $crawler->filter(sprintf('.input-group:has([name="%s"])', self::field('price'))));
    }

    /** The round trip, to the cent. */
    public function testAnArticleKeepsItsPriceExactly(): void
    {
        $this->setCurrency('CHF');
        $this->submit(['title' => 'Desk lamp', 'description' => "Brass.\nTwo bulbs.", 'price' => '19.90']);

        $crawler = $this->client->request('GET', $this->url('/m/article/' . $this->savedId($this->saved ?? new Response())));

        self::assertSelectorTextContains('h1', 'Desk lamp');
        self::assertStringContainsString('19.90', $crawler->filter('dl')->text());
        self::assertStringContainsString('Brass.', $crawler->filter('dl')->text());

        // Back on the form it is the stored value again, not a rounded float.
        $edit = $this->client->request('GET', $this->url('/m/article/1/edit'));

        self::assertSame('19.90', $edit->filter(sprintf('[name="%s"]', self::field('price')))->attr('value'));
    }

    /** A price is shown with its currency wherever it is read, list included. */
    public function testTheListShowsThePriceWithTheCurrency(): void
    {
        $this->setCurrency('CHF');
        $this->submit(['title' => 'Desk lamp', 'price' => '19.90']);

        $text = $this->client->request('GET', $this->url('/m/article'))->filter('table')->text();

        self::assertStringContainsString('Desk lamp', $text);
        self::assertStringContainsString('19.90', $text);
        self::assertStringContainsString('CHF', $text);
    }

    /**
     * The description is not a column. A paragraph in a table squeezes every
     * other column into nothing, and the list is for finding an article.
     */
    public function testTheDescriptionIsNotAListColumn(): void
    {
        $this->submit(['title' => 'Desk lamp', 'description' => 'A very distinctive description']);

        $text = $this->client->request('GET', $this->url('/m/article'))->filter('table')->text();

        self::assertStringContainsString('Desk lamp', $text);
        self::assertStringNotContainsString('A very distinctive description', $text);
    }

    /**
     * **An article says what its price is a price of** (XIV-118).
     *
     * A closed list of seven, shipped rather than invented per customer and
     * seeded into their definitions like every other option (§6.1) — which is
     * why this asserts against the *definitions* by reading the rendered select
     * rather than against {@see Units} directly. What is on the page is what the
     * tenant has, and after XIV-127 those two are allowed to differ.
     */
    public function testAnArticleIsSoldInAUnit(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/article/new'));
        $options = $crawler
            ->filter(sprintf('select[name="%s"] option', self::field('unit')))
            ->each(static fn ($node): string => trim((string) $node->attr('value')));

        self::assertSame(
            ['', ...array_keys(Units::shipped()['choices'])],
            $options,
            'the seven, and an empty one first because an article need not have a unit',
        );
    }

    /** And it prints the customer's word for it, not the key the record holds. */
    public function testTheUnitIsShownAsItsLabel(): void
    {
        $this->submit(['title' => 'Consulting', 'price' => '180.00', 'unit' => Units::HOUR]);

        $page = $this->client->request('GET', $this->url('/m/article/' . $this->savedId($this->saved ?? new Response())));

        self::assertStringContainsString('hours', $page->filter('dl')->text());
    }

    /**
     * **An article with no unit is an ordinary article**, which is the whole of
     * why the field is optional: a yearly maintenance fee is sold as itself, and
     * so is every article that existed before this field did.
     */
    public function testAnArticleWithoutAUnitSaves(): void
    {
        $this->submit(['title' => 'Wartung Jahrespauschale', 'price' => '900.00']);

        self::assertNotNull($this->saved?->headers->get('Location'), 'it saved');

        $page = $this->client->request('GET', $this->url('/m/article/' . $this->savedId($this->saved ?? new Response())));

        self::assertSelectorTextContains('h1', 'Wartung Jahrespauschale');
        self::assertStringNotContainsString('hours', $page->filter('dl')->text());
    }

    /** A negative price is a refund, which is a different thing with a name of its own. */
    public function testANegativePriceIsRefused(): void
    {
        $this->submit(['title' => 'Desk lamp', 'price' => '-5.00']);

        // Refused, so the component comes back with the reason rather than
        // redirecting to a record that should not exist.
        //
        // **Not a 422** (XIV-33): a refused save is a component that re-rendered,
        // which is a successful render, so the status says 200 and only the body
        // says no. Written down here because it is the one thing the migration
        // took away that anything speaking HTTP could previously read.
        self::assertNull($this->saved?->headers->get('Location'), 'nothing was saved');
        self::assertStringContainsString('invalid-feedback', (string) $this->saved?->getContent());
    }

    // -- helpers ------------------------------------------------------------

    /** @param array<string, string> $values */
    private function submit(array $values): void
    {
        $this->saved = $this->saveRecord(ArticleModule::KEY, $values);
    }

    private function setCurrency(string $code): void
    {
        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(TenantProfileManager::class)->apply('Acme AG', $code),
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

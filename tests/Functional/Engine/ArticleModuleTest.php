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
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Article\ArticleModule;
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
    use SharesATenant;

    private const string SLUG = 'test_article';
    private const string HOST = 'article.localhost';
    private const string EMAIL = 'article@example.test';
    private const string PASSWORD = 'article-password';
    private const string FORM = 'module_record';

    private KernelBrowser $client;
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
     * What the acceptance criteria asked for in as many words: the currency in
     * front of the price, as a Bootstrap input group.
     */
    public function testThePriceCarriesTheInstancesCurrencyInFrontOfIt(): void
    {
        $this->setCurrency('CHF');

        $crawler = $this->client->request('GET', $this->url('/m/article/new'));
        $group = $crawler->filter(sprintf('.input-group:has([name="%s"])', self::field('price')));

        self::assertCount(1, $group, 'the price is drawn as an input group');
        self::assertSame('CHF', trim($group->filter('.input-group-text')->text()));
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

        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();

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

    /** A negative price is a refund, which is a different thing with a name of its own. */
    public function testANegativePriceIsRefused(): void
    {
        $this->submit(['title' => 'Desk lamp', 'price' => '-5.00']);

        // Refused, so the form comes back with the reason rather than
        // redirecting to a record that should not exist.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists('.invalid-feedback');
    }

    // -- helpers ------------------------------------------------------------

    /** @param array<string, string> $values */
    private function submit(array $values): void
    {
        $this->client->request('GET', $this->url('/m/article/new'));

        $fields = [];
        foreach ($values as $key => $value) {
            $fields[self::field($key)] = $value;
        }

        $this->client->submitForm('Save', $fields);
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

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
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Module\ModuleRequirementMissing;
use Xivi\Order\OrderModule;

/**
 * What a module needs from the customer before it is worth installing (XIV-23).
 *
 * Not the dependency §3 forbids — that one is about code, and the order package
 * imports nothing from contact or article. This is the runtime half: an order
 * names a contact, so a customer with no contacts cannot have orders, and
 * finding that out from a validation error on an empty picker is the same
 * information delivered worse.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleRequirementsTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_requirements';
    private const string HOST = 'requirements.localhost';
    private const string EMAIL = 'requires@example.test';
    private const string PASSWORD = 'requires-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Req', self::PASSWORD, ['ROLE_ADMIN']);
    }

    /** An order with nowhere to point is refused, and says what to install. */
    public function testAModuleIsRefusedWhenItsRequirementIsMissing(): void
    {
        $this->expectException(ModuleRequirementMissing::class);
        $this->expectExceptionMessage('needs contact');

        $this->install(OrderModule::KEY);
    }

    /** With the requirement in place, it installs. */
    public function testItInstallsOnceTheRequirementIsThere(): void
    {
        $this->install(ContactModule::KEY);
        $module = $this->install(OrderModule::KEY);

        self::assertSame(OrderModule::KEY, $module->getKey());
    }

    /**
     * An optional module is optional: a service business sells custom lines and
     * has no articles at all.
     */
    public function testAnOptionalModuleIsNotRequired(): void
    {
        $this->install(ContactModule::KEY);
        $this->install(OrderModule::KEY);

        $this->signIn();
        $kinds = $this->kindsOffered();

        self::assertNotContains(OrderModule::ARTICLE_LINE, $kinds, 'nothing to point an article line at');
        self::assertSame(
            [OrderModule::CUSTOM_LINE, OrderModule::COMMENT_LINE, OrderModule::SUBTOTAL_LINE],
            $kinds,
            'and the rest work as normal',
        );
    }

    /** Install the articles module and the kind appears, with nothing else done. */
    public function testTheKindAppearsOnceItsModuleIsInstalled(): void
    {
        $this->install(ContactModule::KEY);
        $this->install(OrderModule::KEY);
        $this->install(ArticleModule::KEY);

        $this->signIn();

        self::assertSame(
            [
                OrderModule::ARTICLE_LINE,
                OrderModule::CUSTOM_LINE,
                OrderModule::COMMENT_LINE,
                OrderModule::SUBTOTAL_LINE,
            ],
            $this->kindsOffered(),
        );
    }

    // -- helpers ------------------------------------------------------------

    /**
     * The kinds of line the order form offers, read off its buttons (XIV-29).
     *
     * The form draws no rows until one is pressed, so what a customer is offered
     * is exactly the list of buttons.
     *
     * @return list<string>
     */
    private function kindsOffered(): array
    {
        return $this->client->request('GET', $this->url('/m/order/new'))
            ->filter('[data-live-action-param="addRow"][data-live-collection-param="lines"]')
            ->each(static fn (Crawler $node): string => (string) $node->attr('data-live-kind-param'));
    }

    private function install(string $key): \Xivi\Core\Entity\ModuleDefinition
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): \Xivi\Core\Entity\ModuleDefinition => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get($key),
            ),
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

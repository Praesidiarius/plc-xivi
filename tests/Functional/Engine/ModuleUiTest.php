<?php

declare(strict_types=1);

namespace App\Tests\Functional\Engine;

use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * The module UI, driven through the browser.
 *
 * There is no ContactController and no ContactType — every page here is built
 * from the customer's field definitions by one generic controller and one
 * generic form. That is the part of §5 that only a browser can prove: the
 * metadata drives the *form*, not just the storage.
 */
final class ModuleUiTest extends WebTestCase
{
    private const string ALPHA = 'test_ui_alpha';
    private const string BETA = 'test_ui_beta';
    private const string HOST = 'ui-alpha.localhost';
    private const string EMAIL = 'ui@example.test';
    private const string PASSWORD = 'ui-password';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->removeTenants();

        $provisioner = self::service(TenantProvisioner::class);
        $alpha = $provisioner->provision(self::ALPHA, 'Alpha', [self::HOST]);
        $beta = $provisioner->provision(self::BETA, 'Beta', ['ui-beta.localhost']);

        $switcher = self::service(TenantSwitcher::class);
        $blueprint = self::service(ModuleRegistry::class)->get(ContactModule::KEY);

        // Alpha gets the module; beta deliberately does not.
        $switcher->runFor($alpha, fn () => self::service(ModuleInstaller::class)->install($blueprint));

        self::service(UserCreator::class)->create($alpha, self::EMAIL, 'UI', self::PASSWORD, ['ROLE_ADMIN']);
        self::service(UserCreator::class)->create($beta, self::EMAIL, 'UI', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    protected function tearDown(): void
    {
        $this->removeTenants();

        parent::tearDown();
    }

    public function testTheFormIsBuiltFromTheFieldDefinitions(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/new'));

        self::assertResponseIsSuccessful();

        foreach (['first_name', 'last_name', 'email', 'phone', 'birthday'] as $field) {
            self::assertSelectorExists(sprintf('[name="record[%s]"]', $field), $field . ' is on the form');
        }

        // The widget comes from the field type, not from any template.
        self::assertSame('email', $crawler->filter('[name="record[email]"]')->attr('type'));
        self::assertSame('date', $crawler->filter('[name="record[birthday]"]')->attr('type'));
        // And the label comes from the definition.
        self::assertSelectorTextContains('label[for="record_first_name"]', 'First name');
    }

    public function testCreatingARecordThroughTheForm(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'birthday' => '1815-12-10']);

        self::assertResponseRedirects($this->url('/m/contact'));
        $this->client->followRedirect();

        self::assertSelectorTextContains('table', 'Lovelace');
        // Rendered by the date type, not by the template guessing.
        self::assertSelectorTextContains('table', '1815-12-10');
    }

    public function testARequiredFieldIsEnforcedByItsDefinition(): void
    {
        $this->submitContact(['first_name' => '', 'last_name' => 'Babbage']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists('#record_first_name');
        self::assertStringContainsString('should not be null', (string) $this->client->getResponse()->getContent());
    }

    public function testUniquenessIsEnforcedThroughTheForm(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);
        $this->client->followRedirect();

        $this->submitContact(['first_name' => 'Someone', 'last_name' => 'Else', 'email' => 'ada@example.com']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('already uses this value', (string) $this->client->getResponse()->getContent());
    }

    public function testEditingARecord(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);
        $crawler = $this->client->followRedirect();

        $this->client->click($crawler->filter('a:contains("Edit")')->link());
        self::assertResponseIsSuccessful();
        // The form comes back filled from storage.
        self::assertSame('Ada', $this->client->getCrawler()->filter('[name="record[first_name]"]')->attr('value'));

        $this->client->submitForm('Save', ['record[first_name]' => 'Augusta']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('table', 'Augusta');
        self::assertSelectorTextNotContains('table', 'Ada ');
    }

    public function testDeletingARecord(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Nothing here yet');
    }

    /** A module the customer does not have is a 404 — another customer may well have it. */
    public function testAModuleThatIsNotInstalledIsNotFound(): void
    {
        // Signed in *on beta*: the firewall runs before the controller, so an
        // anonymous visitor would be redirected to login and never reach it.
        $this->signIn('ui-beta.localhost');

        $this->client->request('GET', 'https://ui-beta.localhost/m/contact');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRecordsAreNotVisibleToAnotherTenant(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('table', 'Lovelace');

        // Beta has no contact module at all, so even its own signed-in user
        // cannot reach the record by any route.
        $this->signIn('ui-beta.localhost');
        $this->client->request('GET', 'https://ui-beta.localhost/m/contact');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSignedOutVisitorsCannotReachRecords(): void
    {
        $this->client->request('POST', $this->url('/logout'));

        $this->client->request('GET', $this->url('/m/contact'));

        self::assertResponseRedirects($this->url('/login'));
    }

    /** @param array<string, string> $values */
    private function submitContact(array $values): void
    {
        $this->client->request('GET', $this->url('/m/contact/new'));

        $fields = [];
        foreach ($values as $key => $value) {
            $fields[sprintf('record[%s]', $key)] = $value;
        }

        $this->client->submitForm('Save', $fields);
    }

    private function signIn(?string $host = null): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', $host ?? self::HOST));
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

    private function removeTenants(): void
    {
        $provisioner = self::service(TenantProvisioner::class);
        $tenants = self::service(TenantRepository::class);

        foreach ([self::ALPHA, self::BETA] as $slug) {
            $tenant = $tenants->findOneBySlug($slug);

            if ($tenant instanceof Tenant) {
                $provisioner->deprovision($tenant);
            }
        }
    }
}

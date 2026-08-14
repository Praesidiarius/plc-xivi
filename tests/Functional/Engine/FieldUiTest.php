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

use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * The metadata editor through the browser (§5.4).
 *
 * The test that matters: a field added here appears on the record form, with
 * nothing deployed and no SQL typed. That is the engine's whole claim, finally
 * reachable by the person who owns the data.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FieldUiTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_fieldui';
    private const string HOST = 'fieldui.localhost';
    private const string ADMIN = 'admin@fieldui.test';
    private const string MEMBER = 'member@fieldui.test';
    private const string PASSWORD = 'field-password';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        // A class that adds and removes fields, so it needs each test to start
        // from the shipped ones — which is a rollback, not a new database (see
        // SharesATenant). The users are made inside it and go the same way.
        $tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($tenant, fn () => self::service(ModuleInstaller::class)->install(
            self::service(ModuleRegistry::class)->get(ContactModule::KEY),
        ));

        $users = self::service(UserCreator::class);
        $users->create($tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        $users->create($tenant, self::MEMBER, 'Member', self::PASSWORD, []);
    }

    /**
     * Changing what a module *is* needs more than being signed in — the first
     * thing in the application that does (§8.4).
     */
    public function testAnOrdinaryUserCannotReachTheEditor(): void
    {
        $this->signIn(self::MEMBER);

        $this->client->request('GET', $this->url('/m/contact/fields'));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testTheEditorListsTheModulesFieldsAndItsCollections(): void
    {
        $this->signIn(self::ADMIN);

        $crawler = $this->client->request('GET', $this->url('/m/contact/fields'));

        self::assertResponseIsSuccessful();

        $text = $crawler->filter('main')->text();
        foreach (['first_name', 'birthday', 'Addresses', 'postal_code'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
    }

    /** The claim, end to end: a row becomes a form field. */
    public function testAddingAFieldPutsItOnTheRecordForm(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField(['key' => 'vat_number', 'label' => 'VAT number', 'type' => 'text']);

        self::assertSelectorTextContains('.alert', 'Added "VAT number"');

        $this->client->request('GET', $this->url('/m/contact/new?variant=person'));

        self::assertSelectorExists('[name="module_record[fields][vat_number]"]');
        // The label comes from the definition row, like every other field's.
        self::assertSelectorTextContains('label[for="module_record_fields_vat_number"]', 'VAT number');
    }

    /** And, when marked filterable, into the filter bar — the same rows again. */
    public function testAFilterableFieldReachesTheFilterBar(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField(['key' => 'vat_number', 'label' => 'VAT number', 'type' => 'text', 'filterable' => '1']);

        $crawler = $this->client->request('GET', $this->url('/m/contact'));

        self::assertStringContainsString('VAT number', $crawler->filter('form[method="get"]')->text());
    }

    /** The crowding fix: a new field does not widen the list uninvited (§5.4). */
    public function testANewFieldIsNotAListColumnUnlessAsked(): void
    {
        $this->signIn(self::ADMIN);
        $this->addContact('Ada', 'Lovelace');
        $this->addField(['key' => 'vat_number', 'label' => 'VAT number', 'type' => 'text']);

        $crawler = $this->client->request('GET', $this->url('/m/contact'));
        self::assertStringNotContainsString('VAT number', $crawler->filter('thead')->text());

        // …but it is on the record, where everything is.
        $this->client->request('GET', $this->url('/m/contact/new?variant=person'));
        self::assertSelectorExists('[name="module_record[fields][vat_number]"]');
    }

    public function testAFieldMarkedForTheListBecomesAColumn(): void
    {
        $this->signIn(self::ADMIN);
        $this->addContact('Ada', 'Lovelace');
        $this->addField(['key' => 'vat_number', 'label' => 'VAT number', 'type' => 'text', 'listed' => '1']);

        $crawler = $this->client->request('GET', $this->url('/m/contact'));

        self::assertStringContainsString('VAT number', $crawler->filter('thead')->text());
    }

    public function testAKeyThatIsNotAnIdentifierIsRefused(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField(['key' => 'VAT Number', 'label' => 'Nope', 'type' => 'text']);

        self::assertSelectorTextContains('.alert', 'must start with a letter');
    }

    /**
     * The confirmation exists to say what removal does *not* do, because
     * somebody clicking it reasonably assumes the data goes too.
     */
    public function testTheDeleteConfirmationSaysTheValuesStay(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField(['key' => 'vat_number', 'label' => 'VAT number', 'type' => 'text']);

        $crawler = $this->client->request('GET', $this->url('/m/contact/fields'));
        $crawler = $this->client->click($crawler->filter('a:contains("Remove")')->link());

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('values stay in storage', $text);
        self::assertStringContainsString('No records currently hold a value', $text);

        $this->client->submit($crawler->selectButton('Remove the field')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert', 'values are still stored');
        self::assertSelectorNotExists('[name="module_record[fields][vat_number]"]');
    }

    /** A field that came with the module is not the customer's to remove (§7.2). */
    public function testAModulesOwnFieldOffersNoRemoveButton(): void
    {
        $this->signIn(self::ADMIN);

        $crawler = $this->client->request('GET', $this->url('/m/contact/fields'));

        self::assertStringContainsString('module field', $crawler->filter('main')->text());
        // Every field is the module's own at this point, so nothing is removable.
        self::assertCount(0, $crawler->filter('a:contains("Remove")'));
    }

    /** The list only renders a table when there is something in it. */
    private function addContact(string $first, string $last): void
    {
        $this->client->request('GET', $this->url('/m/contact/new?variant=person'));
        $this->client->submitForm('Save', [
            'module_record[fields][first_name]' => $first,
            'module_record[fields][last_name]' => $last,
        ]);
        $this->client->followRedirect();
    }

    /** @param array<string, string> $values */
    private function addField(array $values): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/fields'));
        $form = $crawler->filter('form[action$="/fields/add"]')->first()->form();

        foreach ($values as $name => $value) {
            $form[$name] = $value;
        }

        $this->client->submit($form);
        $this->client->followRedirect();
    }

    private function signIn(string $email): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => $email,
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

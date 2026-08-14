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
use Symfony\Component\DomCrawler\Crawler;
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
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleUiTest extends WebTestCase
{
    use SharesATenant;

    private const string ALPHA = 'test_ui_alpha';
    private const string BETA = 'test_ui_beta';
    private const string HOST = 'ui-alpha.localhost';
    private const string EMAIL = 'ui@example.test';
    private const string PASSWORD = 'ui-password';

    /**
     * The form's name, from the generic type that builds it. A record's own
     * values live under "fields" and its collections under "collections", so
     * that a customer may call a field anything without it colliding with the
     * name of a collection.
     */
    private const string FORM = 'module_record';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        // Both tenants belong to the class; each test is rolled back in both of
        // them (see SharesATenant).
        $alpha = $this->sharedTenant(self::ALPHA, [self::HOST]);
        $beta = $this->sharedTenant(self::BETA, ['ui-beta.localhost']);

        $switcher = self::service(TenantSwitcher::class);
        $blueprint = self::service(ModuleRegistry::class)->get(ContactModule::KEY);

        // Alpha gets the module; beta deliberately does not.
        $switcher->runFor($alpha, fn () => self::service(ModuleInstaller::class)->install($blueprint));

        foreach ([$alpha, $beta] as $tenant) {
            self::service(UserCreator::class)->create($tenant, self::EMAIL, 'UI', self::PASSWORD, ['ROLE_ADMIN']);
        }

        $this->signIn();
    }

    public function testTheFormIsBuiltFromTheFieldDefinitions(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/new?variant=person'));

        self::assertResponseIsSuccessful();

        foreach (['first_name', 'last_name', 'email', 'phone', 'birthday'] as $field) {
            self::assertSelectorExists(sprintf('[name="%s"]', self::field($field)), $field . ' is on the form');
        }

        // The widget comes from the field type, not from any template.
        self::assertSame('email', $crawler->filter(sprintf('[name="%s"]', self::field('email')))->attr('type'));
        self::assertSame('date', $crawler->filter(sprintf('[name="%s"]', self::field('birthday')))->attr('type'));
        // And the label comes from the definition.
        self::assertSelectorTextContains(sprintf('label[for="%s_fields_first_name"]', self::FORM), 'First name');
    }

    /**
     * A collection's fields are described by the same kind of row as the
     * module's own, so they reach the form the same way — no address template,
     * no address form type, nothing in the UI that knows what an address is.
     */
    public function testACollectionsFieldsAreOnTheFormToo(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/new?variant=person'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Addresses');

        foreach (['label', 'street', 'postal_code', 'city', 'country'] as $field) {
            self::assertSelectorExists(sprintf('[name="%s"]', self::addressField(0, $field)), $field . ' is on the form');
        }

        // One blank row is always rendered, which is what lets the page work
        // with scripting turned off.
        self::assertCount(1, $crawler->filter('.row-of-collection'));
    }

    /** Saving lands on the record that was just saved, not back at the list. */
    public function testCreatingARecordThroughTheForm(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'birthday' => '1815-12-10']);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        // Named by the fields the module says it cannot exist without.
        self::assertSelectorTextContains('h1', 'Ada Lovelace');
        // Rendered by the date type, not by the template guessing.
        self::assertSelectorTextContains('dl', '1815-12-10');
    }

    public function testANewRecordAppearsInTheList(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'birthday' => '1815-12-10']);

        $this->client->request('GET', $this->url('/m/contact'));

        self::assertSelectorTextContains('table', 'Lovelace');
        self::assertSelectorTextContains('table', '1815-12-10');
    }

    /**
     * The whole record, read-only, built from the same definitions as the form —
     * no show template that knows what a contact is.
     */
    public function testTheRecordPageIsBuiltFromTheFieldDefinitions(): void
    {
        $this->submitContact(
            ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com'],
            [['street' => 'Baker Street 1', 'city' => 'Zürich']],
        );

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        foreach (['First name', 'Last name', 'Email', 'Phone', 'Birthday'] as $label) {
            self::assertSelectorTextContains('dl', $label);
        }

        self::assertSelectorTextContains('dl', 'ada@example.com');
        // An empty field says so rather than being left out.
        self::assertStringContainsString('—', $crawler->filter('dl')->text());

        // The collections are on the record too, as a table of their own fields.
        self::assertSelectorTextContains('h2', 'Addresses');
        self::assertSelectorTextContains('.card:contains("Addresses") table', 'Baker Street 1');
    }

    public function testARecordThatDoesNotExistIsNotFound(): void
    {
        $this->client->request('GET', $this->url('/m/contact/999'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** A deleted record is gone from the record page too, not only from the list. */
    public function testADeletedRecordCannotBeViewed(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $crawler = $this->client->followRedirect();
        $url = $this->client->getRequest()->getUri();

        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** owner_id is a system column; the name beside it is resolved by the application. */
    public function testARecordShowsWhoCreatedIt(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        // On the record itself, in the context column beside it…
        $crawler = $this->client->followRedirect();
        $aside = $crawler->filter('.col-lg-4')->text();
        self::assertStringContainsString('Owner', $aside);
        self::assertStringContainsString('UI', $aside);

        // …and in the list's owner column.
        $this->client->request('GET', $this->url('/m/contact'));
        self::assertSelectorTextContains('table', 'UI');
    }

    public function testARequiredFieldIsEnforcedByItsDefinition(): void
    {
        $this->submitContact(['first_name' => '', 'last_name' => 'Babbage']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorExists(sprintf('#%s_fields_first_name', self::FORM));
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

        $crawler = $this->openFirstRecordForEditing();
        // The form comes back filled from storage.
        self::assertSame('Ada', $crawler->filter(sprintf('[name="%s"]', self::field('first_name')))->attr('value'));

        $this->client->submitForm('Save', [self::field('first_name') => 'Augusta']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'Augusta Lovelace');

        $this->client->request('GET', $this->url('/m/contact'));
        self::assertSelectorTextContains('table', 'Augusta');
        self::assertSelectorTextNotContains('table', 'Ada ');
    }

    public function testDeletingARecord(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        $this->client->followRedirect();

        // The empty state, which points at the way in rather than reporting zero.
        self::assertSelectorTextContains('body', 'No contacts yet');
        self::assertSelectorExists('a:contains("Add the first one")');
    }

    public function testAddressesAreSavedWithTheContact(): void
    {
        $this->submitContact(
            ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
            [['street' => 'Baker Street 1', 'postal_code' => '8001', 'city' => 'Zürich']],
        );

        self::assertResponseRedirects();

        $crawler = $this->openFirstRecordForEditing();

        self::assertSame('Baker Street 1', $crawler->filter(sprintf('[name="%s"]', self::addressField(0, 'street')))->attr('value'));
        self::assertSame('Zürich', $crawler->filter(sprintf('[name="%s"]', self::addressField(0, 'city')))->attr('value'));
        // The one saved address, plus the blank row for the next one. The blank
        // row submitted alongside it was not stored.
        self::assertCount(2, $crawler->filter('.row-of-collection'));
    }

    /**
     * The point of carrying the id in the form: a second save updates the row
     * that is already there instead of replacing it with a new one.
     */
    public function testEditingKeepsAnAddressAndCanAddAnother(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace'], [['street' => 'Baker Street 1', 'city' => 'Zürich']]);

        $crawler = $this->openFirstRecordForEditing();
        $addressId = $crawler->filter(sprintf('[name="%s[collections][addresses][0][id]"]', self::FORM))->attr('value');
        self::assertNotSame('', (string) $addressId);

        $this->client->submitForm('Save', [
            self::addressField(0, 'street') => 'Baker Street 2',
            // The blank row the page always renders is where the second one goes.
            self::addressField(1, 'street') => 'Bahnhofstrasse 5',
            self::addressField(1, 'city') => 'Bern',
        ]);

        $crawler = $this->openFirstRecordForEditing();

        self::assertSame('Baker Street 2', $crawler->filter(sprintf('[name="%s"]', self::addressField(0, 'street')))->attr('value'));
        self::assertSame('Bahnhofstrasse 5', $crawler->filter(sprintf('[name="%s"]', self::addressField(1, 'street')))->attr('value'));
        // Still the same row, edited — not deleted and re-created.
        self::assertSame($addressId, $crawler->filter(sprintf('[name="%s[collections][addresses][0][id]"]', self::FORM))->attr('value'));
        self::assertCount(3, $crawler->filter('.row-of-collection'));
    }

    /** Emptying a row is how it is removed without scripting. */
    public function testClearingAnAddressRemovesIt(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace'], [['street' => 'Baker Street 1', 'city' => 'Zürich']]);

        $this->openFirstRecordForEditing();
        $this->client->submitForm('Save', [
            self::addressField(0, 'street') => '',
            self::addressField(0, 'city') => '',
        ]);

        $crawler = $this->openFirstRecordForEditing();

        // Only the blank row is left.
        self::assertCount(1, $crawler->filter('.row-of-collection'));
        self::assertSame('', (string) $crawler->filter(sprintf('[name="%s"]', self::addressField(0, 'street')))->attr('value'));
    }

    /** An address is validated by its own definitions, like anything else. */
    public function testARequiredFieldOnACollectionIsEnforced(): void
    {
        $this->submitContact(
            ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
            // A city with no street: the row is not blank, so it is checked, and
            // street is required by its definition.
            [['city' => 'Zürich']],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('should not be null', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The half of history core cannot do on its own: who did it. Core dispatches
     * what changed and the application adds the person, so this only shows up
     * through a signed-in request (§5.2).
     */
    public function testTheHistoryNamesWhoChangedWhat(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->openFirstRecordForEditing();
        $this->client->submitForm('Save', [self::field('first_name') => 'Augusta']);

        $crawler = $this->openFirstRecord();
        $history = $crawler->filter('.card:contains("History")')->text();

        self::assertStringContainsString('Updated by UI', $history);
        self::assertStringContainsString('Created by UI', $history);
        // What changed, from the definition's label and the stored values.
        self::assertStringContainsString('First name: Ada → Augusta', $history);
    }

    /** An address added to an existing contact is one entry on the contact. */
    public function testTheHistoryShowsCollectionChangesOnTheParent(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->openFirstRecordForEditing();
        $this->client->submitForm('Save', [
            self::addressField(0, 'street') => 'Baker Street 1',
            self::addressField(0, 'city') => 'Zürich',
        ]);

        $history = $this->openFirstRecord()->filter('.card:contains("History")')->text();

        self::assertStringContainsString('Addresses added', $history);
        // In the order the definitions declare, not the order jsonb hands back:
        // street comes before city on the form, so it does here too.
        self::assertMatchesRegularExpression('/Baker Street 1,\s*Zürich/u', $history);
    }

    /**
     * The filter bar is built from the definitions too: a field appears in it
     * because the customer marked it filterable, not because anyone wrote it
     * into a template (§7.3).
     */
    public function testFilteringThroughTheList(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->submitContact(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $crawler = $this->client->request('GET', $this->url('/m/contact'));
        $this->client->submit($crawler->selectButton('Filter')->form([
            'filter[0][path]' => 'last_name',
            'filter[0][op]' => 'contains',
            'filter[0][value]' => 'hopp',
        ]));

        self::assertSelectorTextContains('table', 'Grace');
        self::assertSelectorTextNotContains('table', 'Ada');
        self::assertSelectorTextContains('main', '1 record matching');
    }

    /** A contact is found by where they live, one step away in another table. */
    public function testFilteringByACollectionField(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace'], [['street' => 'A 1', 'city' => 'Zürich']]);
        $this->submitContact(['first_name' => 'Grace', 'last_name' => 'Hopper'], [['street' => 'B 2', 'city' => 'Bern']]);

        $this->client->request('GET', $this->url('/m/contact?filter[0][path]=addresses.city&filter[0][op]=eq&filter[0][value]=Bern'));

        self::assertSelectorTextContains('table', 'Grace');
        self::assertSelectorTextNotContains('table', 'Ada');
    }

    public function testSortingByClickingAColumn(): void
    {
        $this->submitContact(['first_name' => 'Grace', 'last_name' => 'Hopper']);
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $crawler = $this->client->request('GET', $this->url('/m/contact'));
        // The first column is the record's name, which is the only thing every
        // variant has (§5.5); it sorts by the first field that makes it up.
        $crawler = $this->client->click($crawler->filter('thead a:contains("Name")')->link());

        self::assertSame('Ada Lovelace', trim($crawler->filter('tbody tr td')->first()->text()));

        // The same header again turns it round.
        $crawler = $this->client->click($crawler->filter('thead a:contains("Name")')->link());
        self::assertSame('Grace Hopper', trim($crawler->filter('tbody tr td')->first()->text()));
    }

    /**
     * The query is in the URL, so it can be typed. Something the engine will not
     * answer is a message, not a 500 — and not a list that silently ignored it
     * either, which would look like a result.
     */
    public function testAQueryTheEngineRefusesExplainsItself(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        // Consume the "Saved." flash, or it is the alert this would find.
        $this->client->followRedirect();

        $this->client->request('GET', $this->url('/m/contact?sort=addresses.city'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'two of them');
        // And it still shows the records, unsorted, rather than nothing at all.
        self::assertSelectorTextContains('table', 'Lovelace');
    }

    /**
     * A form cannot be drawn before it knows which variant it is for — a company
     * has no first name — and switching the fields live would need JavaScript
     * (§5.5). So it asks first.
     */
    public function testAddingARecordAsksWhichKindFirst(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/new'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'What kind?');

        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('Person', $text);
        self::assertStringContainsString('Company', $text);

        // No form yet: nothing to fill in until the question is answered.
        self::assertSelectorNotExists('[name="' . self::field('first_name') . '"]');
    }

    public function testACompanyIsAskedForItsNameAndNotForAFirstName(): void
    {
        $this->client->request('GET', $this->url('/m/contact/new?variant=company'));

        self::assertSelectorExists('[name="' . self::field('company_name') . '"]');
        // The whole point of variants: a company is not asked for what it cannot
        // have, and so is not required to have it either.
        self::assertSelectorNotExists('[name="' . self::field('first_name') . '"]');
    }

    /** Both kinds are contacts, named by their own fields, in one list (§5.4). */
    public function testACompanyAndAPersonAppearInTheSameList(): void
    {
        $this->client->request('GET', $this->url('/m/contact/new?variant=company'));
        $this->client->submitForm('Save', [self::field('company_name') => 'Acme AG']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Acme AG');

        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $crawler = $this->client->request('GET', $this->url('/m/contact'));
        $names = $crawler->filter('tbody tr td:first-child')->each(static fn ($td): string => trim($td->text()));

        sort($names);
        self::assertSame(['Acme AG', 'Ada Lovelace'], $names);
    }

    /**
     * The link (§7.6): a person picks a company, and the picker offers companies
     * rather than every contact, because the reference names a variant.
     */
    public function testAPersonCanBeLinkedToACompanyThroughTheForm(): void
    {
        $this->client->request('GET', $this->url('/m/contact/new?variant=company'));
        $this->client->submitForm('Save', [self::field('company_name') => 'Acme AG']);
        $this->client->followRedirect();

        $this->submitContact(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $crawler = $this->openFirstRecordForEditing();
        $options = $crawler->filter('[name="' . self::field('company') . '"] option')
            ->each(static fn ($o): string => trim($o->text()));

        // The placeholder, and the one company. Not Grace, who is a person.
        self::assertContains('Acme AG', $options);
        self::assertNotContains('Grace Hopper', $options);
    }

    /** The download, and that it carries the filter you are looking at. */
    public function testExportingTheList(): void
    {
        $this->submitContact(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', $this->url('/m/contact'));
        $this->client->click($crawler->filter('a:contains("Export")')->link());

        $response = $this->client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));
        self::assertStringContainsString('contact-', (string) $response->headers->get('content-disposition'));
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
        self::assertSelectorTextContains('h1', 'Ada Lovelace');

        // Beta has no contact module at all, so even its own signed-in user
        // cannot reach the record by any route — not the list, and not the
        // record's own URL.
        $this->signIn('ui-beta.localhost');
        $this->client->request('GET', 'https://ui-beta.localhost/m/contact');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('GET', 'https://ui-beta.localhost/m/contact/1');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSignedOutVisitorsCannotReachRecords(): void
    {
        $this->client->request('POST', $this->url('/logout'));

        $this->client->request('GET', $this->url('/m/contact'));

        self::assertResponseRedirects($this->url('/login'));
    }

    /**
     * @param array<string, string>       $values
     * @param list<array<string, string>> $addresses rows for the addresses collection,
     *                                               in the order the form renders them
     */
    private function submitContact(array $values, array $addresses = []): void
    {
        $this->client->request('GET', $this->url('/m/contact/new?variant=person'));

        $fields = [];
        foreach ($values as $key => $value) {
            $fields[self::field($key)] = $value;
        }

        foreach ($addresses as $index => $address) {
            foreach ($address as $key => $value) {
                $fields[self::addressField($index, $key)] = $value;
            }
        }

        $this->client->submitForm('Save', $fields);
    }

    /** The record page of the only record in the list. */
    private function openFirstRecord(): Crawler
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact'));

        return $this->client->click($crawler->filter('a:contains("View")')->link());
    }

    /** Its edit form, which is a different page now. */
    private function openFirstRecordForEditing(): Crawler
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact'));

        return $this->client->click($crawler->filter('a:contains("Edit")')->link());
    }

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
    }

    private static function addressField(int $index, string $key): string
    {
        return sprintf('%s[collections][addresses][%d][fields][%s]', self::FORM, $index, $key);
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
}

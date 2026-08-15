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
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_fieldui';
    private const string HOST = 'fieldui.localhost';
    private const string ADMIN = 'admin@fieldui.test';
    /** Whose session the record form is saved under (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string MEMBER = 'member@fieldui.test';
    private const string PASSWORD = 'field-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        // A class that adds and removes fields, so it needs each test to start
        // from the shipped ones — which is a rollback, not a new database (see
        // SharesATenant). The users are made inside it and go the same way.
        $tenant = $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

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
        foreach (['first_name', 'birthday', 'postal_code'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }

        // A shape's label is an input now, because it can be renamed (XIV-8) —
        // and text() does not see what an input holds.
        $labels = $crawler->filter('input[name="label"]')->extract(['value']);

        self::assertContains('Addresses', $labels);
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

    /**
     * A field is as wide as its kind of field usually is (XIV-43).
     *
     * Nobody said anything about this field, so the answer comes from the type —
     * which is the case that decides how the application looks out of the box.
     */
    public function testAFieldIsDrawnAtItsTypesWidth(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField(['key' => 'vat_number', 'label' => 'VAT number', 'type' => 'text']);

        $crawler = $this->client->request('GET', $this->url('/m/contact/new?variant=person'));

        self::assertNotNull(
            $crawler->filter('[name="module_record[fields][vat_number]"]')->closest('.col-md-6'),
            'a text field is half a row',
        );
        self::assertSelectorExists('.row [name="module_record[fields][vat_number]"]', 'inside a grid, or the width means nothing');
    }

    /** A textarea gets the whole row, because that is what it is for. */
    public function testATextareaTakesTheWholeRow(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField(['key' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea']);

        $crawler = $this->client->request('GET', $this->url('/m/contact/new?variant=person'));

        $wrapper = $crawler->filter('[name="module_record[fields][remarks]"]')->closest('[class*="col-"]');

        self::assertNotNull($wrapper, 'it is still in the grid');
        self::assertStringNotContainsString('col-md-', (string) $wrapper->attr('class'), 'and nothing narrows it');
    }

    /** And somebody can disagree with the type, per field. */
    public function testAWidthCanBeSetOnOneField(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField(['key' => 'vat_number', 'label' => 'VAT number', 'type' => 'text']);

        $this->setWidth('vat_number', '3');

        $crawler = $this->client->request('GET', $this->url('/m/contact/new?variant=person'));

        self::assertNotNull(
            $crawler->filter('[name="module_record[fields][vat_number]"]')->closest('.col-md-3'),
            'the field carries what was chosen, not what the type wanted',
        );
    }

    /**
     * And setting one does not discard everything else about the field.
     *
     * XIV-26's lesson: the editor saves the whole field, so a control it has just
     * grown is one more thing that can wipe what it does not know about.
     */
    public function testSettingAWidthKeepsTheRestOfTheField(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField([
            'key' => 'vat_number',
            'label' => 'VAT number',
            'type' => 'text',
            'filterable' => '1',
            'listed' => '1',
        ]);

        $this->setWidth('vat_number', '4');

        $crawler = $this->client->request('GET', $this->url('/m/contact/fields'));
        $row = $crawler->filter('tbody tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->text(), 'vat_number'),
        )->first();

        self::assertSame('VAT number', $row->filter('[name="label"]')->attr('value'), 'the label is still there');
        self::assertCount(2, $row->filter('input[type="checkbox"][checked]'), 'and both rules it was given');
        self::assertSame('4', self::selected($row->filter('select[name="width"]')->getNode(0)), 'and the width stuck');

        // And filterable still means filterable, one layer out.
        $filters = $this->client->request('GET', $this->url('/m/contact'))->filter('form[method="get"]')->text();
        self::assertStringContainsString('VAT number', $filters);
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
        $this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => $first, 'last_name' => $last],
            variant: 'person',
        );
    }

    /**
     * Set one field's width through the editor, sending what the browser sends.
     *
     * The editor's controls sit in table cells and belong to their row's form
     * through the HTML5 `form` attribute — which a browser honours and
     * DomCrawler does not associate. So the association is done here: every
     * control pointing at this row's form, with its current value, plus the new
     * width. Sending only the width would look like somebody unticking every box
     * on the row, and the test would pass or fail for the wrong reason.
     */
    private function setWidth(string $key, string $width): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/fields'));

        $row = $crawler->filter('tbody tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->text(), $key),
        )->first();

        $form = $row->filter('form')->first();
        $id = (string) $form->attr('id');

        $values = ['_token' => (string) $form->filter('[name="_token"]')->attr('value')];

        foreach ($crawler->filter(sprintf('[form="%s"]', $id)) as $node) {
            \assert($node instanceof \DOMElement);

            $name = $node->getAttribute('name');

            if ($name === '' || $name === 'width') {
                continue;
            }

            // A checkbox sends its value only when it is ticked, which is the
            // whole difference between "on list" staying on and being turned off.
            if ($node->getAttribute('type') === 'checkbox') {
                if ($node->hasAttribute('checked')) {
                    $values[$name] = $node->getAttribute('value');
                }

                continue;
            }

            $values[$name] = $node->nodeName === 'select'
                ? self::selected($node)
                : $node->getAttribute('value');
        }

        $values['width'] = $width;

        $this->client->request('POST', $this->url((string) $form->attr('action')), $values);
        $this->client->followRedirect();
    }

    /** Whichever option a select is showing. */
    private static function selected(?\DOMNode $select): string
    {
        if (!$select instanceof \DOMElement) {
            return '';
        }

        foreach ($select->getElementsByTagName('option') as $option) {
            if ($option->hasAttribute('selected')) {
                return $option->getAttribute('value');
            }
        }

        return '';
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

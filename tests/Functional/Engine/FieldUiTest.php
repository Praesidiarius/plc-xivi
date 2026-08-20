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
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Metadata\MetadataRepository;
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

    /** A number with its country code in it, so no tenant profile is needed to read it. */
    private const string INTERNATIONAL = '+41 79 123 45 67';

    /** And what a real address book has in it beside the numbers ([XIV-146]). */
    private const string NOT_A_NUMBER = 'ask reception';

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

    /**
     * The doors, one set per shape, and the fields behind the second of them
     * ([XIV-163]).
     *
     * The old editor put every field of every shape on one page, so this test
     * used to be one request. It is three now, and the extra two are the point:
     * a collection is edited through the same three doors as the module, which
     * is what "the editor edits any shape" means once the editor is more than a
     * single table.
     */
    public function testTheEditorOffersTheSameThreeDoorsForEveryShape(): void
    {
        $this->signIn(self::ADMIN);

        $crawler = $this->client->request('GET', $this->url('/m/contact/fields'));

        self::assertResponseIsSuccessful();

        // A shape's label is an input, because it can be renamed (XIV-8), and
        // text() does not see what an input holds.
        $labels = $crawler->filter('input[name="label"]')->extract(['value']);

        self::assertContains('Addresses', $labels, 'the collection is here too');
        self::assertCount(6, $crawler->filter('main a[href*="/fields/"]'), 'three doors each, for two shapes');

        $module = $this->shapeId();
        $collection = $this->collectionId();

        $own = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/edit', $module)))->filter('main')->text();

        foreach (['first_name', 'birthday'] as $expected) {
            self::assertStringContainsString($expected, $own);
        }

        $rows = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/edit', $collection)))->filter('main')->text();

        self::assertStringContainsString('postal_code', $rows, "and the collection's own fields are behind its own door");
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

        // **A direct child of the row**, which is the whole assertion. A column
        // whose parent is not a row is an ordinary div: every width is correct
        // and every field still stacks. Asserting the classes exist, or that
        // there is a `.row` somewhere above, passes against exactly that bug —
        // which is how it shipped.
        self::assertSelectorExists(
            '.row > .col-md-6 [name="module_record[fields][vat_number]"]',
            'a text field is half a row, in a row',
        );
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
        self::assertSelectorExists('.row > .col-12 [name="module_record[fields][remarks]"]', 'still a direct child of the row');
    }

    /** And somebody can disagree with the type, per field. */
    public function testAWidthCanBeSetOnOneField(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField(['key' => 'vat_number', 'label' => 'VAT number', 'type' => 'text']);

        $this->arrange('vat_number', width: '3');

        $crawler = $this->client->request('GET', $this->url('/m/contact/new?variant=person'));

        self::assertSelectorExists(
            '.row > .col-md-3 [name="module_record[fields][vat_number]"]',
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
        ]);

        $this->arrange('vat_number', width: '4', listed: true);

        // The field's own form, which since [XIV-163] is the page that does not
        // draw a width at all. That is the whole assertion: two forms edit one
        // field between them, and neither may quietly undo the other, which is
        // XIV-26's rule now that there is more than one form to break it.
        $crawler = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d', $this->fieldId('vat_number'))));

        self::assertSame('VAT number', $crawler->filter('[name="label"]')->attr('value'), 'the label is still there');
        self::assertCount(1, $crawler->filter('input[type="checkbox"][checked]'), 'and the rule it was given');

        $arranged = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/arrange', $this->shapeId())));
        $row = $arranged->filter('tbody tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->text(), 'vat_number'),
        )->first();

        self::assertSame('4', self::selected($row->filter('select')->getNode(0)), 'and the width stuck');
        self::assertNotNull($row->filter('input[type="checkbox"][checked]')->getNode(0), 'and so did the list column');

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

    /**
     * And is put on it from the arrange page, which is the only place that asks
     * ([XIV-163]).
     *
     * `listed` used to be a checkbox on the add form. It is one of the four
     * things the third door owns now, because whether a column is worth having
     * is decided against the columns already there. A field therefore arrives
     * off the list and joins it deliberately, which is what XIV-26 wanted from
     * the checkbox that was unticked by default.
     */
    public function testAFieldMarkedForTheListBecomesAColumn(): void
    {
        $this->signIn(self::ADMIN);
        $this->addContact('Ada', 'Lovelace');
        $this->addField(['key' => 'vat_number', 'label' => 'VAT number', 'type' => 'text']);

        $this->arrange('vat_number', listed: true);

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

        $crawler = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d', $this->fieldId('vat_number'))));
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

        $crawler = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/edit', $this->shapeId())));

        self::assertStringContainsString('module field', $crawler->filter('main')->text());
        // Every field is the module's own at this point, so nothing is removable.
        self::assertCount(0, $crawler->filter('a:contains("Remove")'));

        // And not on the field's own page either, which is where the button
        // lives since [XIV-163]. The list above says why it is absent; this says
        // it is absent where somebody would go looking for it.
        $own = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d', $this->fieldId('first_name'))));

        self::assertCount(0, $own->filter('a:contains("Remove")'));
    }

    /**
     * The type change, through the three pages a customer actually walks
     * ([XIV-146], §7.2).
     *
     * Deliberately not a service call with a browser wrapped round it. What
     * XIV-146 promises is that nobody meets a surprise: the report is on the
     * screen *before* the button, the refusal names the value that caused it,
     * and emptying is a second box rather than something that rides along with
     * agreeing. None of that is provable from the engine, because all three are
     * about which page says what and in what order.
     */
    public function testTheDryRunIsOnScreenBeforeAnythingIsWritten(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField(['key' => 'mobile', 'label' => 'Mobile']);
        $this->addContactWithMobile('Ada', self::INTERNATIONAL);
        $this->addContactWithMobile('Grace', self::NOT_A_NUMBER);

        // The link is on the field's own page, beside the other changes that
        // are a page rather than a control.
        $field = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d', $this->fieldId('mobile'))));

        self::assertCount(1, $field->filter('a:contains("Change the type")'));

        $types = $this->client->click($field->selectLink('Change the type')->link());

        self::assertResponseIsSuccessful();
        // Counted, on the first page, before any type has been picked.
        self::assertStringContainsString('2 records hold a value here', $types->filter('main')->text());

        $report = $this->client->submit($types->filter('form[action*="/type/phone"] button')->form());

        self::assertResponseIsSuccessful();
        $text = $report->filter('main')->text();

        // All four things the page exists to say, and the value that caused the
        // refusal among them, because a count on its own is not something
        // anybody can act on.
        self::assertStringContainsString('1 records convert', $text);
        self::assertStringContainsString('1 records hold something', $text);
        self::assertStringContainsString(self::NOT_A_NUMBER, $text);
        self::assertStringContainsString('cannot be undone', $text);

        // And nothing has happened yet.
        self::assertSame('text', $this->fieldType('mobile'));
    }

    /**
     * Emptying the rows that cannot be read is a second box, and the change is
     * refused without it (§7.2).
     */
    public function testEmptyingIsASecondBoxAndTheChangeIsRefusedWithoutIt(): void
    {
        $this->signIn(self::ADMIN);
        $this->addField(['key' => 'mobile', 'label' => 'Mobile']);
        $this->addContactWithMobile('Ada', self::INTERNATIONAL);
        $this->addContactWithMobile('Grace', self::NOT_A_NUMBER);

        $apply = $this->url(sprintf('/m/contact/fields/%d/type/phone/apply', $this->fieldId('mobile')));

        // Agreeing to the conversion and saying nothing about the failing rows
        // is refused, which is what makes the second box a decision rather than
        // a formality.
        $this->client->request('POST', $apply, ['_token' => $this->token(), 'confirm' => '1']);
        $refused = $this->client->followRedirect();

        self::assertStringContainsString(self::NOT_A_NUMBER, $refused->filter('main')->text());
        self::assertSame('text', $this->fieldType('mobile'));

        // And with it, the same call goes through.
        $this->client->request('POST', $apply, ['_token' => $this->token(), 'confirm' => '1', 'empty' => '1']);
        $this->client->followRedirect();

        self::assertSame('phone', $this->fieldType('mobile'));
    }

    /** A contact with something typed into the text field being converted. */
    private function addContactWithMobile(string $first, string $mobile): void
    {
        $this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => $first, 'last_name' => 'Tester', 'mobile' => $mobile],
            variant: 'person',
        );
    }

    /** What the tenant's own definitions say this field is now. */
    private function fieldType(string $key): string
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($key): string {
            $field = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField($key);
            \assert($field instanceof FieldDefinition);

            return $field->getType();
        });
    }

    /**
     * The editor's CSRF token, read off a page that draws one.
     *
     * The two posts above are made by hand rather than through a form, because
     * what is being tested is what the *controller* requires: a form posted
     * around the page is exactly the caller the confirmation has to hold for,
     * and submitting the real form could only ever prove that the real form
     * works.
     */
    private function token(): string
    {
        $page = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d', $this->fieldId('mobile'))));

        return (string) $page->filter('input[name="_token"]')->first()->attr('value');
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
     * Change one field's place on the form, through the page that owns it.
     *
     * The arrange page is one form for the whole shape, so its controls are
     * named per field id and every field of the shape is submitted at once. That
     * is why this helper submits the real form rather than posting three values:
     * a checkbox sends nothing when it is unticked, and a post naming only this
     * field's would read as every other column being turned off.
     */
    private function arrange(string $key, ?string $width = null, ?bool $listed = null): void
    {
        $crawler = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/arrange', $this->shapeId())));
        $form = $crawler->selectButton('Save')->form();
        $id = $this->fieldId($key);

        if ($width !== null) {
            $form[sprintf('width[%d]', $id)] = $width;
        }

        if ($listed !== null) {
            $box = $form[sprintf('listed[%d]', $id)];
            \assert($box instanceof ChoiceFormField);
            $listed ? $box->tick() : $box->untick();
        }

        $this->client->submit($form);
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

    /**
     * Add a field through the form for its type ([XIV-163]).
     *
     * The type is the URL rather than a control, because it is what decided
     * which controls the form has. Everything else is filled in on the form the
     * page actually renders, so a test cannot name a setting the page does not
     * draw.
     *
     * @param array<string, string> $values
     */
    private function addField(array $values): void
    {
        $type = $values['type'] ?? 'text';
        unset($values['type']);

        $crawler = $this->client->request(
            'GET',
            $this->url(sprintf('/m/contact/fields/%d/add/%s', $this->shapeId(), $type)),
        );
        $form = $crawler->selectButton('Add')->form();

        foreach ($values as $name => $value) {
            $form[$name] = $value;
        }

        $this->client->submit($form);
        $this->client->followRedirect();
    }

    /** The contact module's own shape, which the doors hang off. */
    private function shapeId(): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => (int) self::service(MetadataRepository::class)->get(ContactModule::KEY)->getId(),
        );
    }

    /** And its first collection, which is edited through the same three doors. */
    private function collectionId(): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function (): int {
            $collection = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getCollections()->first();
            self::assertInstanceOf(CollectionDefinition::class, $collection, 'the contact module has a collection');

            return (int) $collection->getId();
        });
    }

    private function fieldId(string $key): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($key): int {
            $field = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField($key);
            \assert($field instanceof FieldDefinition);

            return (int) $field->getId();
        });
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

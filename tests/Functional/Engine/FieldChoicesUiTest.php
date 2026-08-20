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
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Order\OrderModule;

/**
 * The two types the editor offered and could not configure (XIV-144).
 *
 * Every test here goes through the editor's **own request** rather than through
 * {@see \Xivi\Core\Metadata\MetadataEditor}, which is XIV-104's lesson written
 * down as a habit: a protection that looks right in the service can be
 * unreachable from the path a customer actually walks, and the defect this
 * ticket names lived exactly there — the engine was fine, the form never asked.
 *
 * What is being proved, in the order the acceptance criteria ask it:
 *
 *  * a `choice` field can be added *with its options*, and cannot be added
 *    without them;
 *  * a `reference` can be added with its target, and cannot be added without
 *    one;
 *  * an option no record holds can be removed, one that records hold cannot, and
 *    the refusal names the value and the count;
 *  * a module's own field's options may be added to and renamed, never removed;
 *  * a reference's target may be moved while the field is empty and not once
 *    records point through it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FieldChoicesUiTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_field_choices';
    private const string HOST = 'fieldchoices.localhost';
    private const string ADMIN = 'admin@fieldchoices.test';
    /** Whose session a record is saved under (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string PASSWORD = 'choices-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $tenant = $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            foreach ([ContactModule::KEY, OrderModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /**
     * The claim, end to end: a choice field added here has options on the record
     * form.
     */
    public function testAChoiceFieldCanBeAddedWithItsOptions(): void
    {
        $this->addField(['key' => 'channel', 'label' => 'Channel', 'type' => 'choice'], "Phone\nEmail\nWalk-in");

        self::assertSelectorTextContains('.alert', 'Added "Channel"');
        self::assertSame(
            ['phone' => 'Phone', 'email' => 'Email', 'walk_in' => 'Walk-in'],
            ChoiceFieldType::choicesOf($this->fieldOf(ContactModule::KEY, 'channel')),
            'the labels are the customer\'s words and the keys are derived from them',
        );

        // And it is a working control on the record form, which is the whole
        // point: the defect was a select with nothing in it.
        $crawler = $this->client->request('GET', $this->url('/m/contact/new?variant=person'));
        $options = $crawler->filter('[name="module_record[fields][channel]"] option')->extract(['value']);

        self::assertContains('phone', $options);
        self::assertContains('walk_in', $options);
    }

    /**
     * And cannot be added without them, which is the other half of "not the
     * current middle".
     *
     * The sentence names **both** ways of answering since [XIV-127] — its own
     * options, or a shared list it is pointed at — because a message naming only
     * the first would send somebody off to type options into a field they had
     * meant to point at "our regions".
     */
    public function testAChoiceFieldCannotBeAddedWithoutOptions(): void
    {
        $this->addField(['key' => 'channel', 'label' => 'Channel', 'type' => 'choice'], '');

        self::assertSelectorTextContains('.alert', 'does nothing until "choices" or "list" is set');
        self::assertNull($this->findField(ContactModule::KEY, 'channel'), 'nothing was created');
    }

    /** The same statement for the other type this ticket is about. */
    public function testAReferenceFieldCanBeAddedWithItsTarget(): void
    {
        $this->addField([
            'key' => 'introduced_by',
            'label' => 'Introduced by',
            'type' => 'reference',
            'module' => ContactModule::KEY,
        ]);

        self::assertSelectorTextContains('.alert', 'Added "Introduced by"');
        self::assertSame(
            ContactModule::KEY,
            ReferenceFieldType::targetModule($this->fieldOf(ContactModule::KEY, 'introduced_by')),
        );
    }

    public function testAReferenceFieldCannotBeAddedWithoutATarget(): void
    {
        $this->addField(['key' => 'introduced_by', 'label' => 'Introduced by', 'type' => 'reference']);

        self::assertSelectorTextContains('.alert', 'does nothing until "module" is set');
        self::assertNull($this->findField(ContactModule::KEY, 'introduced_by'));
    }

    /**
     * The type list offers what the editor can configure, and today that is
     * everything.
     *
     * The page's half of {@see EditorConfiguresEveryTypeTest}: that one proves
     * the rule, this one proves the page is built from it rather than from
     * `all()`, which is the line the defect was hiding behind. It was a select
     * in the middle of the combined form until [XIV-163] and is a page of its
     * own now, which changes where to look and nothing about what is being
     * claimed.
     */
    public function testTheTypeListOffersTheTypesTheEditorCanConfigure(): void
    {
        $crawler = $this->client->request(
            'GET',
            $this->url(sprintf('/m/contact/fields/%d/add', $this->shapeId(ContactModule::KEY))),
        );
        $offered = $crawler->filter('main a[href*="/add/"]')->extract(['href']);

        foreach (['choice', 'reference', 'text'] as $type) {
            self::assertNotEmpty(
                array_filter($offered, static fn (string $href): bool => str_ends_with($href, '/add/'.$type)),
                sprintf('"%s" can be added', $type),
            );
        }
    }

    /** An option nothing holds goes, because nothing is standing in the way. */
    public function testAnOptionNoRecordHoldsCanBeRemoved(): void
    {
        $this->addField(['key' => 'channel', 'label' => 'Channel', 'type' => 'choice'], "Phone\nEmail");

        $this->saveOptions('channel', remove: ['email']);

        self::assertSame(
            ['phone' => 'Phone'],
            ChoiceFieldType::choicesOf($this->fieldOf(ContactModule::KEY, 'channel')),
        );
    }

    /**
     * **The decision, proved against a record that actually holds the value.**.
     *
     * A test on an empty module proves nothing here: what makes this refusal
     * necessary is the record left holding a value that is no longer on the
     * list, which would fail its own field's validation the next time anybody
     * opened it. So one is created, through the record form, and then the option
     * it holds is removed.
     */
    public function testAnOptionARecordHoldsCannotBeRemoved(): void
    {
        $this->addField(['key' => 'channel', 'label' => 'Channel', 'type' => 'choice'], "Phone\nEmail");
        $this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'channel' => 'phone'],
            variant: 'person',
        );

        $this->saveOptions('channel', remove: ['phone']);

        self::assertSelectorTextContains('.alert', '"phone" (1)');
        self::assertSelectorTextContains('.alert', 'could not be saved again');
        self::assertSame(
            ['phone' => 'Phone', 'email' => 'Email'],
            ChoiceFieldType::choicesOf($this->fieldOf(ContactModule::KEY, 'channel')),
            'the list is exactly as it was',
        );
    }

    /**
     * And the count is on the page before anybody tries, which is what turns a
     * refusal into something somebody can plan around.
     */
    public function testTheOptionsPageSaysHowManyRecordsHoldEachOption(): void
    {
        $this->addField(['key' => 'channel', 'label' => 'Channel', 'type' => 'choice'], "Phone\nEmail");
        $this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'channel' => 'phone'],
            variant: 'person',
        );

        $row = $this->optionRow('channel', 'phone');

        self::assertStringContainsString('1 record', $row->text());
    }

    /**
     * Renaming an option changes what the page says and not what a record holds
     * — the value/label split, made visible.
     */
    public function testRenamingAnOptionLeavesTheStoredValueAlone(): void
    {
        $this->addField(['key' => 'channel', 'label' => 'Channel', 'type' => 'choice'], 'Phone');
        $this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'channel' => 'phone'],
            variant: 'person',
        );

        $this->saveOptions('channel', rename: ['phone' => 'By telephone']);

        self::assertSame(
            ['phone' => 'By telephone'],
            ChoiceFieldType::choicesOf($this->fieldOf(ContactModule::KEY, 'channel')),
        );
        self::assertStringContainsString(
            '1 record',
            $this->optionRow('channel', 'phone')->text(),
            'the record still holds it, so it is still counted',
        );
    }

    /**
     * A module's own field takes new options — the wholesaler's pallet and the
     * workshop's machine, which are the customers §5.20 and §5.22 wrote this
     * gap down for.
     */
    public function testAModulesOwnChoiceFieldCanBeAddedTo(): void
    {
        $this->saveOptions('status', add: 'On hold', module: OrderModule::KEY);

        $choices = ChoiceFieldType::choicesOf($this->fieldOf(OrderModule::KEY, 'status'));

        self::assertArrayHasKey('on_hold', $choices);
        self::assertArrayHasKey(OrderModule::CONFIRMED, $choices, 'and keeps every state the lifecycle names');
    }

    /**
     * And never gives one up, because the module's own code names some of them —
     * an order's lifecycle moves records between exactly these words.
     */
    public function testAModulesOwnChoiceFieldOffersNoRemoval(): void
    {
        $row = $this->optionRow('status', OrderModule::CONFIRMED, module: OrderModule::KEY);

        self::assertCount(0, $row->filter('input[type="checkbox"]'), 'no remove box on a module field');

        // And not merely hidden: a form posted around the page is refused too.
        $this->postOptions('status', ['remove' => [OrderModule::CONFIRMED => '1']], module: OrderModule::KEY);

        self::assertSelectorTextContains('.alert', 'removing one is not offered');
        self::assertArrayHasKey(
            OrderModule::CONFIRMED,
            ChoiceFieldType::choicesOf($this->fieldOf(OrderModule::KEY, 'status')),
        );
    }

    /** A field of the customer's own may be pointed somewhere else while it is empty. */
    public function testAnEmptyReferenceCanBeRepointed(): void
    {
        $this->addField([
            'key' => 'introduced_by',
            'label' => 'Introduced by',
            'type' => 'reference',
            'module' => ContactModule::KEY,
        ]);

        $this->setTarget('introduced_by', OrderModule::KEY);

        self::assertSame(
            OrderModule::KEY,
            ReferenceFieldType::targetModule($this->fieldOf(ContactModule::KEY, 'introduced_by')),
        );
    }

    /**
     * And not once records point through it: every stored id is still a valid
     * integer afterwards, addressing the wrong record, and nothing would ever
     * report it.
     */
    public function testAPopulatedReferenceCannotBeRepointed(): void
    {
        $this->addField([
            'key' => 'introduced_by',
            'label' => 'Introduced by',
            'type' => 'reference',
            'module' => ContactModule::KEY,
        ]);

        $introducer = $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Grace', 'last_name' => 'Hopper'],
            variant: 'person',
        ));
        $this->saveRecord(
            ContactModule::KEY,
            [
                'kind' => 'person',
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'introduced_by' => (string) $introducer,
            ],
            variant: 'person',
        );

        $this->setTarget('introduced_by', OrderModule::KEY);

        self::assertSelectorTextContains('.alert', 'point through "introduced_by"');
        self::assertSame(
            ContactModule::KEY,
            ReferenceFieldType::targetModule($this->fieldOf(ContactModule::KEY, 'introduced_by')),
            'still pointing where the records point',
        );
    }

    /**
     * And emptying the target is refused as what it is: a field pointed nowhere.
     *
     * **With records pointing through it**, which is the case that tells the two
     * refusals apart. Emptying is not a move — there is no module to name in a
     * sentence about moving to one — so it gets the sentence somebody gets for
     * adding a reference without a target, which is the same mistake one save
     * later, rather than one about ids that mean nothing in "".
     */
    public function testAReferencesTargetCannotBeEmptied(): void
    {
        $this->addField([
            'key' => 'introduced_by',
            'label' => 'Introduced by',
            'type' => 'reference',
            'module' => ContactModule::KEY,
        ]);

        $introducer = $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Grace', 'last_name' => 'Hopper'],
            variant: 'person',
        ));
        $this->saveRecord(
            ContactModule::KEY,
            [
                'kind' => 'person',
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'introduced_by' => (string) $introducer,
            ],
            variant: 'person',
        );

        $this->setTarget('introduced_by', '');

        self::assertSelectorTextContains('.alert', 'does nothing until "module" is set');
        self::assertSame(
            ContactModule::KEY,
            ReferenceFieldType::targetModule($this->fieldOf(ContactModule::KEY, 'introduced_by')),
        );
    }

    /** A module's own reference is the module's, whether or not it has records. */
    public function testAModulesOwnReferenceCannotBeRepointed(): void
    {
        $id = $this->fieldOf(OrderModule::KEY, 'contact')->getId();

        $this->client->request('POST', $this->url(sprintf('/m/%s/fields/%d', OrderModule::KEY, $id)), [
            '_token' => $this->token(OrderModule::KEY),
            'label' => 'Auftraggeberin',
            'position' => '1',
            ReferenceFieldType::MODULE => OrderModule::KEY,
        ]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert', 'came with the module and points at');
        self::assertSame(
            ContactModule::KEY,
            ReferenceFieldType::targetModule($this->fieldOf(OrderModule::KEY, 'contact')),
        );
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Add a field through the form for its type, exactly as the page sends it
     * ([XIV-163]).
     *
     * The type is in the URL rather than in a select, because it is what decides
     * which controls the form has. What this changes for the refusals below is
     * nothing at all: a `choice` field's form draws the options box and the
     * shared-list select, so submitting it with both empty is still a request
     * the engine has to answer, and it still answers by refusing.
     *
     * @param array<string, string> $values
     */
    private function addField(array $values, ?string $choices = null): void
    {
        $type = $values['type'];
        unset($values['type']);

        $crawler = $this->client->request(
            'GET',
            $this->url(sprintf('/m/contact/fields/%d/add/%s', $this->shapeId(ContactModule::KEY), $type)),
        );
        $form = $crawler->selectButton('Add')->form();

        foreach ($values as $name => $value) {
            $form[$name] = $value;
        }

        if ($choices !== null) {
            $form[ChoiceFieldType::CHOICES] = $choices;
        }

        $this->client->submit($form);
        $this->client->followRedirect();
    }

    /** A module's own shape, which every add form hangs off. */
    private function shapeId(string $module): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => (int) self::service(MetadataRepository::class)->get($module)->getId(),
        );
    }

    /**
     * Save the options page: rename some, remove some, add some.
     *
     * @param array<string, string> $rename value => its new label
     * @param list<string>          $remove values whose box is ticked
     */
    private function saveOptions(
        string $field,
        array $rename = [],
        array $remove = [],
        string $add = '',
        string $module = ContactModule::KEY,
    ): void {
        $values = [];

        foreach ($rename as $value => $label) {
            $values['label'][$value] = $label;
        }

        foreach ($remove as $value) {
            $values['remove'][$value] = '1';
        }

        if ($add !== '') {
            $values[ChoiceFieldType::CHOICES] = $add;
        }

        $this->postOptions($field, $values, $module);
    }

    /**
     * The same page, posted with whatever is handed over — including things its
     * own form would not send.
     *
     * @param array<string, mixed> $values
     */
    private function postOptions(string $field, array $values, string $module = ContactModule::KEY): void
    {
        $id = $this->fieldOf($module, $field)->getId();

        $this->client->request(
            'POST',
            $this->url(sprintf('/m/%s/fields/%d/options', $module, $id)),
            ['_token' => $this->token($module), ...$values],
        );
        $this->client->followRedirect();
    }

    /** One option's row on the options page. */
    private function optionRow(string $field, string $value, string $module = ContactModule::KEY): Crawler
    {
        $id = $this->fieldOf($module, $field)->getId();

        $crawler = $this->client->request('GET', $this->url(sprintf('/m/%s/fields/%d/options', $module, $id)));

        return $crawler->filter('tbody tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('code')->text(), $value),
        )->first();
    }

    /**
     * Point a reference somewhere else through the row form in the field table.
     *
     * Sent as the browser sends it — the row's controls belong to the form
     * through the HTML5 `form` attribute, which DomCrawler does not associate —
     * so the label and the position go with it rather than arriving empty.
     */
    private function setTarget(string $field, string $target, string $module = ContactModule::KEY): void
    {
        $definition = $this->fieldOf($module, $field);

        $this->client->request('POST', $this->url(sprintf('/m/%s/fields/%d', $module, $definition->getId())), [
            '_token' => $this->token($module),
            'label' => $definition->getLabel(),
            'position' => (string) $definition->getPosition(),
            ReferenceFieldType::MODULE => $target,
        ]);
        $this->client->followRedirect();
    }

    private function token(string $module): string
    {
        return (string) $this->client
            ->request('GET', $this->url('/m/' . $module . '/fields'))
            ->filter('input[name="_token"]')
            ->first()
            ->attr('value');
    }

    private function fieldOf(string $module, string $key): FieldDefinition
    {
        $field = $this->findField($module, $key);

        self::assertInstanceOf(FieldDefinition::class, $field, sprintf('"%s" has no field "%s"', $module, $key));

        return $field;
    }

    private function findField(string $module, string $key): ?FieldDefinition
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ?FieldDefinition => self::service(MetadataRepository::class)->get($module)->getField($key),
        );
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'email' => self::ADMIN,
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

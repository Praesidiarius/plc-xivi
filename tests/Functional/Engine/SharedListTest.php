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
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Entity\ValueList;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Validation\RecordValidator;
use Xivi\Core\ValueList\ValueLists;
use Xivi\Order\OrderModule;

/**
 * A list a customer keeps once and several fields take their values from
 * (XIV-127).
 *
 * Everything consequential here goes through **the pages a customer walks**
 * rather than through {@see \Xivi\Core\ValueList\ValueListEditor}, which is
 * XIV-104's lesson and XIV-91's rule together: a protection that looks right in
 * a service can be unreachable from the path somebody actually takes, and the
 * one operation here that cannot be undone is required to be confirmed **in the
 * controller** — which a test calling the service could not tell you anything
 * about.
 *
 * Records are written straight through {@see RecordRepository} where a test
 * needs a column populated, and that is deliberate rather than lazy: what is
 * being proved is what a merge and a refusal do to values that are *there*, and
 * driving the record form to put them there would make the setup longer than the
 * claim. One test saves through the form anyway
 * ({@see self::testARecordFormOnlyEverStoresAnEntryOfTheList()}), and one asks
 * the validator directly, because "the field really validates against the list"
 * is a claim about the write path and cannot be made any other way.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SharedListTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_shared_list';
    private const string HOST = 'sharedlist.localhost';
    private const string ADMIN = 'admin@sharedlist.test';
    /** Whose session a record is saved under (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string PASSWORD = 'shared-list-password';

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

    // -- the acceptance criteria, in order -----------------------------------

    /**
     * A customer makes a list, fills it in, and uses it from more than one
     * field.
     *
     * The "more than one" is the whole ticket: *Region* on a contact and *Region*
     * on an order were two unrelated strings that drifted apart the moment
     * somebody edited one.
     */
    public function testOneListIsUsedFromFieldsInTwoModules(): void
    {
        $list = $this->makeRegions();

        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);
        $this->addChoiceField(OrderModule::KEY, 'region', 'Region', $list);

        self::assertSame($list, ChoiceFieldType::listKeyOf($this->fieldOf(ContactModule::KEY, 'region')));
        self::assertSame($list, ChoiceFieldType::listKeyOf($this->fieldOf(OrderModule::KEY, 'region')));

        // And the list itself says so, which is what makes the delete refusal
        // predictable before anybody meets it.
        $this->client->request('GET', $this->url('/lists/' . $list));

        self::assertSelectorTextContains('body', 'Contacts → Region');
        self::assertSelectorTextContains('body', 'Orders → Region');
    }

    /**
     * The record form stores an entry of the list, and nothing else reaches the
     * record.
     *
     * The one test here that goes through the write path a person uses, because
     * "the field really takes its values from the list" is a claim about that
     * path: a `choice` field whose options are empty accepts anything at all
     * ({@see ChoiceFieldType::constraints()}), so a field pointed at a list and
     * still accepting rubbish would look exactly like a working one.
     *
     * **The off-list value is dropped rather than refused, and that is the
     * form's behaviour rather than this ticket's.** Symfony's `ChoiceType`
     * reverse-transforms a value that is not among its choices to null, so a
     * hand-edited submission loses it before the validator is reached — which is
     * exactly what a `choice` field with its own options has always done, and is
     * the point: the field is constrained by the list in the same way and by the
     * same machinery. The engine's own refusal is asserted where it can be seen,
     * below.
     */
    public function testARecordFormOnlyEverStoresAnEntryOfTheList(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);

        $accepted = $this->saveRecord(ContactModule::KEY, [
            'kind' => 'person',
            'first_name' => 'Nina',
            'last_name' => 'Baumgartner',
            'region' => 'zuerich',
        ], variant: 'person');

        self::assertTrue($accepted->isRedirect(), 'an entry of the list is accepted');
        self::assertSame('zuerich', $this->readRecord(ContactModule::KEY, 1)->get('region'));

        $this->saveRecord(ContactModule::KEY, [
            'kind' => 'person',
            'first_name' => 'Urs',
            'last_name' => 'Meier',
            'region' => 'valais',
        ], variant: 'person');

        self::assertNull(
            $this->readRecord(ContactModule::KEY, 2)->get('region'),
            'a value the list has never heard of does not reach the record',
        );
    }

    /**
     * And the engine refuses it outright, which is the claim about
     * {@see ChoiceFieldType::constraints()} rather than about a widget.
     *
     * The validator is where a value arriving from somewhere other than the form
     * — an import, a console command, the API that does not exist yet — meets
     * the list, and it is the assertion that goes red if the constraint stops
     * reading the shared list and falls back to the field's own (empty) options:
     * an empty `Assert\Choice` is skipped, so the field would accept everything
     * and say so nowhere.
     */
    public function testTheValidatorRefusesAValueThatIsNotOnTheList(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);

        $person = ['kind' => 'person', 'first_name' => 'Nina', 'last_name' => 'B'];

        self::assertCount(0, $this->validate(ContactModule::KEY, [...$person, 'region' => 'zuerich']));
        self::assertCount(1, $this->validate(ContactModule::KEY, [...$person, 'region' => 'valais']));
    }

    /**
     * A colour and a picture, drawn on the record page in tokens the dark theme
     * redefines.
     *
     * **The assertion is about the tokens rather than about the pixels**, and
     * that is the only honest way to test "survives dark mode" without a
     * browser. Bootstrap 5.3 redefines `--bs-{tone}-bg-subtle`,
     * `--bs-{tone}-text-emphasis` and `--bs-{tone}-border-subtle` under
     * `[data-bs-theme=dark]` and does **not** redefine `--bs-{tone}` itself — so
     * a chip built from the subtle trio follows the theme and one built from
     * `text-bg-{tone}` or from a hex does not. Naming a hex is the failure this
     * catches.
     */
    public function testAValueCarriesAColourAndAnIconInThemeAwareTokens(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);
        $this->colour($list, 'zuerich', 'success', 'star-fill');

        $id = $this->writeRecord(ContactModule::KEY, [
            'kind' => 'person',
            'first_name' => 'Nina',
            'last_name' => 'Baumgartner',
            'region' => 'zuerich',
        ]);

        $crawler = $this->client->request('GET', $this->url(sprintf('/m/%s/%d', ContactModule::KEY, $id)));
        $badge = $crawler->filter('.badge')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Zürich'),
        );

        self::assertGreaterThan(0, $badge->count(), 'the value is drawn as a chip');

        $class = (string) $badge->first()->attr('class');

        self::assertStringContainsString('bg-success-subtle', $class);
        self::assertStringContainsString('text-success-emphasis', $class);
        self::assertStringContainsString('border-success-subtle', $class);
        self::assertStringNotContainsString('text-bg-', $class, 'text-bg-* is fixed in both themes');
        self::assertGreaterThan(0, $badge->filter('i.bi-star-fill')->count(), 'and carries its picture');
    }

    /**
     * A value has a parent, the picker shows it, and a filter does not follow
     * it.
     *
     * **What a hierarchy does to a filter is decided here and the answer is
     * "nothing"** (§5.4). Filtering on a parent matches records holding the
     * parent, not records holding its children — because the count beside an
     * entry, the refusal that reads it and the merge that acts on it all count
     * the value exactly, and a filter that counted differently would be a second
     * notion of "records holding this" free to disagree with the first.
     */
    public function testAParentIndentsThePickerAndDoesNotWidenAFilter(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);
        $this->reparent($list, 'zuerich', 'schweiz');

        // The picker reads as the tree it is: the child arrives indented, which
        // is the whole of what a parent does to a form.
        $crawler = $this->client->request('GET', $this->url('/m/contact/new?variant=person'));
        $labels = $crawler->filter('[name="module_record[fields][region]"] option')->extract(['_text']);

        self::assertContains('Schweiz', $labels);
        self::assertContains('– Zürich', $labels);

        $this->writeRecord(ContactModule::KEY, [
            'kind' => 'person', 'first_name' => 'Nina', 'last_name' => 'B', 'region' => 'zuerich',
        ]);
        $this->writeRecord(ContactModule::KEY, [
            'kind' => 'person', 'first_name' => 'Urs', 'last_name' => 'M', 'region' => 'schweiz',
        ]);

        $crawler = $this->client->request('GET', $this->url('/m/contact?filter[0][path]=region&filter[0][op]=eq&filter[0][value]=schweiz'));

        self::assertSelectorTextContains('body', 'Urs');
        self::assertSelectorTextNotContains('body', 'Nina', 'a filter matches the value it names, not what sits under it');
    }

    /**
     * An existing `choice` field with its own options keeps working untouched.
     *
     * **Proved against a definition written before this ticket existed** — the
     * contact module's own `kind` field, which is its variant field and whose
     * options the installer wrote — rather than against one this test created in
     * the new shape. What the assertion actually checks is that its stored
     * options are byte-identical after a save through the row form that now
     * sends the new control on every request: the field never learns that shared
     * lists exist, and no migration went anywhere near it.
     */
    public function testAModulesOwnChoiceFieldIsUnchangedByASaveThatNowSendsTheNewControl(): void
    {
        $before = $this->fieldOf(ContactModule::KEY, 'kind')->getOptions();

        self::assertNotSame([], $before[ChoiceFieldType::CHOICES] ?? [], 'the module wrote its options');
        self::assertArrayNotHasKey(ChoiceFieldType::LIST, $before, 'and said nothing about a list');

        $this->saveRow(ContactModule::KEY, 'kind', ['list' => '']);

        self::assertSame($before, $this->fieldOf(ContactModule::KEY, 'kind')->getOptions());
    }

    /**
     * And the same for a field the customer added the old way.
     *
     * Written through the add form **without** touching the list control, which
     * is the definition every tenant is full of: options of its own, no `list`
     * key, and a record form that offers them.
     */
    public function testAChoiceFieldWithItsOwnOptionsKeepsWorking(): void
    {
        $this->addField([
            'shape' => (string) $this->shapeOf(ContactModule::KEY)->getId(),
            'key' => 'channel',
            'label' => 'Channel',
            'type' => 'choice',
            ChoiceFieldType::CHOICES => "Phone\nEmail",
        ]);

        $field = $this->fieldOf(ContactModule::KEY, 'channel');

        self::assertSame(['phone' => 'Phone', 'email' => 'Email'], ChoiceFieldType::choicesOf($field));
        self::assertArrayNotHasKey(ChoiceFieldType::LIST, $field->getOptions());

        $crawler = $this->client->request('GET', $this->url('/m/contact/new?variant=person'));
        $options = $crawler->filter('[name="module_record[fields][channel]"] option')->extract(['value']);

        self::assertContains('phone', $options);
        self::assertContains('email', $options);
    }

    /**
     * Removing an entry records hold is refused, with the values, the counts and
     * where they are.
     *
     * §5.4's rule, in the "beside the field" spelling: *a list somebody's records
     * point into cannot lose an entry while they point into it, whether the list
     * lives in the field or beside it.* Proved with records that actually hold
     * the value, in two modules at once, because the reach is the difference
     * between this refusal and XIV-144's.
     */
    public function testAnEntryRecordsHoldCannotBeRemoved(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);
        $this->addChoiceField(OrderModule::KEY, 'region', 'Region', $list);

        $this->writeRecord(ContactModule::KEY, [
            'kind' => 'person', 'first_name' => 'Nina', 'last_name' => 'B', 'region' => 'zurich',
        ]);
        $this->writeRecord(ContactModule::KEY, [
            'kind' => 'person', 'first_name' => 'Urs', 'last_name' => 'M', 'region' => 'zurich',
        ]);
        $this->writeRecord(OrderModule::KEY, ['region' => 'zurich']);

        $this->saveList($list, remove: ['zurich']);

        self::assertSelectorTextContains('.alert', '3 existing records hold an entry you are removing');
        self::assertSelectorTextContains('.alert', '"zurich" (3)');
        self::assertSelectorTextContains('.alert', 'Contacts → Region');
        self::assertSelectorTextContains('.alert', 'Orders → Region');
        self::assertNotNull($this->list($list)->getEntry('zurich'), 'the entry is still there');
    }

    /** And one nothing holds goes, which is what stops the rule being "nothing may be removed". */
    public function testAnEntryNothingHoldsIsRemoved(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);

        $this->saveList($list, remove: ['bern']);

        self::assertNull($this->list($list)->getEntry('bern'));
    }

    /**
     * Renaming an entry moves no record, which is XIV-144's rule inherited
     * rather than re-decided.
     */
    public function testRenamingAnEntryMovesNoRecord(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);

        $id = $this->writeRecord(ContactModule::KEY, [
            'kind' => 'person', 'first_name' => 'Nina', 'last_name' => 'B', 'region' => 'zuerich',
        ]);

        $this->saveList($list, rename: ['zuerich' => 'Kanton Zürich']);

        self::assertSame('zuerich', $this->readRecord(ContactModule::KEY, $id)->get('region'));
        self::assertSame('Kanton Zürich', $this->list($list)->getEntry('zuerich')?->getLabel());
    }

    // -- the merge -----------------------------------------------------------

    /**
     * The merge plan counts what the merge then rewrites, **including a
     * collection's rows**.
     *
     * The count on the confirmation page is the promise; the number the merge
     * reports is what happened. Proving they agree is proving the plan is not a
     * decoration — and the collection is in it because a merge that rewrote the
     * module's rows and skipped the order lines would leave half of somebody's
     * data saying "Zurich" for ever, silently.
     */
    public function testTheMergePlanCountsWhatTheMergeRewrites(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);
        $this->addChoiceField(OrderModule::KEY, 'region', 'Region', $list, collection: 'lines');

        $contact = $this->writeRecord(ContactModule::KEY, [
            'kind' => 'person', 'first_name' => 'Nina', 'last_name' => 'B', 'region' => 'zurich',
        ]);
        $order = $this->writeRecord(OrderModule::KEY, []);
        $this->writeRow(OrderModule::KEY, 'lines', $order, ['region' => 'zurich']);
        $this->writeRow(OrderModule::KEY, 'lines', $order, ['region' => 'zurich']);
        $this->writeRow(OrderModule::KEY, 'lines', $order, ['region' => 'bern']);

        $crawler = $this->confirmMerge($list, 'zurich', 'zuerich');

        // Three records, and the page says where each of them is.
        self::assertSelectorTextContains('body', '3 records will be rewritten');
        self::assertSelectorTextContains('body', 'cannot be undone');
        self::assertStringContainsString('Orders / Lines → Region', $crawler->filter('table')->text());

        $this->doMerge($list, 'zurich', 'zuerich', confirm: true);

        self::assertSelectorTextContains('.alert', 'Merged, and 3 records were rewritten');
        self::assertSame('zuerich', $this->readRecord(ContactModule::KEY, $contact)->get('region'));
        self::assertSame(
            ['zuerich', 'zuerich', 'bern'],
            $this->rowValues(OrderModule::KEY, 'lines', $order, 'region'),
            'the collection rows moved too, and the one holding something else did not',
        );
        self::assertNull($this->list($list)->getEntry('zurich'), 'and the entry it was merged from is gone');
    }

    /**
     * It cannot be triggered without the confirmation, and the rule is in the
     * controller.
     *
     * XIV-91's rule verbatim, and the half a test calling the service could not
     * reach: a `required` attribute is a courtesy to somebody using the page and
     * nothing at all to a form posted around it. So this posts the merge
     * endpoint directly, with a valid token and everything else in place, and
     * omits exactly one thing.
     */
    public function testTheMergeIsRefusedWithoutTheConfirmationEvenWithAValidToken(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);

        $id = $this->writeRecord(ContactModule::KEY, [
            'kind' => 'person', 'first_name' => 'Nina', 'last_name' => 'B', 'region' => 'zurich',
        ]);

        $this->doMerge($list, 'zurich', 'zuerich', confirm: false);

        self::assertSame('zurich', $this->readRecord(ContactModule::KEY, $id)->get('region'), 'nothing was rewritten');
        self::assertNotNull($this->list($list)->getEntry('zurich'), 'and the entry is still on the list');
    }

    /** Merging an entry into itself is refused rather than deleting it in the name of a merge. */
    public function testAnEntryCannotBeMergedIntoItself(): void
    {
        $list = $this->makeRegions();

        $this->doMerge($list, 'zurich', 'zurich', confirm: true);

        self::assertSelectorTextContains('.alert', 'two different entries');
        self::assertNotNull($this->list($list)->getEntry('zurich'));
    }

    // -- the guards around pointing a field at one ---------------------------

    /**
     * A populated field cannot be pointed at a list that has not got what its
     * records hold.
     *
     * **The refusal that answers §5.21's objection to options in general.** That
     * section argues a checkbox reinterpreting stored data is the reason to
     * reach for a field type instead; the objection is right and it is answered
     * rather than dodged — the values are counted against the list first, so what
     * survives is a change that reinterprets nothing.
     */
    public function testPointingAPopulatedFieldAtAListThatLacksItsValuesIsRefused(): void
    {
        $list = $this->makeRegions();

        $this->addField([
            'shape' => (string) $this->shapeOf(ContactModule::KEY)->getId(),
            'key' => 'region',
            'label' => 'Region',
            'type' => 'choice',
            ChoiceFieldType::CHOICES => "Valais\nZürich",
        ]);

        $this->writeRecord(ContactModule::KEY, [
            'kind' => 'person', 'first_name' => 'Nina', 'last_name' => 'B', 'region' => 'valais',
        ]);

        $this->saveRow(ContactModule::KEY, 'region', ['list' => $list]);

        self::assertSelectorTextContains('.alert', 'is not on the list');
        self::assertSelectorTextContains('.alert', '"valais" (1)');
        self::assertSame('', ChoiceFieldType::listKeyOf($this->fieldOf(ContactModule::KEY, 'region')));
    }

    /** And can be, once every value it holds is on the list. */
    public function testPointingAPopulatedFieldAtAListThatHasItsValuesIsAllowed(): void
    {
        $list = $this->makeRegions();

        $this->addField([
            'shape' => (string) $this->shapeOf(ContactModule::KEY)->getId(),
            'key' => 'region',
            'label' => 'Region',
            'type' => 'choice',
            ChoiceFieldType::CHOICES => 'Zürich',
        ]);

        $this->writeRecord(ContactModule::KEY, [
            'kind' => 'person', 'first_name' => 'Nina', 'last_name' => 'B', 'region' => 'zuerich',
        ]);

        $this->saveRow(ContactModule::KEY, 'region', ['list' => $list]);

        self::assertSame($list, ChoiceFieldType::listKeyOf($this->fieldOf(ContactModule::KEY, 'region')));
    }

    /**
     * A module's own choice field cannot be pointed at a list at all.
     *
     * §5.4's oldest rule reaching one step further: an order's `status` options
     * are the states its lifecycle moves records between, and a list the
     * customer maintains is a list the customer can take entries out of.
     */
    public function testAModulesOwnChoiceFieldCannotBePointedAtAList(): void
    {
        $list = $this->makeRegions();

        $this->saveRow(ContactModule::KEY, 'kind', ['list' => $list]);

        self::assertSelectorTextContains('.alert', 'came with the module');
        self::assertSame('', ChoiceFieldType::listKeyOf($this->fieldOf(ContactModule::KEY, 'kind')));
    }

    /** And a field cannot be pointed at a list this customer has not got. */
    public function testAFieldCannotBePointedAtAListThatDoesNotExist(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);

        $this->saveRow(ContactModule::KEY, 'region', ['list' => 'kantone']);

        self::assertSelectorTextContains('.alert', 'No shared list named "kantone"');
        self::assertSame($list, ChoiceFieldType::listKeyOf($this->fieldOf(ContactModule::KEY, 'region')));
    }

    /** A list nothing points at can be deleted; one something points at cannot. */
    public function testAListInUseCannotBeDeleted(): void
    {
        $list = $this->makeRegions();
        $this->addChoiceField(ContactModule::KEY, 'region', 'Region', $list);

        $this->client->request('POST', $this->url('/lists/' . $list . '/delete'), ['_token' => $this->listToken()]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert', 'cannot be deleted');
        self::assertSelectorTextContains('.alert', 'Contacts → Region');
        self::assertTrue($this->listExists($list));
    }

    // -- helpers ------------------------------------------------------------

    /**
     * A list of regions with the three entries every test here uses.
     *
     * Made through the pages, so the keys are the ones the derivation produces —
     * `zuerich` for "Zürich", because the slugger is pinned to German and `ü`
     * becomes `ue` (§5.4).
     */
    private function makeRegions(): string
    {
        $this->client->request('POST', $this->url('/lists'), [
            '_token' => $this->listToken(),
            'label' => 'Regions',
        ]);
        $this->client->followRedirect();

        $this->saveList('regions', add: "Schweiz\nZürich\nZurich\nBern");

        return 'regions';
    }

    /**
     * Save the list page: rename some entries, tick some for removal, add some.
     *
     * Everything the page draws is sent, which is the page's own contract: an
     * entry missing from the form is one somebody removed rather than one they
     * did not mention.
     *
     * @param array<string, string> $rename value => its new label
     * @param list<string>          $remove values whose box is ticked
     * @param array<string, mixed>  $extra  anything else, for the tests about one column
     */
    private function saveList(
        string $list,
        array $rename = [],
        array $remove = [],
        string $add = '',
        array $extra = [],
    ): void {
        $values = ['_token' => $this->listToken(), 'list_label' => $this->list($list)->getLabel()];

        foreach ($this->list($list)->getEntries() as $entry) {
            $values['label'][$entry->getValue()] = $rename[$entry->getValue()] ?? $entry->getLabel();
            $values['position'][$entry->getValue()] = (string) $entry->getPosition();
            $values['tone'][$entry->getValue()] = $entry->getTone()->value ?? '';
            $values['icon'][$entry->getValue()] = $entry->getIcon()->value ?? '';
            $values['parent'][$entry->getValue()] = $entry->getParent()?->getValue() ?? '';
        }

        foreach ($remove as $value) {
            $values['remove'][$value] = '1';
        }

        if ($add !== '') {
            $values['add'] = $add;
        }

        $this->client->request('POST', $this->url('/lists/' . $list), array_replace_recursive($values, $extra));
        $this->client->followRedirect();
    }

    private function colour(string $list, string $value, string $tone, string $icon): void
    {
        $this->saveList($list, extra: ['tone' => [$value => $tone], 'icon' => [$value => $icon]]);
    }

    private function reparent(string $list, string $value, string $parent): void
    {
        $this->saveList($list, extra: ['parent' => [$value => $parent]]);
    }

    private function confirmMerge(string $list, string $from, string $into): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->request('POST', $this->url('/lists/' . $list . '/merge'), [
            '_token' => $this->listToken(),
            'from' => $from,
            'into' => $into,
        ]);
    }

    /**
     * The merge endpoint itself, posted with everything the confirmation page
     * would send **except**, when asked, the confirmation.
     */
    private function doMerge(string $list, string $from, string $into, bool $confirm): void
    {
        $values = ['_token' => $this->listToken(), 'from' => $from, 'into' => $into];

        if ($confirm) {
            $values['confirm'] = '1';
        }

        $this->client->request('POST', $this->url('/lists/' . $list . '/merge/do'), $values);
        $this->client->followRedirect();
    }

    /**
     * Add a choice field through the add form, pointed at a list.
     *
     * @param ?string $collection the collection's key, or null for the module's own shape
     */
    private function addChoiceField(
        string $module,
        string $key,
        string $label,
        string $list,
        ?string $collection = null,
    ): void {
        $this->addField([
            'shape' => (string) $this->shapeOf($module, $collection)->getId(),
            'key' => $key,
            'label' => $label,
            'type' => 'choice',
            ChoiceFieldType::LIST => $list,
        ], $module);
    }

    /** @param array<string, string> $values */
    private function addField(array $values, string $module = ContactModule::KEY): void
    {
        $this->client->request('POST', $this->url(sprintf('/m/%s/fields/add', $module)), [
            '_token' => $this->fieldToken($module),
            ...$values,
        ]);
        $this->client->followRedirect();
    }

    /**
     * Save one field's row in the field table, as the browser sends it.
     *
     * The row's controls belong to the form through the HTML5 `form` attribute,
     * which DomCrawler does not associate, so the label and the position go with
     * it by hand rather than arriving empty.
     *
     * @param array<string, string> $values
     */
    private function saveRow(string $module, string $key, array $values): void
    {
        $field = $this->fieldOf($module, $key);

        $this->client->request('POST', $this->url(sprintf('/m/%s/fields/%d', $module, $field->getId())), [
            '_token' => $this->fieldToken($module),
            'label' => $field->getLabel(),
            'position' => (string) $field->getPosition(),
            ...$values,
        ]);
        $this->client->followRedirect();
    }

    private function fieldToken(string $module): string
    {
        return (string) $this->client
            ->request('GET', $this->url('/m/' . $module . '/fields'))
            ->filter('input[name="_token"]')
            ->first()
            ->attr('value');
    }

    private function listToken(): string
    {
        return (string) $this->client
            ->request('GET', $this->url('/lists'))
            ->filter('input[name="_token"]')
            ->first()
            ->attr('value');
    }

    // -- reading the tenant's own state --------------------------------------

    private function list(string $key): ValueList
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ValueList => self::service(ValueLists::class)->get($key),
        );
    }

    private function listExists(string $key): bool
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): bool => self::service(ValueLists::class)->exists($key),
        );
    }

    private function shapeOf(string $module, ?string $collection = null): ShapeDefinition
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $collection): ShapeDefinition {
            $definition = self::service(MetadataRepository::class)->get($module);

            if ($collection === null) {
                return $definition;
            }

            $shape = $definition->getCollection($collection);
            self::assertInstanceOf(ShapeDefinition::class, $shape);

            return $shape;
        });
    }

    private function fieldOf(string $module, string $key): FieldDefinition
    {
        $field = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ?FieldDefinition => self::service(MetadataRepository::class)->get($module)->getField($key),
        );

        self::assertInstanceOf(FieldDefinition::class, $field, sprintf('"%s" has no field "%s"', $module, $key));

        return $field;
    }

    /**
     * A record with these values, written straight to the table.
     *
     * Deliberately not through the record form: what these tests are about is
     * what a merge and a refusal do to values that are already in a column, and
     * driving a live component to put them there would make the setup longer
     * than the claim it supports. The one claim that *is* about the write path
     * goes through the form.
     *
     * @param array<string, mixed> $data
     */
    private function writeRecord(string $module, array $data): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $data): int {
            $shape = self::service(MetadataRepository::class)->get($module);
            $saved = self::service(RecordRepository::class)->save($shape, new Record($data));

            return (int) $saved->id;
        });
    }

    /** @param array<string, mixed> $data */
    private function writeRow(string $module, string $collection, int $parent, array $data): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $collection, $parent, $data): void {
            $shape = self::service(MetadataRepository::class)->get($module)->getCollection($collection);
            self::assertInstanceOf(ShapeDefinition::class, $shape);

            self::service(RecordRepository::class)->save($shape, new Record($data, parentId: $parent));
        });
    }

    /**
     * The engine's own opinion of a set of values, without a form anywhere near
     * it.
     *
     * @param array<string, mixed> $data
     */
    private function validate(string $module, array $data): ConstraintViolationListInterface
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ConstraintViolationListInterface => self::service(RecordValidator::class)->validate(
                self::service(MetadataRepository::class)->get($module),
                $data,
            ),
        );
    }

    private function readRecord(string $module, int $id): Record
    {
        $record = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ?Record => self::service(RecordRepository::class)->find(
                self::service(MetadataRepository::class)->get($module),
                $id,
            ),
        );

        self::assertInstanceOf(Record::class, $record);

        return $record;
    }

    /** @return list<mixed> */
    private function rowValues(string $module, string $collection, int $parent, string $field): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($module, $collection, $parent, $field): array {
            $shape = self::service(MetadataRepository::class)->get($module)->getCollection($collection);
            self::assertInstanceOf(ShapeDefinition::class, $shape);

            return array_map(
                static fn (Record $row): mixed => $row->get($field),
                self::service(RecordRepository::class)->findChildren($shape, $parent),
            );
        });
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

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
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ValueList;
use Xivi\Core\Export\RecordExporter;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Field\Type\MultiChoiceFieldType;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Import\ImportReport;
use Xivi\Core\Import\RecordImporter;
use Xivi\Core\Metadata\FieldTypeConversion;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\RecordAccess;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Query\Sort;
use Xivi\Core\Query\UnsupportedQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\Twig\FieldDisplayExtension;
use Xivi\Core\ValueList\ValueListEditor;
use Xivi\Core\ValueList\ValueLists;

/**
 * A field that holds several options ([XIV-169]).
 *
 * One test per decision the ticket asked to be taken, on
 * {@see MultiReferenceTest}'s terms and for its reason: every one of them could
 * be got wrong in a way that looks perfectly right from a page. A joined string
 * still renders, a `unique` flag that matches nothing still saves, an option
 * removal counted with the wrong comparison still *succeeds* and takes the
 * option away, and a filter comparing text still finds something. So what is
 * asserted here is mostly what is **stored**, what is **counted** and what is
 * **refused**.
 *
 * **The subject is a contact with two several-valued fields**, one keeping its
 * own options and one pointed at a shared list, because §5.26's settlement is
 * that both are one question with two answers and nothing below the type may
 * tell them apart. Beside them, on the same shape, a plain `choice`: that field
 * is what proves the other half of the storage criterion, since the single
 * type's storage has to be exactly what it always was.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MultiChoiceTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_multi_choice';
    private const string HOST = 'multichoice.localhost';
    private const string ADMIN = 'admin@multichoice.test';
    private const string PASSWORD = 'multichoice-password';

    /** The field under test, its shared-list twin, and the single choice beside them. */
    private const string SEVERAL = 'languages';
    private const string LISTED = 'topics';
    private const string ONE = 'channel';

    /**
     * The options, in the order the customer arranged them.
     *
     * `en` and `en_gb` are here on purpose and are the prefix pair: a containment
     * filter that had been written as a `LIKE` would find both when asked about
     * either, which is the defect the JSON array exists to make impossible.
     *
     * @var array<string, string>
     */
    private const array OPTIONS = [
        'de' => 'German',
        'fr' => 'French',
        'en' => 'English',
        'en_gb' => 'English (UK)',
    ];

    /** The shared list, and two of its entries carrying a colour. */
    private const string LIST_KEY = 'topics';

    private KernelBrowser $client;
    private Tenant $tenant;
    private string $path;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);
        $this->path = tempnam(sys_get_temp_dir(), 'xivi-multichoice-') . '.xlsx';

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            );

            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $editor = self::service(MetadataEditor::class);

            if ($contacts->getField(self::SEVERAL) === null) {
                $editor->addField(
                    shape: $contacts,
                    key: self::SEVERAL,
                    label: 'Languages',
                    type: 'multi_choice',
                    filterable: true,
                    listed: true,
                    options: [ChoiceFieldType::CHOICES => self::OPTIONS],
                );
            }

            if ($contacts->getField(self::ONE) === null) {
                $editor->addField(
                    shape: $contacts,
                    key: self::ONE,
                    label: 'Channel',
                    type: 'choice',
                    filterable: true,
                    options: [ChoiceFieldType::CHOICES => ['email' => 'Email', 'post' => 'Post']],
                );
            }

            $list = $this->sharedList();

            if ($contacts->getField(self::LISTED) === null) {
                $editor->addField(
                    shape: $contacts,
                    key: self::LISTED,
                    label: 'Topics',
                    type: 'multi_choice',
                    filterable: true,
                    listed: true,
                    options: [ChoiceFieldType::LIST => $list->getKey()],
                );
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    // -- the acceptance criteria, in order -----------------------------------

    /**
     * A record holds several options, the column holds a JSON array of keys, and
     * the single `choice` beside it still holds a bare string.
     *
     * Read out of the column as text rather than through the repository, because
     * a repository that hydrated a joined string into a list would make the two
     * spellings indistinguishable from anywhere above it, and which of the two is
     * written is the whole criterion. The second assertion is the one that says
     * `choice` is untouched: the criterion "the single type is unchanged in
     * storage" is not something a test of the new type can leave to inspection.
     */
    public function testARecordHoldsSeveralKeysAsAnArrayAndTheSingleChoiceIsUnchanged(): void
    {
        $contact = $this->write([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => ['de', 'fr'],
            self::ONE => 'email',
        ]);

        $stored = $this->payloadOf($contact);

        self::assertSame(['de', 'fr'], $stored[self::SEVERAL], 'a JSON array of option keys');
        self::assertSame('email', $stored[self::ONE], 'and one choice is still a bare string');
    }

    /**
     * De-duplicated, canonicalised into the **field's** option order, and the
     * same value however it was picked.
     *
     * The order is where this type diverges from §5.29 deliberately, and both
     * halves of the divergence are asserted. It is not ascending: `en` comes
     * after `fr` because the customer put it there, where sorting would have put
     * it first and made the stored value disagree with the dropdown somebody
     * picked from.
     *
     * And it is a *set*: saving the same options in another order has to be a
     * save that changed nothing, or every re-save would write a history entry
     * about a reordering nobody made (§5.2). That is true because the storage
     * form is canonical and {@see RecordWriter}'s diff compares storage forms
     * with `===`, which for an array is order-sensitive.
     */
    public function testTheStoredOrderIsTheFieldsOwnAndTwoSpellingsOfOneSetAreOneValue(): void
    {
        $contact = $this->write([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => ['en', 'de', 'fr', 'de'],
        ]);

        self::assertSame(
            ['de', 'fr', 'en'],
            $this->payloadOf($contact)[self::SEVERAL],
            'the field arranged them de, fr, en, and that is the order stored, duplicates dropped',
        );

        $this->write([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => ['fr', 'en', 'de'],
        ], $contact);

        self::assertCount(1, $this->historyOf($contact), 'reordering the same options is not a change');
    }

    /**
     * Rearranging the options changes what a record reads like and rewrites no
     * record at all.
     *
     * The other half of the same decision, and the half that pays for it. The
     * display order is read off the field's current options rather than off the
     * stored array, so a customer reordering their list in the editor is a
     * definition change and not a column rewrite. The assertion is therefore two
     * things at once: the sentence moved, and the row did not.
     */
    public function testRearrangingTheOptionsChangesWhatIsShownAndRewritesNoRecord(): void
    {
        $contact = $this->write([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => ['de', 'en'],
        ]);

        self::assertSame('German, English', $this->shownFor($contact, self::SEVERAL));

        $before = $this->payloadOf($contact)[self::SEVERAL];

        // The same four options, in the other order. Nothing else about the field
        // moves, which is what makes this a rearrangement rather than an edit.
        $this->rearrange(['en' => 'English', 'en_gb' => 'English (UK)', 'fr' => 'French', 'de' => 'German']);

        self::assertSame('English, German', $this->shownFor($contact, self::SEVERAL), 'the page follows the field');
        self::assertSame($before, $this->payloadOf($contact)[self::SEVERAL], 'and the record was not touched');
        self::assertCount(1, $this->historyOf($contact), 'nor did anything record that it was');
    }

    /**
     * `unique` is refused by the engine, and the box is not drawn on the form.
     *
     * Both halves matter, on {@see MultiReferenceTest}'s argument: the engine
     * refusing is what makes it true for the importer and the console, and the
     * form not drawing the box is what stops a customer meeting a refusal by
     * ticking something. Inherited rather than restated here, since the refusal
     * is keyed on {@see \Xivi\Core\Field\HoldsSeveralValues} and this type simply
     * declares it, which is the claim being checked.
     */
    public function testUniqueIsRefusedAndTheBoxIsNotDrawnForThisType(): void
    {
        $refusal = null;

        try {
            self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(MetadataEditor::class)->addField(
                shape: self::service(MetadataRepository::class)->get(ContactModule::KEY),
                key: 'more_languages',
                label: 'More languages',
                type: 'multi_choice',
                unique: true,
                options: [ChoiceFieldType::CHOICES => self::OPTIONS],
            ));
        } catch (MetadataChangeRefused $caught) {
            $refusal = $caught;
        }

        self::assertInstanceOf(MetadataChangeRefused::class, $refusal, 'the engine refuses the flag');
        self::assertStringContainsString('several values', $refusal->getMessage());

        $shape = $this->shapeId();

        $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/add/multi_choice', $shape)));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="unique"]', 'a box that would always be refused is not drawn');

        $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/add/text', $shape)));
        self::assertSelectorExists('input[name="unique"]', 'the control itself has not gone missing');
    }

    /**
     * Sorting by it is refused, and the list header does not offer the link.
     *
     * The two go together: the refusal is what makes a typed URL honest, and the
     * header is what stops anybody arriving at one by clicking. Inherited from
     * the capability rather than written here, which is what is being checked.
     */
    public function testSortingIsRefusedAndTheColumnHeaderDoesNotOfferIt(): void
    {
        $this->write(['kind' => ContactModule::COMPANY, 'company_name' => 'Acme AG']);

        $this->client->request('GET', $this->url('/m/contact'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('thead', 'Languages', 'the column is drawn');
        self::assertSelectorNotExists(
            sprintf('thead a[href*="sort=%s"]', self::SEVERAL),
            'and it is a heading rather than a link, because there is no ordering to offer',
        );

        $this->expectException(UnsupportedQuery::class);

        $this->find(new RecordQuery([], [new Sort(self::SEVERAL)], 1, 25));
    }

    /**
     * "Holds this option" finds the records holding it, and one key being a
     * prefix of another produces no false match.
     *
     * **The prefix pair is the point of this test.** `en` and `en_gb` are two
     * ordinary options of one field, and every key here is lowercase ASCII,
     * digits and underscores by construction
     * ({@see ChoiceFieldType::valueFor()}), so keys really do sit inside one
     * another far more readily than record ids do. A joined string compared with
     * `LIKE '%en%'` would find the record holding `en_gb` when asked about `en`,
     * and would find it when asked about `de` as well, since `de` is inside
     * neither but `en` is inside `en_gb`. Containment over a JSON array compares
     * elements and cannot make either mistake.
     */
    public function testIncludesIsContainmentAndAPrefixKeyIsNotAFalseMatch(): void
    {
        $english = $this->write(['kind' => ContactModule::COMPANY, 'company_name' => 'English', self::SEVERAL => ['en']]);
        $british = $this->write(['kind' => ContactModule::COMPANY, 'company_name' => 'British', self::SEVERAL => ['en_gb']]);
        $both = $this->write(['kind' => ContactModule::COMPANY, 'company_name' => 'Both', self::SEVERAL => ['en', 'en_gb']]);
        $none = $this->write(['kind' => ContactModule::COMPANY, 'company_name' => 'None']);

        self::assertSame(
            [$both, $english],
            $this->matching(new Filter(self::SEVERAL, Operator::Includes, 'en')),
            'asking about en finds en, and does not find en_gb',
        );

        self::assertSame(
            [$both, $british],
            $this->matching(new Filter(self::SEVERAL, Operator::Includes, 'en_gb')),
            'and asking about en_gb finds only the records really holding it',
        );

        // Not holding it, records with nothing in the field included: a contact
        // with no languages genuinely does not speak this one.
        self::assertSame([$none, $british], $this->matching(new Filter(self::SEVERAL, Operator::Excludes, 'en')));

        self::assertSame([$none], $this->matching(new Filter(self::SEVERAL, Operator::IsEmpty, null)));

        // And two of them mean *and*, like every other pair of filters, because
        // "has any of these" is the `OR` tree §7.3 says is not built.
        self::assertSame([$both], $this->matching(
            new Filter(self::SEVERAL, Operator::Includes, 'en'),
            new Filter(self::SEVERAL, Operator::Includes, 'en_gb'),
        ));
    }

    /**
     * Removing an option records hold is counted and refused, and the count is
     * the one that would otherwise have been silently zero.
     *
     * **The engine question of this ticket.** §5.4 refuses a change that strands
     * records, and enforces it with a number; the number came from
     * `data ->> 'key' = 'de'`, which for an array is the array's own text and
     * therefore matches no option there has ever been. Written that way, this
     * refusal would not have fired, the option would have come off the list from
     * under every record holding it, and **nothing would have reported it.** A rule
     * enforced by a count that is always zero is a rule switched off.
     *
     * So the editor asks the type
     * ({@see \Xivi\Core\Field\Enumerates::findsHoldersBy()}), and the assertions
     * come in a pair for XIV-60's reason: a refusal that fires is only worth
     * something beside an option nothing holds, which comes off without argument.
     * The refusal naming the *count* is the third: a message reading "1 record"
     * is a message that reached the comparison rather than an exception raised
     * for some other reason.
     */
    public function testRemovingAnOptionRecordsHoldIsCountedAndRefused(): void
    {
        $this->write([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => ['de', 'fr'],
        ]);

        $held = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(MetadataEditor::class)->valuesHeldBy($this->fieldOf(self::SEVERAL)),
        );

        self::assertSame(['de' => 1, 'fr' => 1], $held, 'the count sees inside the array');

        $refusal = null;

        try {
            // German out, everything else left exactly as it was.
            $this->rearrange(['fr' => 'French', 'en' => 'English', 'en_gb' => 'English (UK)']);
        } catch (MetadataChangeRefused $caught) {
            $refusal = $caught;
        }

        self::assertInstanceOf(MetadataChangeRefused::class, $refusal, 'an option a record holds does not come off');
        self::assertStringContainsString('1', $refusal->getMessage(), 'and the refusal says how many records hold it');

        self::assertArrayHasKey(
            'de',
            $this->optionsOfTheField(),
            'the definition is exactly as it was, because a refusal writes nothing',
        );

        // And the corroboration: an option nothing holds comes off without
        // argument, so the refusal above is about the records rather than about
        // removals in general (XIV-60).
        $this->rearrange(['de' => 'German', 'fr' => 'French', 'en' => 'English']);

        self::assertArrayNotHasKey('en_gb', $this->optionsOfTheField(), 'an option nothing holds is free to go');
    }

    /**
     * Export writes the separator form and import reads it back: §5.6's round
     * trip, with several option keys in one cell.
     *
     * The cell itself is asserted rather than only the round trip, because a
     * file holding something nobody could read would round-trip perfectly and
     * §5.6 says the file is the interchange format. The comma is safe here
     * without an escape because every key is lowercase ASCII, digits and
     * underscores.
     */
    public function testExportWritesTheSeparatorFormAndImportReadsItBack(): void
    {
        $contact = $this->write([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::SEVERAL => ['de', 'en'],
        ]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(RecordExporter::class)
            ->toFile($this->module(), new RecordQuery(), RecordAccess::unrestricted(), $this->path));

        self::assertSame('de,en', $this->cellOf('contact', self::SEVERAL), 'the keys go into one cell, comma separated');

        $report = $this->import();

        self::assertTrue($report->applied, 'the file re-imports');
        self::assertSame(0, $report->created, 'a re-import is not a second copy');
        self::assertSame(['de', 'en'], $this->payloadOf($contact)[self::SEVERAL]);
    }

    /**
     * A `choice` field becomes a `multi_choice` one with every row surviving.
     *
     * XIV-146's dry run reads every live value through the type moving in, and
     * {@see MultiChoiceFieldType::toStorage()} reads one option as a set of one,
     * which is {@see \Xivi\Core\Field\Type\MultiReferenceFieldType}'s trick with
     * a key in place of an id. So the whole column survives, and the criterion is
     * verified rather than assumed.
     *
     * **The plan rather than the conversion, and that is a finding rather than a
     * shortcut.** §5.4 does not offer converting *into* a type that has to be
     * told what its values mean, so `choice` and `reference` are absent from the
     * conversion page and `multi_choice` and `multi_reference` are absent for the
     * same reason: they need the same answer. The dry run is where the promise
     * "every row survives" actually lives, and it is exactly what is asked here.
     * The way back is refused by that same standing rule rather than reported
     * lossy, since nothing converts into a `choice` either.
     */
    public function testAChoiceFieldConvertsIntoThisTypeWithEveryRowSurviving(): void
    {
        foreach (['email', 'post', 'email'] as $index => $channel) {
            $this->write([
                'kind' => ContactModule::COMPANY,
                'company_name' => sprintf('Company %d', $index),
                self::ONE => $channel,
            ]);
        }

        $plan = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(FieldTypeConversion::class)->plan($this->fieldOf(self::ONE), 'multi_choice'),
        );

        self::assertSame(3, $plan->records, 'every live value is read, not a sample');
        self::assertSame(3, $plan->converts, 'and every one of them survives');
        self::assertSame(0, $plan->refuses);
        self::assertSame(3, $plan->changes, 'each becomes a set of one, which is a different spelling');

        // And the report a customer reads before agreeing says the door is
        // two-way, which it is while every record holds one option. The dry run
        // works that out by reading the converted value back through the type the
        // field has today, so this assertion is also what keeps
        // {@see ChoiceFieldType::toStorage()} answering a list rather than
        // casting one into the word "Array" and a PHP warning ([XIV-169]).
        self::assertTrue($plan->reversible, 'a column of single values converts both ways');
    }

    /**
     * The same type, taking its values from a shared list, drawing a chip each.
     *
     * §5.26 settled before either multi-value type existed that a field holding
     * several of a list's entries would point at the same rows through the same
     * option and the same capability. This is that promise exercised: the entries
     * come from the list, their colours come with them, and one chip is drawn per
     * value rather than one line with commas in it.
     *
     * Asked through {@see FieldDisplayExtension}, which is the door a template
     * goes through, so what is checked is what a page would draw rather than a
     * method nothing calls.
     */
    public function testAFieldPointedAtASharedListDrawsAChipPerValue(): void
    {
        $contact = $this->write([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::LISTED => ['support', 'billing'],
        ]);

        $badges = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($contact): array {
            $record = $this->recordOf($contact);

            return self::service(FieldDisplayExtension::class)
                ->valueBadges($this->fieldOf(self::LISTED), $record->get(self::LISTED));
        });

        self::assertCount(2, $badges, 'two values, two chips');
        self::assertSame('Billing', $badges[0]->label, 'in the list\'s own order rather than the picked one');
        self::assertSame('Support', $badges[1]->label);
        self::assertNotNull($badges[0]->tone, 'and the entry\'s own colour comes with it');
    }

    /**
     * An entry of a shared list that a several-valued field holds cannot be
     * removed, and merging two rewrites what is inside the array.
     *
     * **The second half is the one that would have failed silently.** A merge is
     * an irreversible rewrite across every field pointing at the list (§5.26),
     * and it was one `UPDATE … WHERE data ->> 'key' = 'billing'`. Against a field
     * holding a set that matches nothing, so the merge would have reported
     * rewriting nothing, said so on a page nobody would have doubted, and left
     * half of somebody's data saying the old thing for ever, which is the exact
     * outcome that section says the merge exists to prevent.
     */
    public function testASharedListSeesInsideThisTypesValues(): void
    {
        $contact = $this->write([
            'kind' => ContactModule::COMPANY,
            'company_name' => 'Acme AG',
            self::LISTED => ['support', 'billing'],
        ]);

        $refusal = null;

        try {
            self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(ValueListEditor::class)
                ->update($this->sharedList(), 'Topics', [], ['billing']));
        } catch (MetadataChangeRefused $caught) {
            $refusal = $caught;
        }

        self::assertInstanceOf(MetadataChangeRefused::class, $refusal, 'an entry a record holds does not come off');
        self::assertStringContainsString('Topics', $refusal->getMessage(), 'and the field it is held in is named');

        $written = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => self::service(ValueListEditor::class)->merge($this->sharedList(), 'billing', 'support'),
        );

        self::assertSame(1, $written, 'the merge finds the record holding the entry among several');
        self::assertSame(
            ['support'],
            $this->payloadOf($contact)[self::LISTED],
            'and the two collapse into one, de-duplicated, rather than being left side by side',
        );
    }

    /**
     * A collection row may hold one, and that is decided rather than defaulted.
     *
     * XIV-113 allowed it and XIV-115 refused it, and the two are consistent: a
     * file is refused on a collection because a download is addressed by module
     * and record id and a row has no address, which is a property of *files*
     * rather than of holding several things. A set of option keys is a JSON array
     * in a JSON payload and a collection's rows have exactly the same payload
     * (§5.1), so there is nothing here to refuse and refusing it would be a rule
     * with no reason under it.
     */
    public function testACollectionRowMayHoldSeveralOptions(): void
    {
        $field = self::service(TenantSwitcher::class)->runFor($this->tenant, function (): FieldDefinition {
            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $collection = $contacts->getCollections()->first();
            self::assertInstanceOf(CollectionDefinition::class, $collection, 'the contact module ships a collection');

            return $collection->getField(self::SEVERAL) ?? self::service(MetadataEditor::class)->addField(
                shape: $collection,
                key: self::SEVERAL,
                label: 'Languages',
                type: 'multi_choice',
                options: [ChoiceFieldType::CHOICES => self::OPTIONS],
            );
        });

        self::assertSame('multi_choice', $field->getType());
    }

    // -- fixtures and helpers ------------------------------------------------

    /**
     * The shared list, made once and found thereafter.
     *
     * Two entries carry a colour and one does not, which is what makes the badge
     * assertion about the entry rather than about the type: a chip is drawn per
     * value here whether or not somebody coloured it, and §5.26's own rule for a
     * lone value is the opposite.
     */
    private function sharedList(): ValueList
    {
        $lists = self::service(ValueLists::class);
        $existing = $lists->find(self::LIST_KEY);

        if ($existing !== null) {
            return $existing;
        }

        $list = self::service(ValueListEditor::class)->create('Topics');

        self::service(ValueListEditor::class)->update($list, 'Topics', [], [], ['Billing', 'Support']);

        $entries = [];

        foreach ($list->getEntries() as $entry) {
            $entries[$entry->getValue()] = ['label' => $entry->getLabel(), 'tone' => 'primary'];
        }

        self::service(ValueListEditor::class)->update($list, 'Topics', $entries, []);

        return $list;
    }

    /**
     * The field's options, set to exactly this map.
     *
     * @param array<string, string> $options
     */
    private function rearrange(array $options): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($options): void {
            $field = $this->fieldOf(self::SEVERAL);

            self::service(MetadataEditor::class)->updateField(
                field: $field,
                label: $field->getLabel(),
                required: false,
                unique: false,
                filterable: true,
                listed: true,
                title: false,
                position: $field->getPosition(),
                options: [ChoiceFieldType::CHOICES => $options],
            );
        });
    }

    /** @return array<string, string> */
    private function optionsOfTheField(): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => ChoiceFieldType::choicesOf($this->fieldOf(self::SEVERAL)),
        );
    }

    /** What a document, an export cell or a record's own name would read. */
    private function shownFor(int $id, string $key): string
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id, $key): string {
            $field = $this->fieldOf($key);
            $record = $this->recordOf($id);

            return self::service(FieldTypeRegistry::class)->get($field->getType())->display($record->get($key), $field);
        });
    }

    /** Inside a tenant context already. */
    private function recordOf(int $id): Record
    {
        $record = self::service(RecordRepository::class)->find($this->module(), $id);
        self::assertInstanceOf(Record::class, $record);

        return $record;
    }

    /**
     * The ids matching these filters, oldest last, which is the order every list
     * is in.
     *
     * @return list<int>
     */
    private function matching(Filter ...$filters): array
    {
        return array_map(
            static fn (Record $record): int => (int) $record->id,
            $this->find(new RecordQuery(array_values($filters))),
        );
    }

    /** @return list<Record> */
    private function find(RecordQuery $query): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(RecordRepository::class)
                ->findBy($this->module(), $query, RecordAccess::unrestricted()),
        );
    }

    /**
     * A record written straight through the writer.
     *
     * Deliberately not through the record form, on {@see MultiReferenceTest}'s
     * reason: what these tests are about is what the engine does with values that
     * are there, and driving a live component to put them there would make the
     * setup longer than the claim.
     *
     * @param array<string, mixed> $data
     */
    private function write(array $data, ?int $id = null): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($data, $id): int {
            $shape = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $record = $id === null ? new Record(data: $data) : new Record(data: $data, id: $id);

            return (int) self::service(RecordWriter::class)->save($shape, $record)->id;
        });
    }

    /**
     * The row's payload as JSON gave it back, rather than as the repository
     * hydrated it.
     *
     * The difference is the whole of the storage criterion: a repository that
     * read a joined string into a list would make the two spellings
     * indistinguishable from anywhere above it.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(int $id): array
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): array {
            $shape = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            $json = self::tenantConnection()->fetchOne(
                sprintf('SELECT data FROM %s WHERE id = :id', $shape->getTableName()),
                ['id' => $id],
            );

            $decoded = json_decode(\is_string($json) ? $json : '{}', true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded));

            return $decoded;
        });
    }

    /**
     * The tenant's own connection, not the default one.
     *
     * `default_connection` is `control` (§3.1), so asking the container for a
     * `Connection` in a test gives the control plane's, and a customer's records
     * are not in it.
     */
    private static function tenantConnection(): Connection
    {
        $registry = self::getContainer()->get('doctrine');
        \assert($registry instanceof \Doctrine\Persistence\ManagerRegistry);

        $connection = $registry->getConnection('tenant');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /** @return list<mixed> */
    private function historyOf(int $id): array
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(HistoryRepository::class)->findFor($this->module(), $id),
        );
    }

    private function module(): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(ContactModule::KEY);
    }

    private function fieldOf(string $key): FieldDefinition
    {
        $field = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField($key);
        self::assertInstanceOf(FieldDefinition::class, $field);

        return $field;
    }

    private function shapeId(): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => (int) $this->module()->getId(),
        );
    }

    private function cellOf(string $sheet, string $field): string
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($sheet, $field): string {
            $reader = new \OpenSpout\Reader\XLSX\Reader();
            $reader->open($this->path);

            $header = [];
            $value = '';

            foreach ($reader->getSheetIterator() as $page) {
                if (mb_strtolower($page->getName()) !== $sheet) {
                    continue;
                }

                foreach ($page->getRowIterator() as $index => $row) {
                    if ($index === 1) {
                        $header = array_map(self::asText(...), $row->toArray());

                        continue;
                    }

                    $column = array_search($field, $header, true);
                    self::assertIsInt($column, sprintf('the sheet has a "%s" column', $field));

                    $value = self::asText($row->toArray()[$column] ?? '');

                    break;
                }
            }

            $reader->close();

            return $value;
        });
    }

    /**
     * One cell as the text a header or a value reads as.
     *
     * Anything that is not a scalar reads as blank rather than being cast, which
     * would be a fatal on a rich text run nobody put there.
     */
    private static function asText(mixed $cell): string
    {
        return \is_scalar($cell) ? (string) $cell : '';
    }

    private function import(): ImportReport
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): ImportReport => self::service(RecordImporter::class)->apply($this->module(), $this->path),
        );
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
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

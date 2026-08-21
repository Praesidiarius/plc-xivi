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
use Xivi\Contact\ContactModule;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ValueList;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Metadata\MetadataChangeRefused;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;
use Xivi\Core\ValueList\ValueListEditor;
use Xivi\Core\ValueList\ValueLists;

/**
 * Values a customer wants read first, at the top of the record page ([XIV-173]).
 *
 * Every assertion here is made against the **rendered record page**, which is
 * unusual for an engine test and is the point of this one. What was built is a
 * strip of chips in a header that already had four things in it, and every way
 * of getting it wrong is a way that a unit test of the function behind it would
 * pass: the values could be right and land in the wrong place, the container
 * could be drawn empty and move the title, an unpromoted field could leak in
 * because the loop asked the wrong question, or the cap could be counted per
 * field instead of across the strip. So this drives the page and reads the
 * markup, and only the two refusals are asked of the engine directly, because
 * a refusal is exactly the thing a page cannot show you.
 *
 * **The subject is a contact with three promoted fields and one that is not.**
 * A `multi_choice` pointed at a shared list, because that is the case the ticket
 * was asked for and the one that carries colours; a `multi_choice` keeping its
 * own options, because §5.26's settlement is that a list and a field's own set
 * are one question with two answers; and a single `choice`, because a Region at
 * the top of a page was decided to be a real want on its own. The unpromoted
 * `text` field beside them is what proves the flag is doing the deciding rather
 * than the page drawing everything it can find.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PromotedValuesTest extends WebTestCase
{
    use SharesATenant;

    private const string SLUG = 'test_promoted_values';
    private const string HOST = 'promoted.localhost';
    private const string ADMIN = 'admin@promoted.test';
    private const string PASSWORD = 'promoted-password';

    /** Promoted: several values out of a shared list, which is the tags case. */
    private const string TAGS = 'tags';

    /** Promoted: several values out of the field's own options. */
    private const string LANGUAGES = 'languages';

    /** Promoted: one value, which falls out of the same flag for free. */
    private const string REGION = 'region';

    /** Not promoted, and drawn in the read view like every field always was. */
    private const string NICKNAME = 'nickname';

    private const string LIST_KEY = 'tags';

    /**
     * Four entries, which is one more than the header draws.
     *
     * The cap is three and the fourth is what makes the count appear, so a list
     * of exactly three would leave the most breakable half of this untested.
     *
     * @var list<string>
     */
    private const array ENTRIES = ['Urgent', 'Key account', 'Late payer', 'Reseller'];

    /** @var array<string, string> */
    private const array LANGUAGES_OPTIONS = ['de' => 'German', 'fr' => 'French'];

    /** @var array<string, string> */
    private const array REGION_OPTIONS = ['east' => 'Eastern', 'west' => 'Western'];

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            );

            $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $editor = self::service(MetadataEditor::class);
            $list = $this->sharedList();

            if ($contacts->getField(self::TAGS) === null) {
                $editor->addField(
                    shape: $contacts,
                    key: self::TAGS,
                    label: 'Tags',
                    type: 'multi_choice',
                    options: [ChoiceFieldType::LIST => $list->getKey()],
                    promoted: true,
                );
            }

            if ($contacts->getField(self::LANGUAGES) === null) {
                $editor->addField(
                    shape: $contacts,
                    key: self::LANGUAGES,
                    label: 'Languages',
                    type: 'multi_choice',
                    options: [ChoiceFieldType::CHOICES => self::LANGUAGES_OPTIONS],
                    promoted: true,
                );
            }

            if ($contacts->getField(self::REGION) === null) {
                $editor->addField(
                    shape: $contacts,
                    key: self::REGION,
                    label: 'Region',
                    type: 'choice',
                    options: [ChoiceFieldType::CHOICES => self::REGION_OPTIONS],
                    promoted: true,
                );
            }

            if ($contacts->getField(self::NICKNAME) === null) {
                $editor->addField(
                    shape: $contacts,
                    key: self::NICKNAME,
                    label: 'Nickname',
                    type: 'text',
                );
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    // -- the acceptance criteria, in order -----------------------------------

    /**
     * The values land in the header, in the shared list's own colours, and one
     * chip each.
     *
     * Read out of `.record-promoted` rather than out of the page, because the
     * whole claim is about *where* they are: the same two words are also in the
     * read view below, so a test asserting that the page mentions them would go
     * green with nothing built at all.
     *
     * The colour assertion is what says this went through
     * `_value_badge.html.twig` rather than through a second rendering path
     * somebody wrote for the header. A tone the customer chose becomes
     * `bg-primary-subtle`, and no hand-written strip would produce that class by
     * accident.
     */
    public function testAPromotedFieldsValuesAreDrawnInTheHeaderWithTheListsOwnColours(): void
    {
        $this->show($this->contact([self::TAGS => ['urgent', 'key_account']]));

        self::assertSelectorExists('.record-promoted');
        self::assertSelectorTextContains('.record-promoted', 'Urgent');
        self::assertSelectorTextContains('.record-promoted', 'Key account');
        self::assertSelectorExists(
            '.record-promoted .badge.bg-primary-subtle',
            'the chip carries the entry\'s own colour, through the badge template',
        );
    }

    /**
     * A field nobody promoted stays out of the header and keeps its place in the
     * read view.
     *
     * Both halves in one test, because they are one decision. Promotion is an
     * *addition*: a promoted field is drawn twice and an unpromoted one is drawn
     * exactly where it always was, and a page that moved either of them would be
     * rearranging a form somebody deliberately arranged (XIV-119).
     */
    public function testAnUnpromotedFieldIsNotInTheHeaderAndIsStillInTheReadView(): void
    {
        $this->show($this->contact([
            self::TAGS => ['urgent'],
            self::NICKNAME => 'Ackers',
        ]));

        self::assertSelectorTextNotContains('.record-promoted', 'Ackers', 'not up here');
        self::assertSelectorTextContains('.col-lg-8', 'Ackers', 'and still down there');
        self::assertSelectorTextContains('.col-lg-8', 'Nickname', 'under its own label');
    }

    /**
     * A promoted field is still drawn in full below, which is what the count in
     * the header points at.
     *
     * The other half of the same decision, from the promoted side. The header
     * shows three of four; the read view shows the field under its own label with
     * every value in it, which is why the "+1" needs no link and why moving a
     * promoted field out of its section was refused.
     */
    public function testAPromotedFieldIsStillDrawnInTheReadViewUnderItsOwnLabel(): void
    {
        $this->show($this->contact([self::TAGS => ['urgent', 'key_account', 'late_payer', 'reseller']]));

        self::assertSelectorTextContains('.col-lg-8', 'Tags', 'the field kept its row');
        self::assertSelectorTextContains('.col-lg-8', 'Reseller', 'with the value the header had no room for');
    }

    /**
     * A record with nothing in any promoted field draws no container at all.
     *
     * `assertSelectorNotExists` rather than an assertion about text, because the
     * criterion is that the header does not *move*: an empty `<span>` with a
     * margin on it would satisfy "shows nothing" and still push the line along.
     * The wrapper exists only when there is something in it, so its absence is
     * the whole claim.
     */
    public function testARecordWithNoPromotedValueDrawsNothingAndLeavesNoGap(): void
    {
        $this->show($this->contact([self::NICKNAME => 'Ackers']));

        self::assertSelectorNotExists('.record-promoted', 'no container, so nothing to leave a gap');
        self::assertSelectorTextContains('h1', 'Acme AG', 'and the title is where it always was');
    }

    /**
     * A `multi_choice` field promotes several values, one chip each.
     *
     * Counted rather than read, because the failure this catches is the one
     * that renders perfectly: `display()` would give "German, French" in a single
     * chip, which looks right until a customer names an option `Zurich, CH` and
     * two values read as three. {@see \Xivi\Core\Field\ShowsSeveralBadges} has
     * that argument; this is it held to.
     */
    public function testAMultiChoiceFieldPromotesEveryOneOfItsValuesAsItsOwnChip(): void
    {
        $crawler = $this->show($this->contact([self::LANGUAGES => ['de', 'fr']]));

        self::assertCount(2, $crawler->filter('.record-promoted .badge'), 'two values, two chips');
        self::assertSelectorTextContains('.record-promoted', 'German');
        self::assertSelectorTextContains('.record-promoted', 'French');
    }

    /**
     * A single-valued `choice` keeping its own options is promoted too, and is
     * drawn as a chip rather than as a bare word.
     *
     * The chip is the decision worth asserting. §5.26 says a badge around an
     * uncoloured word is furniture, and that is why `value_badges()` answers with
     * nothing here. But that is a statement about a *read view*, where a value
     * sits on a line under its label. In the header everything is a badge, so a lone
     * value drawn as bare text beside the module label would read as something
     * that failed to render.
     */
    public function testASingleChoiceWithItsOwnOptionsIsPromotedAndDrawnAsAChip(): void
    {
        $crawler = $this->show($this->contact([self::REGION => 'east']));

        self::assertSelectorTextContains('.record-promoted', 'Eastern');
        self::assertCount(1, $crawler->filter('.record-promoted .badge'), 'one value, one chip');
    }

    /**
     * Three chips and then a count, taken across the whole strip rather than per
     * field.
     *
     * Six values over two fields, so a cap counted per field would draw five
     * chips and pass an assertion about "+N" existing at all. What is asserted is
     * the number: three drawn, three counted. The room being shared out is the
     * header, and no one field owns a share of it.
     */
    public function testThirtyTagsDoNotPushTheTitleOffAndTheRestAreCounted(): void
    {
        $crawler = $this->show($this->contact([
            self::TAGS => ['urgent', 'key_account', 'late_payer', 'reseller'],
            self::LANGUAGES => ['de', 'fr'],
        ]));

        self::assertCount(4, $crawler->filter('.record-promoted .badge'), 'three values and the count');
        self::assertSelectorTextContains('.record-promoted', '+3', 'and the count is of everything left over');
        self::assertSelectorTextNotContains('.record-promoted', 'German', 'the fourth value onwards is not drawn');
    }

    /**
     * Several promoted fields read in the shape's own field order.
     *
     * `position` decides it, exactly as it decides the form and the read view, so
     * nothing new had to be invented and nothing new may drift from it. Asserted
     * by moving one field above another and watching the strip follow: the order
     * of the *values* is a fact about the fields rather than about the record, so
     * a page that had sorted by anything else would still have looked plausible
     * on the first arrangement.
     */
    public function testSeveralPromotedFieldsFollowTheShapesOwnFieldOrder(): void
    {
        $contact = $this->contact([
            self::REGION => 'east',
            self::LANGUAGES => ['de'],
        ]);

        $crawler = $this->show($contact);
        self::assertSame(
            ['German', 'Eastern'],
            $this->chips($crawler),
            'languages sits above region on the form, so it is drawn first',
        );

        $this->moveToFront(self::REGION);

        $crawler = $this->show($contact);
        self::assertSame(
            ['Eastern', 'German'],
            $this->chips($crawler),
            'and the strip follows the arrangement rather than a rule of its own',
        );
    }

    /**
     * The flag is refused on a type whose values are not a set the customer
     * keeps, and the editor draws no box for one.
     *
     * Both halves, on {@see MultiChoiceTest}'s argument about `unique`: the
     * engine refusing is what makes the rule true for the importer and the
     * console, and the arrange page not drawing the box is what stops a customer
     * meeting the refusal by ticking something.
     */
    public function testPromotionIsRefusedOnATypeWithNoValueSetAndTheBoxIsNotDrawn(): void
    {
        $refusal = null;

        try {
            self::service(TenantSwitcher::class)->runFor($this->tenant, fn () => self::service(MetadataEditor::class)->addField(
                shape: self::service(MetadataRepository::class)->get(ContactModule::KEY),
                key: 'motto',
                label: 'Motto',
                type: 'text',
                promoted: true,
            ));
        } catch (MetadataChangeRefused $caught) {
            $refusal = $caught;
        }

        self::assertInstanceOf(MetadataChangeRefused::class, $refusal, 'the engine refuses the flag');
        self::assertStringContainsString('chip', $refusal->getMessage());

        $crawler = $this->client->request('GET', $this->url(sprintf('/m/contact/fields/%d/arrange', $this->shapeId())));
        self::assertResponseIsSuccessful();

        self::assertCount(
            1,
            $crawler->filter(sprintf('input[name="promoted[%d]"]', $this->fieldId(self::TAGS))),
            'a type that enumerates something gets the box',
        );
        self::assertCount(
            0,
            $crawler->filter(sprintf('input[name="promoted[%d]"]', $this->fieldId(self::NICKNAME))),
            'and a text field does not',
        );
    }

    /**
     * A collection's field cannot be promoted.
     *
     * The same refusal a section meets on a collection, one page along: a
     * collection is a list of rows *inside* a record, so its fields describe a
     * row, and a contact with four addresses has four answers to "which kind" and
     * none of them is the contact's.
     */
    public function testACollectionsFieldCannotBePromoted(): void
    {
        $refusal = null;

        try {
            self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
                $contacts = self::service(MetadataRepository::class)->get(ContactModule::KEY);
                $collection = $contacts->getCollections()->first();
                self::assertInstanceOf(CollectionDefinition::class, $collection, 'the contact module ships a collection');

                self::service(MetadataEditor::class)->addField(
                    shape: $collection,
                    key: 'row_tags',
                    label: 'Row tags',
                    type: 'multi_choice',
                    options: [ChoiceFieldType::CHOICES => self::LANGUAGES_OPTIONS],
                    promoted: true,
                );
            });
        } catch (MetadataChangeRefused $caught) {
            $refusal = $caught;
        }

        self::assertInstanceOf(MetadataChangeRefused::class, $refusal, 'a row is not a record');
        self::assertStringContainsString('rows inside a record', $refusal->getMessage());
    }

    // -- fixtures and helpers ------------------------------------------------

    /**
     * The shared list, made once and found thereafter.
     *
     * Every entry carries a colour, because the colour is half of what the header
     * is for: a chip that is grey whatever it says is a chip that has to be read
     * rather than recognised.
     */
    private function sharedList(): ValueList
    {
        $lists = self::service(ValueLists::class);
        $existing = $lists->find(self::LIST_KEY);

        if ($existing !== null) {
            return $existing;
        }

        $editor = self::service(ValueListEditor::class);
        $list = $editor->create('Tags');
        $editor->update($list, 'Tags', [], [], self::ENTRIES);

        $entries = [];

        foreach ($list->getEntries() as $entry) {
            $entries[$entry->getValue()] = ['label' => $entry->getLabel(), 'tone' => 'primary'];
        }

        $editor->update($list, 'Tags', $entries, []);

        return $list;
    }

    /**
     * One company, written straight through the writer.
     *
     * Not through the record form, on {@see MultiChoiceTest}'s reason: what is
     * under test is what a page does with values that are already there, and
     * driving a live component to put them there would make the setup longer than
     * the claim.
     *
     * @param array<string, mixed> $data
     */
    private function contact(array $data): int
    {
        return self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($data): int {
            $shape = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $record = new Record(data: [
                'kind' => ContactModule::COMPANY,
                'company_name' => 'Acme AG',
                ...$data,
            ]);

            return (int) self::service(RecordWriter::class)->save($shape, $record)->id;
        });
    }

    private function show(int $id): Crawler
    {
        $crawler = $this->client->request('GET', $this->url(sprintf('/m/contact/%d', $id)));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * The labels in the header strip, in the order they are drawn.
     *
     * @return list<string>
     */
    private function chips(Crawler $crawler): array
    {
        return $crawler->filter('.record-promoted .badge')->each(
            static fn (Crawler $chip): string => trim($chip->text()),
        );
    }

    /**
     * One field moved above every other, through the editor rather than by
     * writing the column.
     *
     * `updateField()` is the door the arrange page goes through, refusals and
     * all, so a reordering done here is the reordering a customer would do.
     * Everything else about the field is handed back as it already is, which is
     * what that method takes.
     */
    private function moveToFront(string $key): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($key): void {
            $field = $this->fieldOf($key);

            self::service(MetadataEditor::class)->updateField(
                field: $field,
                label: $field->getLabel(),
                required: $field->isRequired(),
                unique: $field->isUnique(),
                filterable: $field->isFilterable(),
                listed: $field->isListed(),
                title: $field->isTitle(),
                position: -10,
                width: $field->getWidth(),
                section: $field->getSection(),
                promoted: $field->isPromoted(),
            );
        });
    }

    private function fieldOf(string $key): FieldDefinition
    {
        $field = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField($key);
        self::assertInstanceOf(FieldDefinition::class, $field);

        return $field;
    }

    private function fieldId(string $key): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => (int) $this->fieldOf($key)->getId(),
        );
    }

    private function shapeId(): int
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): int => (int) $this->module()->getId(),
        );
    }

    private function module(): ModuleDefinition
    {
        return self::service(MetadataRepository::class)->get(ContactModule::KEY);
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

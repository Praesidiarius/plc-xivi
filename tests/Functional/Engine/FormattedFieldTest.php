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
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Xivi\Contact\ContactModule;
use Xivi\Core\Document\DocumentMarkers;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\HoldsFormattedText;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * A field that holds formatted text (XIV-131).
 *
 * **The assertion this file exists for is the second one**, and everything else
 * here is the surrounding decision. §5.13 built Markdown rendering for email and
 * the valuable half was never the rendering: it was that substitution happens on
 * the *source*, with the parser told to escape raw HTML, so a value containing a
 * script tag becomes text **without anybody remembering to make it so**. This
 * ticket is the second caller on that property, and a property with two callers
 * is one that needs a test naming it rather than a comment claiming it.
 *
 * The record page is now the one place in this application where a customer's
 * own value reaches a page as markup rather than as text. That is worth a test
 * that is blunt about what it is checking: a `<script>` goes into a record, and
 * what comes back has to be a page with no script element inside the block that
 * value was drawn in.
 *
 * The rest of the file is §5.21's other decisions, each asserted where it
 * happens rather than described:
 *
 * - a list column and a Word document get the words with the marks taken off;
 * - a filter matches the source, which is what is stored;
 * - the export gets the source, because the column holds it untouched;
 * - and an existing `textarea` field is exactly what it was.
 *
 * The fields are added through {@see MetadataEditor}, which is how a customer
 * would add one — a module blueprint declaring a formatted field is not part of
 * this ticket, and testing through the editor is testing the path anybody
 * actually has.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class FormattedFieldTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_formatted_field';
    private const string HOST = 'formatted.localhost';
    private const string EMAIL = 'formatted@example.test';
    private const string PASSWORD = 'formatted-password';

    /** The formatted one. */
    private const string PROCEDURE = 'procedure';

    /** And a plain `textarea` beside it, which must go on behaving as it did. */
    private const string NOTE = 'note';

    /**
     * One value, used for both fields, carrying every case at once.
     *
     * A heading, emphasis, a list — and a script tag in the middle of a sentence,
     * which is the reason this constant is shaped the way it is. Written as an
     * inline `<script>` rather than on a line of its own deliberately: a raw HTML
     * *block* and raw HTML *inline* take different paths through CommonMark, and
     * the inline one is the path a value substituted into a sentence takes.
     */
    private const string SOURCE = <<<'MARKDOWN'
        ## Safety first

        Wear **gloves** before <script>alert(1)</script> touching it.

        - Check the seal
        - Log the reading
        MARKDOWN;

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
        });

        // Both are on the list and both are filterable, so that the two
        // decisions about what a cell says and what a filter matches can be
        // asserted about the same record.
        $this->addField(self::PROCEDURE, 'Procedure', 'markdown');
        $this->addField(self::NOTE, 'Note', 'textarea');

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'Formatted', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
    }

    /**
     * The one assertion this ticket is answerable for.
     *
     * A record holds a script tag; the page holds a paragraph that reads
     * `<script>`. Three assertions rather than one, because each of them fails
     * for a different reason and the messages should say which:
     *
     * 1. no script *element* inside the block the value was drawn in — the thing
     *    that would actually run;
     * 2. the escaped text is present — so the value was not silently dropped,
     *    which would pass the first assertion perfectly;
     * 3. the unescaped opening tag is nowhere in the document at all, which
     *    catches it having leaked into an attribute or a comment somewhere the
     *    selector above does not reach.
     */
    public function testAValueContainingMarkupArrivesAsText(): void
    {
        $page = $this->show($this->save());

        self::assertCount(0, $page->filter('.markdown-body script'), 'nothing executable came out of a record value');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->filter('.markdown-body')->html());
        self::assertStringNotContainsString('<script>alert(1)', (string) $this->client->getResponse()->getContent());
    }

    /** And the formatting the escaping is protecting is actually there. */
    public function testTheFormattingIsRendered(): void
    {
        $body = $this->show($this->save())->filter('.markdown-body');

        self::assertSame('Safety first', $body->filter('h2')->text(), 'a heading');
        self::assertSame('gloves', $body->filter('strong')->text(), 'emphasis');
        self::assertCount(2, $body->filter('li'), 'and a list');
    }

    /**
     * A `textarea` is what it was: plain text, printed as typed.
     *
     * This is the acceptance criterion about not disturbing anybody, and it is
     * asserted on a record where the formatted field is *empty* so that a
     * `<strong>` anywhere in the record's own fields is a failure rather than
     * something that has to be attributed to one field or the other.
     *
     * It is also most of the argument for this being its own type rather than an
     * option on `textarea` (§5.21). Nothing had to be done to make this pass:
     * there is no code path on which an existing field's type changes, because
     * the type is what the definition says and nothing edited it.
     */
    public function testAnExistingTextareaFieldIsUnaffected(): void
    {
        $fields = $this->show($this->save([self::NOTE => self::SOURCE]))->filter('dl');

        self::assertStringContainsString('**gloves**', $fields->text(), 'the marks are printed, as typed');
        self::assertStringNotContainsString('<strong>', $fields->html(), 'and nothing was rendered');
        self::assertStringNotContainsString('<script>', $fields->html(), 'still escaped, as every value here always was');
    }

    /**
     * And the type itself is untouched, which is the half a rendered page cannot
     * show.
     *
     * `textarea` does not implement the capability interface, so nothing in the
     * engine will ever ask it for Markdown source, and its widget is the same
     * `TextareaType` it always was. If either of these changed, every existing
     * field in every tenant would change meaning at once — which is precisely
     * what an option on `textarea` would have made possible and a separate type
     * cannot.
     */
    public function testTheTextareaTypeItselfIsUntouched(): void
    {
        $textarea = self::service(FieldTypeRegistry::class)->get('textarea');

        self::assertNotInstanceOf(HoldsFormattedText::class, $textarea);
        self::assertSame(TextareaType::class, $textarea->formType());
    }

    /**
     * A list cell shows `bold`, not `**bold**` (§5.21).
     *
     * A column has one line and no room for a heading, so the formatting cannot
     * be drawn there; given that, printing the punctuation that would have
     * produced it is noise rather than fidelity. What the cell gets is what the
     * parser says the words are.
     */
    public function testAListColumnShowsTheWordsWithoutTheMarks(): void
    {
        $this->save();

        $table = $this->client->request('GET', $this->url('/m/contact'))->filter('table');

        self::assertStringContainsString('Safety first Wear gloves before', $table->text());
        self::assertStringNotContainsString('**gloves**', $table->text(), 'the marks are gone');
        self::assertStringNotContainsString('##', $table->text(), 'and so is the heading marker');
        self::assertCount(0, $table->filter('script'), 'and a cell is still text, not markup');
    }

    /**
     * A document gets the same words, for the same reason twice over: a .docx is
     * not HTML, and `**Warning:**` printed on a customer's invoice is punctuation
     * nobody meant to send (§5.21, §5.7).
     *
     * Asserted through `DocumentMarkers` rather than by generating a file,
     * because what is being decided here is the *value* a marker is worth. The
     * .docx machinery around it is XIV-4's and has its own tests.
     */
    public function testADocumentMarkerGetsTheWordsWithoutTheMarks(): void
    {
        $id = $this->save();

        $value = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id): string {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);
            $record = self::service(RecordRepository::class)->find($module, $id);
            \assert($record instanceof Record);

            return self::service(DocumentMarkers::class)->dataFor($module, $record)[self::PROCEDURE] ?? '';
        });

        self::assertStringStartsWith('Safety first Wear gloves before', $value);
        self::assertStringNotContainsString('**', $value);
    }

    /**
     * What is *stored* is the source, untouched — which is what the export
     * writes, and therefore what an import can read back (§5.6).
     *
     * Read out of the column rather than off a hydrated record, for the reason
     * the date test gives one file over: asking the record would prove the round
     * trip through the field type works, not what the database holds. The
     * exporter works in storage form, so this column is the export.
     */
    public function testTheStoredValueIsTheSourceAsTyped(): void
    {
        $this->save();

        $stored = self::service(TenantSwitcher::class)->runFor($this->tenant, function (): string {
            $table = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getTableName();
            $connection = self::getContainer()->get('doctrine.dbal.tenant_connection');
            \assert($connection instanceof Connection);

            return (string) $connection->fetchOne(sprintf(
                "SELECT data->>'%s' FROM %s WHERE deleted_at IS NULL LIMIT 1",
                self::PROCEDURE,
                $table,
            ));
        });

        self::assertSame(self::SOURCE, $stored);
    }

    /**
     * A filter matches the source, and that is a decision rather than a
     * consequence (§5.21).
     *
     * Searching for `**gloves**` finds the record, because the string in the
     * payload is the string somebody typed. The alternative — matching the
     * rendered words — would mean rendering every row on every query or keeping
     * a second derived copy of every value to search against, and neither buys
     * anything worth having.
     */
    public function testAFilterMatchesTheSource(): void
    {
        $this->save();

        $table = $this->client->request('GET', $this->url(sprintf(
            '/m/contact?filter[0][path]=%s&filter[0][op]=contains&filter[0][value]=%s',
            self::PROCEDURE,
            rawurlencode('**gloves**'),
        )))->filter('table');

        self::assertStringContainsString('Ada', $table->text(), 'found by the marks it was typed with');
    }

    /**
     * The editor is a textarea and a preview, and the preview is server-rendered
     * (XIV-131).
     *
     * **No JavaScript is written for this and nothing is fetched.** The record
     * form is already a Live Component re-rendering as somebody types (XIV-32),
     * so a preview block inside the widget follows the typing for free; here it
     * is asserted on an edit form, which is the same rendering with the saved
     * value in it. `FrontEndAssetsTest` is what holds the wider promise that a
     * customer's browser calls no CDN, and this feature adds nothing for it to
     * check because it adds no imports at all.
     */
    public function testTheEditorDrawsAPreviewOfWhatWasTyped(): void
    {
        $form = $this->client->request('GET', $this->url('/m/contact/' . $this->save() . '/edit'));

        self::assertSelectorExists('textarea#module_record_fields_' . self::PROCEDURE);
        self::assertSame('gloves', $form->filter('.markdown-preview .markdown-body strong')->text());
        // Somewhere among the form's help texts rather than in the first one.
        // This used to read `.form-text` and take what the crawler gave it,
        // which was this hint only for as long as nothing else on a contact form
        // had one — and since XIV-114 the phone field does.
        self::assertContains(
            true,
            $form->filter('.form-text')->each(
                static fn (Crawler $hint): bool => str_contains($hint->text(), 'Formatting:'),
            ),
            'the markdown hint is drawn beside the textarea',
        );
        self::assertCount(0, $form->filter('.markdown-preview script'), 'and the preview escapes too');
    }

    /**
     * A record with the formatted field filled in, unless a test says otherwise.
     *
     * @param array<string, string> $fields
     */
    private function save(array $fields = [self::PROCEDURE => self::SOURCE]): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['first_name' => 'Ada', 'last_name' => 'Lovelace', ...$fields],
            variant: 'person',
        ));
    }

    private function show(int $id): Crawler
    {
        $page = $this->client->request('GET', $this->url('/m/contact/' . $id));

        self::assertResponseIsSuccessful();

        return $page;
    }

    private function addField(string $key, string $label, string $type): FieldDefinition
    {
        return self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): FieldDefinition => self::service(MetadataEditor::class)->addField(
                shape: self::service(MetadataRepository::class)->get(ContactModule::KEY),
                key: $key,
                label: $label,
                type: $type,
                filterable: true,
                listed: true,
            ),
        );
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', sprintf('https://%s/login', self::HOST));
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

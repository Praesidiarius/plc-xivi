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
use App\Tenant\Entity\PermissionGrant;
use App\Tenant\Entity\User;
use App\Tenant\Repository\UserRepository;
use App\Tenant\Security\UserCreator;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
use Xivi\Core\Metadata\MetadataEditor;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\PermissionScope;

/**
 * Documents from .docx templates (XIV-4).
 *
 * The templates are real .docx files — built here rather than committed as
 * fixtures, because a zip in the repository is a thing nobody can read a diff of
 * and this way the test says what a template *is*: a zip with the parts Word
 * insists on and a paragraph with markers in it.
 *
 * The PDF half needs the converter service, so it skips rather than fails when
 * there is none: everything up to the conversion is proven either way.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DocumentTemplateTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_documents';
    private const string HOST = 'documents.localhost';
    private const string ADMIN = 'admin@documents.test';
    /** Whose session a record is saved under unless a test says otherwise (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string WRITER = 'writer@documents.test';
    private const string SENDER = 'sender@documents.test';
    private const string PASSWORD = 'documents-password';

    private KernelBrowser $client;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn () => self::service(ModuleInstaller::class)->install(
                self::service(ModuleRegistry::class)->get(ContactModule::KEY),
            ),
        );

        $users = self::service(UserCreator::class);
        $users->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);
        // The two halves of the ticket's permission split, one person each.
        $users->create($this->tenant, self::WRITER, 'Writer', self::PASSWORD, []);
        $users->create($this->tenant, self::SENDER, 'Sender', self::PASSWORD, []);

        $this->signIn(self::ADMIN);
    }

    /**
     * The reason the page exists: somebody writing a template in Word has to know
     * what to type, and the answer is this customer's own definitions.
     */
    public function testThePageListsAPlaceholderForEveryFieldOfEveryVariant(): void
    {
        $page = $this->client->request('GET', $this->url('/m/contact/templates'))->filter('main')->text();

        foreach (['[first_name]', '[last_name]', '[email]', '[company_name]'] as $marker) {
            self::assertStringContainsString($marker, $page);
        }

        // Not only the fields: every template ends up wanting the record's number.
        self::assertStringContainsString('[record_id]', $page);
    }

    /**
     * A date is not a property of a person or of a company.
     *
     * The general markers are about the moment rather than the record, so they
     * get their own section instead of being repeated under every variant, where
     * they read as something the contact has.
     */
    public function testMarkersThatAreNotAboutTheRecordHaveTheirOwnSection(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/templates'));

        $general = $crawler->filter('#general-markers')->text();
        $everything = $crawler->filter('main')->text();
        $perVariant = str_replace($general, '', $everything);

        foreach (['[today]', '[tenant.name]', '[user.name]', '[user.email]'] as $marker) {
            self::assertStringContainsString($marker, $general);
            self::assertStringNotContainsString($marker, $perVariant, $marker . ' is not a field of anything');
        }

        // And the record's own markers stay where they belong.
        self::assertStringNotContainsString('[first_name]', $general);
        self::assertStringNotContainsString('[record_id]', $general);
    }

    /** The general markers are filled in like any other. */
    public function testAGeneralMarkerIsSubstitutedToo(): void
    {
        $template = $this->upload('Letter', 'From [tenant.name], written by [user.name] on [today].');
        $id = $this->aContact();

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));

        $text = $this->textOf((string) $this->client->getResponse()->getContent());

        self::assertStringContainsString('From ' . $this->tenant->getName(), $text);
        self::assertStringContainsString('written by Admin', $text);
        self::assertStringContainsString('on ' . date('Y-m-d') . '.', $text);
    }

    /**
     * One button beside the record, and the templates behind it.
     *
     * Not a card listing them: a contact with fifty templates would have been a
     * column of a hundred buttons (XIV-4).
     */
    public function testAnUploadedTemplateIsOfferedOnTheRecord(): void
    {
        $this->upload('Letter', 'Dear [first_name] [last_name].');
        $id = $this->aContact();

        $crawler = $this->client->request('GET', $this->url('/m/contact/' . $id));
        $button = $crawler->filter(sprintf('a[href*="/m/contact/%d/document"]', $id));

        self::assertCount(1, $button, 'one button, whatever the number of templates');
        self::assertSame('modal', $button->attr('data-bs-toggle'), 'which opens the chooser');
        self::assertStringContainsString('Letter', $crawler->filter('#document-modal option')->text());
    }

    /**
     * And the same choice as a page, for whoever has no JavaScript.
     *
     * The button's href goes here; Bootstrap only intercepts the click when it is
     * there to do it, which is why this is a page rather than a fragment.
     */
    public function testTheChooserIsAlsoAPageOfItsOwn(): void
    {
        $this->upload('Letter', 'Dear [first_name] [last_name].');
        $id = $this->aContact();

        $crawler = $this->client->request('GET', $this->url('/m/contact/' . $id . '/document'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Letter', $crawler->filter('select[name="template"] option')->text());
        self::assertCount(2, $crawler->filter('select[name="format"] option'), 'PDF and Word');

        // And the form leads where the modal's does, with the format it was asked for.
        $this->client->submit($crawler->selectButton('Download')->form(['format' => 'docx']));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Dear Ada Lovelace.', $this->textOf((string) $this->client->getResponse()->getContent()));
    }

    /** A letter to a person is not a letter to a company (§5.5). */
    public function testATemplateForOneVariantIsNotOfferedOnAnother(): void
    {
        $this->upload('Company letter', 'Dear [company_name].', ContactModule::COMPANY);
        $id = $this->aContact();

        $page = $this->client->request('GET', $this->url('/m/contact/' . $id))->filter('main')->text();

        self::assertStringNotContainsString('Company letter', $page);
    }

    /** Asked for anyway, by typing the URL: still not a document that means anything. */
    public function testATemplateForAnotherVariantIsRefusedEvenWhenAskedForDirectly(): void
    {
        $template = $this->upload('Company letter', 'Dear [company_name].', ContactModule::COMPANY);
        $id = $this->aContact();

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** The whole point, end to end: a record's values, inside a Word document. */
    public function testGeneratingADocxFillsInTheRecordsValues(): void
    {
        $template = $this->upload('Letter', 'Dear [first_name] [last_name], your number is [record_id].');
        $id = $this->aContact();

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'wordprocessingml',
            (string) $this->client->getResponse()->headers->get('content-type'),
        );

        $text = $this->textOf((string) $this->client->getResponse()->getContent());

        self::assertStringContainsString('Dear Ada Lovelace', $text);
        self::assertStringContainsString('your number is ' . $id, $text);
        self::assertStringNotContainsString('[first_name]', $text, 'every marker is replaced');
    }

    /** Rendered through the field type, so a date reads as a date rather than as storage. */
    public function testValuesAreWrittenTheWayTheFieldTypeShowsThem(): void
    {
        $template = $this->upload('Birthday note', 'Born on [birthday].');
        $id = $this->aContact(['birthday' => '1815-12-10']);

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));

        // A document is written the way the person generating it reads (§5.7,
        // XIV-50) — the same rule as every other value, now that a date has a
        // reader's form at all. Whether a document should instead follow its
        // *recipient* is a real question and a different one; nothing about it is
        // answered here.
        self::assertStringContainsString('Born on 12/10/1815', $this->textOf((string) $this->client->getResponse()->getContent()));
    }

    /**
     * The conversion itself, against the real service.
     *
     * Skipped rather than faked when it is not running: a fake would prove that
     * the seam is called, which the docx test already does, and would say nothing
     * at all about whether LibreOffice can read what we produce.
     */
    public function testGeneratingAPdfProducesAPdf(): void
    {
        $template = $this->upload('Letter', 'Dear [first_name].');
        $id = $this->aContact();

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=pdf', $id, $template)));

        if ($this->client->getResponse()->isRedirection()) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('content-type'));
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Word's own placeholder text survives into the document, and therefore into
     * the PDF.
     *
     * A letterhead is built from content controls — the "Sender's name",
     * "address street" boxes somebody clicks into. Until one is typed in it
     * carries `showingPlcHdr`, which Word displays and prints and **LibreOffice
     * renders as nothing** — so the same document came out complete as a .docx
     * and with its whole sender block missing as a PDF. The flag is dropped on
     * the way out; the text was always there.
     */
    public function testWordsOwnPlaceholderTextSurvivesIntoTheDocument(): void
    {
        $template = $this->upload('Letterhead', 'Dear [first_name].', null, "Sender's name");
        $id = $this->aContact();

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));

        $docx = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString("Sender's name", $this->textOf($docx), 'the control keeps its words');
        self::assertStringNotContainsString('showingPlcHdr', $this->xmlOf($docx), 'and stops being ignorable');
        self::assertStringContainsString('Dear Ada.', $this->textOf($docx), 'the markers still work');
    }

    /**
     * A letter that went out is a fact about the record's life (§5.2).
     *
     * The first history entry that records something other than a change, and it
     * says which template and which format — a timeline that only said "document
     * generated" would answer the least interesting half of the question.
     */
    public function testGeneratingADocumentIsRecordedOnTheTimeline(): void
    {
        $template = $this->upload('Letter', 'Dear [first_name].');
        $id = $this->aContact();

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));

        $timeline = $this->client->request('GET', $this->url('/m/contact/' . $id . '/history'))->filter('main')->text();

        self::assertStringContainsString('Document generated', $timeline);
        self::assertStringContainsString('by Admin', $timeline);
        self::assertStringContainsString('Letter', $timeline);
        self::assertStringContainsString('DOCX', $timeline);
    }

    /** Two downloads are two entries, and each says what it was. */
    public function testEachFormatIsItsOwnEntry(): void
    {
        $template = $this->upload('Letter', 'Dear [first_name].');
        $id = $this->aContact();

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));
        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));

        $entries = $this->client->request('GET', $this->url('/m/contact/' . $id . '/history'))
            ->filter('.history-entry')
            ->each(static fn ($node): string => $node->text());

        // Two generations and the record being created.
        self::assertCount(3, $entries);
    }

    /**
     * The bug this ticket came from, to the character (XIV-25).
     *
     * An order template printed `[contacŧ]` into a finished document instead of
     * the customer's name. The last character is U+0167 — `t` with a stroke,
     * which is AltGr and the key beside `t` on a Swiss layout — and at body-text
     * size it is indistinguishable from the letter it is not. Nothing is called
     * that, so the generator correctly left the words alone, and the only place
     * anybody could have found out was the finished PDF.
     */
    public function testAPlaceholderDifferingByOneCharacterIsNamed(): void
    {
        $this->upload('Order letter', 'Dear [contacŧ], your order is ready.');

        $page = $this->client->getCrawler()->filter('main')->text();

        self::assertStringContainsString('[contacŧ]', $page, 'named, not merely counted');
        self::assertStringContainsString('printed just as it is', $page);
    }

    /**
     * It reports and it does not refuse.
     *
     * Square brackets in a letter are legal prose, a customer may be half-way
     * through writing a template, and an unknown token may well be one somebody
     * meant. Refusing the upload would trade a silent wrongness for a loud one.
     */
    public function testATemplateWithAnUnknownPlaceholderIsStillAccepted(): void
    {
        $template = $this->upload('Half written', 'Dear [contacŧ].');

        // Read before anything else is done, because the upload's own redirect
        // is where somebody finds out.
        $page = $this->client->getCrawler()->filter('main')->text();
        $id = $this->aContact();

        self::assertStringContainsString('Half written', $page, 'the template is kept');
        self::assertStringContainsString('printed just as it is', $page, 'and told about, not rejected');

        // And it generates, brackets and all — which is the behaviour that was
        // right all along and is deliberately unchanged.
        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Dear [contacŧ].', $this->textOf((string) $this->client->getResponse()->getContent()));
    }

    /**
     * The case a naive implementation gets wrong, and the reason this reuses the
     * generator's own scan rather than a second one.
     *
     * Word cuts a placeholder somebody typed in one go across several runs —
     * `[first_na` in one and `me]` in the next — at every spell-check boundary
     * it feels like. A checker that searched the XML for `[first_name]` would
     * find nothing and report a perfectly good template as broken, which is a
     * worse failure than the silence it was built to fix: it is wrong about
     * exactly the templates a human typed by hand.
     */
    public function testAMarkerWordHasSplitAcrossRunsIsRecognised(): void
    {
        $template = $this->upload('Split letter', ['Dear [first_na', 'me] [last_na', 'me].']);

        $page = $this->client->getCrawler()->filter('main')->text();
        $id = $this->aContact();

        // Nothing at all, which is the assertion: the reference panel beside the
        // form prints `[first_name]` on every render, so the thing to look for
        // is the sentence the report would have added and not the token.
        self::assertStringNotContainsString('printed just as', $page, 'a correct template reports nothing');

        // And the split is a real one: this is the same document the generator
        // fills, so a test that only asserted silence could be passing because
        // nothing was scanned at all.
        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));

        self::assertStringContainsString('Dear Ada Lovelace.', $this->textOf((string) $this->client->getResponse()->getContent()));
    }

    /**
     * Everything the reference list beside the form offers counts as known.
     *
     * All three sections of it, because the vocabulary the report checks against
     * and the vocabulary the page prints are the same list — a checker with its
     * own idea of what a marker is starts crying wolf the first time somebody
     * adds one.
     *
     * `[company_name]` is a company's field on a template naming no kind of
     * record, and is deliberately not reported: it is a real marker of this
     * module, and the reason it comes out blank on a person is the record rather
     * than the template.
     */
    public function testTheModulesGeneralAndCollectionMarkersAllCountAsKnown(): void
    {
        $this->upload(
            'Everything',
            'From [tenant.name] on [today], written by [user.name]: '
            . '[first_name] [company_name] #[record_id], [addresses.street] [addresses.city].',
        );

        $page = $this->client->getCrawler()->filter('main')->text();

        self::assertStringNotContainsString('printed just as', $page);
    }

    /**
     * The other half of the ticket: a template nobody has touched can go wrong
     * on its own.
     *
     * A field renamed or removed months after the letter was written leaves the
     * template naming something that no longer exists, and the moment of upload
     * — the one moment a check on upload alone would catch — is long past. So
     * the check runs against what is stored, every time this page is drawn.
     */
    public function testATemplateAlreadyUploadedIsCheckedAgainWhenAFieldGoesAway(): void
    {
        $this->addField('vat_number', 'VAT number');
        $this->upload('Invoice letter', 'VAT [vat_number].');

        // Nothing to say while the field is there…
        self::assertStringNotContainsString(
            'printed just as',
            $this->client->request('GET', $this->url('/m/contact/templates'))->filter('main')->text(),
        );

        $this->removeField('vat_number');

        // …and the same template, unchanged, now says what it will print.
        $page = $this->client->request('GET', $this->url('/m/contact/templates'))->filter('main')->text();

        self::assertStringContainsString('[vat_number]', $page);
        self::assertStringContainsString('printed just as it is', $page);
    }

    /** A .docx is a zip with a document in it, and the extension proves nothing. */
    public function testSomethingThatIsNotADocxIsRefused(): void
    {
        $path = sys_get_temp_dir() . '/xivi-test-not-a-docx.docx';
        file_put_contents($path, 'This is a text file wearing a hat.');

        $crawler = $this->client->request('GET', $this->url('/m/contact/templates'));
        $form = $crawler->selectButton('Upload a template')->form(['name' => 'Nonsense']);
        self::fileField($form)->upload($path);
        $this->client->submit($form);

        $page = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('not a Word document', $page);
        self::assertStringNotContainsString('Nonsense', $page);

        @unlink($path);
    }

    public function testATemplateCanBeRemoved(): void
    {
        $this->upload('Letter', 'Dear [first_name].');

        $crawler = $this->client->request('GET', $this->url('/m/contact/templates'));
        $this->client->submit($crawler->selectButton('Delete')->form());

        $page = $this->client->followRedirect()->filter('main')->text();

        self::assertStringContainsString('No templates yet', $page);
    }

    /**
     * The ticket asked for these to be two permissions, and this is what that
     * buys: the person who keeps the stationery and the person who sends letters
     * are different people, and neither becomes the other.
     */
    public function testKeepingTemplatesAndGeneratingDocumentsAreSeparateGrants(): void
    {
        $template = $this->upload('Letter', 'Dear [first_name].');
        $id = $this->aContact();

        $this->grant(self::WRITER, ModuleAction::Templates);
        $this->grant(self::SENDER, ModuleAction::Document);
        $this->grant(self::SENDER, ModuleAction::View);

        $this->signIn(self::WRITER);
        $this->client->request('GET', $this->url('/m/contact/templates'));
        self::assertResponseIsSuccessful('whoever keeps the templates may open them');

        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'and may not send one');

        $this->signIn(self::SENDER);
        $this->client->request('GET', $this->url(sprintf('/m/contact/%d/document/download?template=%d&format=docx', $id, $template)));
        self::assertResponseIsSuccessful('whoever sends letters may generate one');

        $this->client->request('GET', $this->url('/m/contact/templates'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'and may not change what they say');
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Uploads a template through the browser and returns its id.
     *
     * @param string|list<string> $body a list is one paragraph whose text Word
     *                                  has split across runs, which is what
     *                                  happens to anything typed by hand
     */
    private function upload(string $name, string|array $body, ?string $variant = null, ?string $placeholderControl = null): int
    {
        $path = self::aDocx($body, $placeholderControl);

        $crawler = $this->client->request('GET', $this->url('/m/contact/templates'));
        $values = ['name' => $name];

        if ($variant !== null) {
            $values['variant'] = $variant;
        }

        $form = $crawler->selectButton('Upload a template')->form($values);
        self::fileField($form)->upload($path);
        $this->client->submit($form);
        $this->client->followRedirect();

        @unlink($path);

        $ids = $this->client->getCrawler()
            ->filter('form[action*="/templates/"]')
            ->each(static fn ($node): string => (string) $node->attr('action'));

        self::assertNotEmpty($ids, 'the template was accepted');

        return (int) preg_replace('#\D#', '', str_replace('/delete', '', (string) end($ids)));
    }

    /**
     * The upload field, typed.
     *
     * Form::offsetGet is documented as returning a field or a list of them, so
     * static analysis cannot know which this is; the form has one file input.
     */
    private static function fileField(Form $form): FileFormField
    {
        $field = $form['template'];
        \assert($field instanceof FileFormField);

        return $field;
    }

    /**
     * A minimal but real .docx.
     *
     * Three parts is all Word needs to open a file: the content types, the
     * relationship naming the main document, and the document itself.
     *
     * @param string|list<string> $text one run, or the several Word cuts a
     *                                  hand-typed line into
     */
    private static function aDocx(string|array $text, ?string $placeholderControl = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-test-template-') . '.docx';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');
        // A Word content control that is still showing its placeholder text —
        // the "click here to type" boxes a letterhead is built from.
        $control = $placeholderControl === null ? '' : '<w:p><w:sdt><w:sdtPr><w:temporary/><w:showingPlcHdr/><w:text/></w:sdtPr>'
            . '<w:sdtContent><w:r><w:t>' . htmlspecialchars($placeholderControl, \ENT_XML1) . '</w:t></w:r></w:sdtContent>'
            . '</w:sdt></w:p>';

        // One `<w:r>` per run. A caller that passes several is reproducing what
        // Word does to a placeholder somebody typed in one go, which is the
        // whole reason the scanning has to be tolerant of markup (XIV-25).
        $runs = '';

        foreach (\is_array($text) ? $text : [$text] as $run) {
            $runs .= '<w:r><w:t xml:space="preserve">' . htmlspecialchars($run, \ENT_XML1) . '</w:t></w:r>';
        }

        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $control
            . '<w:p>' . $runs . '</w:p>'
            . '</w:body></w:document>');
        $zip->close();

        return $path;
    }

    /** The words inside a .docx, with the XML taken off. */
    private function textOf(string $docx): string
    {
        return strip_tags($this->xmlOf($docx));
    }

    /** The main document part of a .docx, as it was written. */
    private function xmlOf(string $docx): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-test-read-') . '.docx';
        file_put_contents($path, $docx);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true, 'the download is a readable zip');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        @unlink($path);

        return $xml;
    }

    /** @param array<string, string> $values */
    private function aContact(array $values = []): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace', ...$values],
            variant: 'person',
        ));
    }

    /**
     * A field this customer added, which is the only kind that can go away
     * again: a module's own field is refused removal on purpose (§7.2), so
     * the staleness a template suffers from is always a customer's own.
     */
    private function addField(string $key, string $label): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, static fn () => self::service(MetadataEditor::class)->addField(
            shape: self::service(MetadataRepository::class)->get(ContactModule::KEY),
            key: $key,
            label: $label,
            type: 'text',
        ));
    }

    private function removeField(string $key): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, static function () use ($key): void {
            $field = self::service(MetadataRepository::class)->get(ContactModule::KEY)->getField($key);
            \assert($field !== null);

            self::service(MetadataEditor::class)->removeField($field);
        });
    }

    private function grant(string $email, ModuleAction $action): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($email, $action): void {
            $manager = self::getContainer()->get('doctrine')->getManager('tenant');
            \assert($manager instanceof EntityManagerInterface);

            $user = self::service(UserRepository::class)->findOneByEmail($email);
            self::assertInstanceOf(User::class, $user);

            $manager->persist(PermissionGrant::forUser($user, ContactModule::KEY, $action, PermissionScope::All));
            $manager->flush();
        });
    }

    private function signIn(string $email): void
    {
        $this->client->getCookieJar()->clear();

        $crawler = $this->client->request('GET', $this->url('/login'));
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

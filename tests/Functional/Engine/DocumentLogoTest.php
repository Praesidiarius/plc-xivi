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
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Xivi\Contact\ContactModule;
use Xivi\Core\Document\DocumentFormat;
use Xivi\Core\Document\DocumentGenerator;
use Xivi\Core\Document\DocumentTemplateRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\RecordRepository;

/**
 * `[tenant.logo]` on a .docx, which is a change to the pipeline rather than a
 * key in a list (XIV-89, §5.7).
 *
 * **What is actually being proved here is not "the marker works".** Every other
 * marker resolves to text and is substituted by `anourvalar/office`; this one
 * replaces the marker's run with a `<w:drawing>`, which means a media part, a
 * relationship, a content type and an extent — four things in the package that
 * have to agree, in a file the customer wrote. So the assertions are about the
 * package rather than about the words: which parts exist, what the relationship
 * says, and whether the bytes that came out are the bytes that went in.
 *
 * Three of them are the cases that would have shipped broken:
 *
 * - **the header**, because a letterhead puts its mark there and a header keeps
 *   its own relationships, so the whole apparatus is per-part rather than
 *   per-document;
 * - **the split marker**, because Word cuts a placeholder somebody typed in one
 *   go across several runs, which is the exact case §5.7 chose this library to
 *   survive and would otherwise be the case that silently does nothing;
 * - **the `rId`**, because a collision does not crash. The package still opens
 *   and one relationship answers for two uses, so a customer's own header image
 *   comes out as the logo, or the logo comes out as their font.
 *
 * And one that is about the PDF rather than the .docx: Gotenberg is what turns
 * this into what the recipient sees, and Word and LibreOffice agreeing that a
 * file is valid is famously not the same as their agreeing what to draw — which
 * this feature has already been bitten by once (XIV-4's `showingPlcHdr`). A
 * .docx that opens and a PDF with a blank where the mark should be is a failure,
 * so the PDF is inspected for an image and not merely for a `%PDF-`.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DocumentLogoTest extends WebTestCase
{
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_document_logo';
    private const string HOST = 'document-logo.localhost';
    private const string ADMIN = 'admin@document-logo.test';
    /** Whose session a record is saved under (XIV-33). */
    private const string EMAIL = self::ADMIN;
    private const string PASSWORD = 'document-logo-password';

    /** The relationship type a picture is reached through. */
    private const string IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    private KernelBrowser $client;
    private Tenant $tenant;

    /** @var list<string> temporary uploads to clear up after each test */
    private array $files = [];

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

        self::service(UserCreator::class)->create($this->tenant, self::ADMIN, 'Admin', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn(self::ADMIN);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];

        parent::tearDown();
    }

    /**
     * The ticket, in one test: a template places the marker and the document
     * carries the picture.
     *
     * The bytes are compared for equality rather than searched for, which is the
     * same call `TenantLogoTest` makes about the serving route and for a related
     * reason: nothing is re-encoded on the way through (§8.6), so anything other
     * than byte equality means something in the pipeline decided to be helpful.
     */
    public function testATemplateCanPlaceTheMarkAndTheDocumentCarriesTheImage(): void
    {
        $this->uploadLogo();
        $docx = $this->generate(['Yours, [tenant.logo]']);

        $document = $this->partOf($docx, 'word/document.xml');

        self::assertStringContainsString('<w:drawing>', $document, 'the marker became an element');
        self::assertStringNotContainsString('[tenant.logo]', self::textOf($document), 'and not text');

        $media = $this->mediaIn($docx);
        self::assertCount(1, $media, 'exactly one picture was added');
        self::assertSame(self::png(), $this->partOf($docx, $media[0]), 'byte for byte what was uploaded');

        // The three parts of the package that have to agree with each other, and
        // any one of which missing produces a file Word calls corrupt.
        self::assertStringContainsString(self::IMAGE, $this->partOf($docx, 'word/_rels/document.xml.rels'));
        self::assertMatchesRegularExpression(
            '#<Default[^>]+Extension="png"#i',
            $this->partOf($docx, '[Content_Types].xml'),
            'a png in the package is declared',
        );
        self::assertMatchesRegularExpression('#<wp:extent cx="\d+" cy="\d+"/>#', $document, 'and it has a size');
    }

    /**
     * The extent is the size §5.7 says it is, on a real document.
     *
     * The arithmetic itself is pinned by `LogoExtentTest`; what this adds is that
     * the number computed there is the number that reaches the file, which is the
     * step where a unit test cannot help.
     */
    public function testTheMarkIsDrawnAtTheSizeTheBriefPromises(): void
    {
        $this->uploadLogo();
        $document = $this->partOf($this->generate(['[tenant.logo]']), 'word/document.xml');

        self::assertMatchesRegularExpression('#<wp:extent cx="1440000" cy="480000"/>#', $document);
    }

    /**
     * Word cuts a placeholder somebody typed in one go across several runs, which
     * is the case the whole substitution design exists for (§5.7).
     *
     * The words around the marker have to survive it too. The span being replaced
     * reaches from inside one run's text into the next one's, so a version of this
     * that closed the wrong element would take the rest of the sentence with it.
     */
    public function testAMarkSplitAcrossRunsIsStillOnePicture(): void
    {
        $this->uploadLogo();
        $docx = $this->generate(['Regards from [tenant.', 'lo', 'go] and nobody else']);

        $document = $this->partOf($docx, 'word/document.xml');
        $text = self::textOf($document);

        self::assertSame(1, substr_count($document, '<w:drawing>'), 'one picture, not none and not three');
        self::assertStringContainsString('Regards from ', $text, 'the words before it are still there');
        self::assertStringContainsString(' and nobody else', $text, 'and the words after it');
        self::assertStringNotContainsString('tenant.', $text, 'and the marker is gone entirely');
    }

    /**
     * Where a letterhead actually puts it.
     *
     * A header is a part of its own with relationships of its own, so this is not
     * the same code path finding a different string — it is the second time
     * everything happens. The document part is checked for *not* having gained a
     * picture, because a version of this that wrote every part's drawing into
     * `document.xml` would still pass a test that only looked at the header.
     */
    public function testAMarkInTheLetterheadsHeaderIsDrawnInTheHeader(): void
    {
        $this->uploadLogo();
        $docx = $this->generate(['Dear [first_name].'], header: 'Acme AG [tenant.logo]');

        self::assertStringContainsString('<w:drawing>', $this->partOf($docx, 'word/header1.xml'));
        self::assertStringNotContainsString('<w:drawing>', $this->partOf($docx, 'word/document.xml'));

        // The header's own rels part, which the template did not have at all —
        // a header with no hyperlinks and no pictures has none, and that is the
        // ordinary state of the header a mark is about to go into.
        self::assertStringContainsString(self::IMAGE, $this->partOf($docx, 'word/_rels/header1.xml.rels'));
        self::assertStringNotContainsString(self::IMAGE, $this->partOf($docx, 'word/_rels/document.xml.rels'));
    }

    /**
     * The `rId` cannot be one the customer's template already uses.
     *
     * The template here carries six relationships of its own, including a gap in
     * the numbering and an id that is not a number at all — which is legal, since
     * a relationship id is an xsd:ID and nothing requires `rId` plus a digit. A
     * naive "one past the highest number" would land on `rId9` here; a naive
     * counter would land on `rId1`.
     *
     * Nothing is asserted about *which* id is chosen, deliberately. What matters
     * is that it is new and that every relationship the customer wrote is still
     * theirs.
     */
    public function testTheRelationshipIdCannotCollideWithOneTheTemplateAlreadyUses(): void
    {
        $taken = ['rId1', 'rId2', 'rId3', 'rId8', 'rIdLogo', 'rIdImage1'];

        $this->uploadLogo();
        $docx = $this->generate(['[tenant.logo]'], relationships: $taken);

        $rels = $this->partOf($docx, 'word/_rels/document.xml.rels');

        preg_match_all('#\bId="([^"]+)"#', $rels, $matches);
        $ids = $matches[1];

        self::assertSame(array_unique($ids), $ids, 'no id is used twice');

        foreach ($taken as $id) {
            self::assertContains($id, $ids, $id . ' is still the customer\'s');
        }

        // And the one this feature added is one of the ids that were not there
        // before — which, with the uniqueness above, is the whole claim.
        preg_match('#<Relationship Id="([^"]+)"[^>]*Type="' . preg_quote(self::IMAGE, '#') . '"#', $rels, $ours);
        self::assertArrayHasKey(1, $ours, 'the picture has a relationship');
        self::assertNotContains($ours[1], $taken);
    }

    /**
     * An installation that has never uploaded a logo generates a document with
     * nothing where the mark would be.
     *
     * Not an empty picture and not the literal brackets — the rule §5.7 already
     * applies to every unfilled marker, and this one is not allowed to be an
     * exception to it. It costs no code at all: the image pass finds nothing to
     * do and the ordinary blanking finishes the job, which is why the ordering in
     * `DocumentGenerator` is written down there.
     */
    public function testAnInstallationWithNoLogoGeneratesADocumentWithNothingThere(): void
    {
        $docx = $this->generate(['Yours, [tenant.logo] — Acme']);

        $document = $this->partOf($docx, 'word/document.xml');

        self::assertStringNotContainsString('[tenant.logo]', self::textOf($document), 'no brackets');
        self::assertStringNotContainsString('<w:drawing>', $document, 'and no empty picture');
        self::assertSame([], $this->mediaIn($docx), 'and nothing in the package to be broken');
        self::assertStringContainsString('Yours, ', self::textOf($document));
        self::assertStringContainsString(' — Acme', self::textOf($document));
    }

    /**
     * The review does not call the marker unfillable (XIV-25).
     *
     * It would have, left alone: the report is a comparison between the tokens in
     * the file and `DocumentMarkers::keysFor()`, so a marker that is not in the
     * vocabulary is one the upload page tells the customer will be printed as it
     * stands — while the generator quietly draws a picture instead. The two
     * halves disagreeing is exactly the failure XIV-25's own docblock warns
     * about.
     */
    public function testTheReviewDoesNotReportTheMarkerAsUnfillable(): void
    {
        $this->uploadTemplate(['Yours, [tenant.logo]']);

        $page = $this->client->request('GET', $this->url('/m/contact/templates'))->filter('main')->text();

        self::assertStringNotContainsString('will be printed just as it is', $page);
    }

    /**
     * The reference list says which marker draws a picture, which the ticket
     * asked to be decided.
     *
     * Decided yes: `[tenant.logo]` sitting beside `[tenant.name]` under one
     * heading, in the same brackets, with a label that reads like the name of a
     * file, is a token that gets pasted into a sentence. One word on the row is
     * the whole of the fix.
     */
    public function testTheReferenceListSaysWhichMarkerDrawsAPicture(): void
    {
        $crawler = $this->client->request('GET', $this->url('/m/contact/templates'));

        self::assertStringContainsString('[tenant.logo]', $crawler->filter('#general-markers')->text());
        self::assertCount(1, $crawler->filter('#general-markers .marker-image'));
        // And exactly one: the badge belongs to the picture, not to the section.
        self::assertCount(1, $crawler->filter('main .marker-image'));
    }

    /**
     * And the email templates page does not offer it at all.
     *
     * An email has no `<w:drawing>` and no answer yet to what a picture in one
     * would even be — a fetched URL or a CID attachment, which is a design
     * question about email rather than a line missing here (§5.13). Until that is
     * answered the marker comes out blank in an email, and advertising something
     * that comes out blank is the thing that page already refuses to do with
     * collection markers.
     */
    public function testTheEmailTemplatesPageDoesNotOfferAMarkerItCannotDraw(): void
    {
        $page = $this->client->request('GET', $this->url('/m/contact/email-templates/new'))->filter('main')->text();

        self::assertStringContainsString('[tenant.name]', $page, 'the text markers are still offered');
        self::assertStringNotContainsString('[tenant.logo]', $page);
    }

    /**
     * The bytes are read from the database, with no HTTP involved.
     *
     * Proved by generating the document with no request in flight at all — the
     * way a console command or a queued job would — rather than by asserting that
     * something was not called. XIV-49 added a public route serving these bytes
     * and reaching for it here would have worked in development and failed
     * wherever the application cannot address itself; a document that only
     * generates inside a web request is a document that stops generating the
     * first time somebody schedules one.
     */
    public function testTheBytesAreReadFromTheDatabaseWithNoRequestInFlight(): void
    {
        $this->uploadLogo();
        $id = $this->uploadTemplate(['[tenant.logo]']);
        $record = $this->aContact();

        // Out of the browser entirely: no client, no session, no route.
        $docx = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($id, $record): string {
            $module = self::service(MetadataRepository::class)->get(ContactModule::KEY);

            $template = self::service(DocumentTemplateRepository::class)->find(ContactModule::KEY, $id);
            self::assertNotNull($template);

            $contact = self::service(RecordRepository::class)->find($module, $record);
            self::assertNotNull($contact);

            return self::service(DocumentGenerator::class)->contents($template, $module, $contact, DocumentFormat::Docx);
        });

        self::assertStringContainsString('<w:drawing>', $this->partOf($docx, 'word/document.xml'));
        self::assertCount(1, $this->mediaIn($docx));
    }

    /**
     * The same document as a PDF, with the picture intact.
     *
     * **`%PDF-` is not the assertion.** LibreOffice will happily produce a
     * perfectly valid PDF with a blank where a drawing it could not read should
     * have been, and this feature has already had that exact failure once — Word
     * and LibreOffice agreed about a file and disagreed about what to draw
     * (XIV-4). So the PDF is searched for an image XObject, which cannot live
     * inside a compressed object stream and is therefore readable in the bytes.
     *
     * The document with no logo is generated too, and asserted *not* to contain
     * one. Without that half this test passes on a converter that puts an image
     * in every PDF it makes, and there would be no way to tell.
     *
     * Skipped rather than failed when the converter is not running, as the
     * sibling PDF test is: a fake would prove the seam is called and say nothing
     * about whether LibreOffice can read what we wrote.
     */
    public function testTheSameDocumentConvertsToPdfWithTheImageIntact(): void
    {
        $withoutLogo = $this->generate(['Yours, [tenant.logo]'], format: 'pdf');

        if ($withoutLogo === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertStringStartsWith('%PDF-', $withoutLogo);
        self::assertDoesNotMatchRegularExpression('#/Subtype\s*/Image#', $withoutLogo, 'nothing to draw yet');

        $this->uploadLogo();
        $withLogo = (string) $this->generate(['Yours, [tenant.logo]'], format: 'pdf');

        self::assertStringStartsWith('%PDF-', $withLogo);
        self::assertMatchesRegularExpression('#/Subtype\s*/Image#', $withLogo, 'the picture survived the conversion');
    }

    /**
     * And the letterhead case survives it too, which is the one that matters.
     *
     * A mark in the body is where a test puts it; a mark in the header is where a
     * customer puts it, and the header is a separate part with separate
     * relationships that LibreOffice reaches by a different route. The .docx
     * assertions above prove the package is right — this proves the converter
     * agrees, which is a different claim and the one XIV-4 learned to make
     * separately.
     */
    public function testAMarkInTheHeaderSurvivesTheConversionToPdf(): void
    {
        $this->uploadLogo();
        $pdf = $this->generate(['Dear [first_name].'], header: 'Acme AG [tenant.logo]', format: 'pdf');

        if ($pdf === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertMatchesRegularExpression('#/Subtype\s*/Image#', $pdf);
    }

    // -- helpers ------------------------------------------------------------

    /**
     * Uploads a template, generates a document from a contact and hands back the
     * bytes.
     *
     * @param list<string> $body          the runs of the body paragraph; more than
     *                                    one is what Word does to a placeholder
     *                                    somebody typed by hand
     * @param list<string> $relationships ids the template's own rels already use
     *
     * @return ($format is 'docx' ? string : string|null) null when the converter
     *                                                    is not running
     */
    private function generate(
        array $body,
        ?string $header = null,
        array $relationships = [],
        string $format = 'docx',
    ): ?string {
        $template = $this->uploadTemplate($body, $header, $relationships);
        $id = $this->aContact();

        $this->client->request('GET', $this->url(sprintf(
            '/m/contact/%d/document/download?template=%d&format=%s',
            $id,
            $template,
            $format,
        )));

        if ($format === 'pdf' && $this->client->getResponse()->isRedirection()) {
            return null;
        }

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * The customer's mark, through the screen they upload it on.
     *
     * Through the form rather than by writing the entity, because the storage is
     * XIV-49's and this ticket is entitled to assume it works — but only from the
     * outside, where a change to how an upload is decoded would show up here too.
     */
    private function uploadLogo(): void
    {
        $this->client->request('GET', $this->url('/settings/profile'));
        $this->client->submitForm('Save', ['logo' => $this->fileOf(self::png(), 'logo.png')]);
    }

    /**
     * @param list<string> $body
     * @param list<string> $relationships
     */
    private function uploadTemplate(array $body, ?string $header = null, array $relationships = []): int
    {
        $path = $this->fileOf(self::aDocx($body, $header, $relationships), 'template.docx');

        $crawler = $this->client->request('GET', $this->url('/m/contact/templates'));
        $form = $crawler->selectButton('Upload a template')->form(['name' => 'Letter']);
        self::fileField($form)->upload($path);

        $this->client->submit($form);
        $this->client->followRedirect();

        $actions = $this->client->getCrawler()
            ->filter('form[action*="/templates/"]')
            ->each(static fn ($node): string => (string) $node->attr('action'));

        self::assertNotEmpty($actions, 'the template was accepted');

        return (int) preg_replace('#\D#', '', str_replace('/delete', '', (string) end($actions)));
    }

    /**
     * The upload field, typed.
     *
     * `Form::offsetGet` is documented as returning a field or a list of them, so
     * static analysis cannot know which this is; the form has one file input.
     */
    private static function fileField(Form $form): FileFormField
    {
        $field = $form['template'];
        \assert($field instanceof FileFormField);

        return $field;
    }

    /**
     * A .docx with as much of a real package as this ticket needs.
     *
     * Built here rather than committed, for the reason `DocumentTemplateTest`
     * gives: a zip in the repository is a thing nobody can read a diff of, and
     * this way the test states what a template *is*. It carries three things that
     * one does not — a header part, relationships of the customer's own, and a
     * body split into however many runs a caller asks for — because those are the
     * three cases XIV-89 is actually about.
     *
     * @param list<string> $body          one `<w:r>` per entry
     * @param list<string> $relationships extra ids in `word/_rels/document.xml.rels`
     */
    private static function aDocx(array $body, ?string $header, array $relationships): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-test-logo-') . '.docx';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . ($header === null ? '' : '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>')
            . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');

        // Whatever the customer's own document already points at. External
        // hyperlinks, because they need no part to exist and are the commonest
        // relationship a letter really has.
        $theirs = '';

        foreach ($relationships as $id) {
            $theirs .= sprintf(
                '<Relationship Id="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.test/" TargetMode="External"/>',
                $id,
            );
        }

        if ($header !== null) {
            $theirs .= '<Relationship Id="rIdHeaderOne" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>';

            $zip->addFromString('word/header1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:p>' . self::runsOf([$header]) . '</w:p>'
                . '</w:hdr>');
        }

        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $theirs
            . '</Relationships>');

        // The section properties are what make a header a header rather than an
        // orphaned part, which matters for the PDF: LibreOffice draws what the
        // section references and nothing else.
        $section = $header === null
            ? ''
            : '<w:sectPr><w:headerReference w:type="default" r:id="rIdHeaderOne"/></w:sectPr>';

        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>'
            . '<w:p>' . self::runsOf($body) . '</w:p>'
            . $section
            . '</w:body></w:document>');

        $zip->close();

        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /**
     * One `<w:r>` per entry, which is how Word stores a line somebody typed and
     * then edited in the middle of.
     *
     * @param list<string> $runs
     */
    private static function runsOf(array $runs): string
    {
        $xml = '';

        foreach ($runs as $run) {
            $xml .= '<w:r><w:t xml:space="preserve">' . htmlspecialchars($run, \ENT_XML1) . '</w:t></w:r>';
        }

        return $xml;
    }

    /**
     * A real PNG, 480 × 160, written out by hand.
     *
     * **Not generated with GD**, which this application does not depend on and
     * this container does not have — and rightly, since the check the upload
     * makes is `getimagesizefromstring`, which is core PHP. A fixture built by an
     * extension that may not be installed would be testing the wrong thing on the
     * wrong machine.
     *
     * It has to be a *genuine* PNG rather than a plausible header, because the
     * PDF half of this test asks LibreOffice to draw it. And it has to be this
     * shape rather than one pixel, because 480 × 160 is three times the 40 mm the
     * brief caps a mark at — so the document proves the scaling rather than
     * merely the placing.
     */
    private static function png(): string
    {
        $width = 480;
        $height = 160;

        $raw = '';

        for ($row = 0; $row < $height; ++$row) {
            // A filter byte of zero — "this scanline is stored as it is" — then
            // three bytes a pixel. One flat colour, because what is being tested
            // is that the bytes arrive, not what they look like.
            $raw .= "\x00" . str_repeat("\x2f\x6b\xd8", $width);
        }

        return "\x89PNG\r\n\x1a\n"
            // Width, height, eight bits a channel, colour type 2 (truecolour),
            // the only compression and filter methods PNG has, not interlaced.
            . self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . self::chunk('IDAT', (string) gzcompress($raw, 9))
            . self::chunk('IEND', '');
    }

    /** A PNG chunk: its length, its name, its bytes and a CRC over the last two. */
    private static function chunk(string $type, string $data): string
    {
        return pack('N', \strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /** One part of a .docx, as it was written. */
    private function partOf(string $docx, string $part): string
    {
        $path = $this->fileOf($docx, 'read.docx');

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true, 'the download is a readable zip');

        $contents = $zip->getFromName($part);
        $zip->close();

        self::assertIsString($contents, $part . ' is in the package');

        return $contents;
    }

    /**
     * Every media part in the package, by name.
     *
     * @return list<string>
     */
    private function mediaIn(string $docx): array
    {
        $path = $this->fileOf($docx, 'read.docx');

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true);

        $media = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);

            if (str_starts_with($name, 'word/media/')) {
                $media[] = $name;
            }
        }

        $zip->close();

        return $media;
    }

    /** The words in a piece of Word XML, with the markup taken off. */
    private static function textOf(string $xml): string
    {
        return strip_tags($xml);
    }

    /** A real file on disk, because that is what a browser sends and ZipArchive reads. */
    private function fileOf(string $contents, string $name): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('xivi-logo-doc-', true) . '-' . $name;
        file_put_contents($path, $contents);

        $this->files[] = $path;

        return $path;
    }

    private function aContact(): int
    {
        return $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'person', 'first_name' => 'Ada', 'last_name' => 'Lovelace'],
            variant: 'person',
        ));
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

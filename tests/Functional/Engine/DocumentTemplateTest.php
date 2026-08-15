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
use App\Tests\Support\SharesATenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;
use Xivi\Contact\ContactModule;
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
    use SharesATenant;

    private const string SLUG = 'test_documents';
    private const string HOST = 'documents.localhost';
    private const string ADMIN = 'admin@documents.test';
    private const string WRITER = 'writer@documents.test';
    private const string SENDER = 'sender@documents.test';
    private const string PASSWORD = 'documents-password';
    private const string FORM = 'module_record';

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

        // Not only the fields: every template ends up wanting these.
        self::assertStringContainsString('[record_id]', $page);
        self::assertStringContainsString('[today]', $page);
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

        self::assertStringContainsString('Born on 1815-12-10', $this->textOf((string) $this->client->getResponse()->getContent()));
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

    /** Uploads a template through the browser and returns its id. */
    private function upload(string $name, string $body, ?string $variant = null, ?string $placeholderControl = null): int
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
     */
    private static function aDocx(string $text, ?string $placeholderControl = null): string
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

        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $control
            . '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($text, \ENT_XML1) . '</w:t></w:r></w:p>'
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
        $this->client->request('GET', $this->url('/m/contact/new?variant=person'));
        $this->client->submitForm('Save', [
            self::field('first_name') => 'Ada',
            self::field('last_name') => 'Lovelace',
            ...array_combine(
                array_map(self::field(...), array_keys($values)),
                array_values($values),
            ),
        ]);

        $this->client->followRedirect();

        return (int) basename((string) parse_url((string) $this->client->getRequest()->getUri(), \PHP_URL_PATH));
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

    private static function field(string $key): string
    {
        return sprintf('%s[fields][%s]', self::FORM, $key);
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

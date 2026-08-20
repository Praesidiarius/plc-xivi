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
use App\Tenant\Payment\InvoicePaymentPart;
use App\Tenant\Payment\PaymentSettings;
use App\Tenant\Payment\ReferenceType;
use App\Tenant\Security\UserCreator;
use App\Tenant\Settings\TenantProfileManager;
use App\Tests\Support\SavesRecords;
use App\Tests\Support\SharesATenant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Mime\Email;
use Xivi\Article\ArticleModule;
use Xivi\Contact\ContactModule;
use Xivi\Core\History\HistoryRepository;
use Xivi\Core\Mail\EmailTemplateRepository;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Record\RecordAction;
use Xivi\Invoice\InvoiceModule;
use Xivi\Order\OrderModule;

/**
 * The QR-bill payment part on a real invoice PDF (XIV-152, §5.28).
 *
 * The payload's line-by-line correctness is InvoiceQrBillTest's job and needs
 * no converter. What only this test can prove is the other half of the
 * pipeline: that a PDF leaving the download route gained the slip as a page,
 * that the QR is actually *drawn* in it, and that the skip paths ship a plain
 * invoice plus the sentence saying why. The PDF is proved by inspecting it,
 * the standing §5.7 rule: a page count and an image XObject, not a `%PDF-`
 * prefix.
 *
 * **And that the slip is now a choice** (XIV-164): the tick is on the chooser
 * and on the modal and starts ticked, it is absent without a word where it
 * could mean nothing, unticking removes the page and nothing else, the send
 * carries the same tick, and the timeline says which way it went. The refusals
 * above are asserted a second time with the tick posted by hand, because "a
 * tick asks for a slip and does not promise one" is the property a checkbox
 * could quietly have broken.
 *
 * Skipped rather than faked when Gotenberg is not running, like the logo
 * test's PDF half and for its reason: a fake would prove the seam is called
 * and say nothing about what Chromium draws or what the merge produces.
 *
 * Whether a real banking app scans the result is deliberately left to a human
 * with a phone; no assertion here can stand in for that.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class InvoiceQrBillPdfTest extends WebTestCase
{
    use MailerAssertionsTrait;
    use SavesRecords;
    use SharesATenant;

    private const string SLUG = 'test_invoice_qr';
    private const string HOST = 'invoice-qr.localhost';
    private const string EMAIL = 'qr@example.test';
    private const string PASSWORD = 'invoice-qr-password';

    private const string IBAN = 'CH9300762011623852957';

    private KernelBrowser $client;
    private Tenant $tenant;

    /** @var list<string> temporary template files to clear up */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->tenant = $this->sharedTenant(self::SLUG, [self::HOST]);

        self::service(TenantSwitcher::class)->runFor($this->tenant, function (): void {
            $installer = self::service(ModuleInstaller::class);
            $registry = self::service(ModuleRegistry::class);

            foreach ([ContactModule::KEY, ArticleModule::KEY, OrderModule::KEY, InvoiceModule::KEY] as $key) {
                $installer->install($registry->get($key));
            }
        });

        self::service(UserCreator::class)->create($this->tenant, self::EMAIL, 'QR', self::PASSWORD, ['ROLE_ADMIN']);

        $this->signIn();
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
     * The ticket, end to end: a configured profile, an ordinary invoice, and
     * the PDF that comes back has one more page than the letter and a picture
     * in it.
     *
     * The template deliberately places no image, so the one image object in
     * the merged file can only be the QR code. The plain generation is run
     * first and asserted image-free, for the reason the logo test runs its
     * negative half: without it, this passes on a converter that puts a
     * picture in everything.
     */
    public function testAnInvoicePdfGainsThePaymentPartPage(): void
    {
        $this->configureProfile('CHF', self::IBAN);

        $pdf = $this->invoicePdf();

        if ($pdf === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(2, $this->pagesIn($pdf), 'the letter, then the payment part');
        self::assertMatchesRegularExpression('#/Subtype\s*/Image#', $pdf, 'the QR code is drawn, not merely intended');
    }

    /**
     * Any currency the standard does not know produces the invoice *without*
     * a payment part, and the reason is on the next page the person sees.
     * Never an invalid QR: there is nothing here to scan at all.
     */
    public function testAnotherCurrencyShipsThePlainInvoiceAndSaysWhy(): void
    {
        $this->configureProfile('USD', self::IBAN);

        $pdf = $this->invoicePdf();

        if ($pdf === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertSame(1, $this->pagesIn($pdf), 'no page was added');
        self::assertDoesNotMatchRegularExpression('#/Subtype\s*/Image#', $pdf, 'and no QR went along');

        // The flash lands on whatever the person looks at next, which after a
        // download is the record they came from.
        $next = $this->client->request('GET', $this->url('/m/invoice/' . $this->invoice))->filter('main')->text();

        self::assertStringContainsString('USD', $next, 'the sentence names the currency in the way');
        self::assertStringContainsString('without a QR payment part', $next);
    }

    /**
     * The acceptance criterion in its own words: a tenant whose profile lacks
     * required creditor data is told what is missing before a broken payment
     * part ships. Nothing broken ships, and the gaps are named.
     */
    public function testMissingCreditorDataIsNamedAndNothingBrokenShips(): void
    {
        // A currency the standard accepts, and no account for it to pay into.
        $this->configureProfile('CHF', iban: '');

        $pdf = $this->invoicePdf();

        if ($pdf === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertSame(1, $this->pagesIn($pdf));
        self::assertDoesNotMatchRegularExpression('#/Subtype\s*/Image#', $pdf);

        $next = $this->client->request('GET', $this->url('/m/invoice/' . $this->invoice))->filter('main')->text();

        self::assertStringContainsString('The company profile is missing', $next);
        self::assertStringContainsString('IBAN', $next, 'the gap is named by its field label');
    }

    /**
     * The tick is on the chooser and on the modal, and it starts ticked
     * (XIV-164).
     *
     * Both, because they are one form rendered twice (§5.7) and a default that
     * held on the page and not in the modal would be the worse half wrong: the
     * modal is where nine downloads in ten are started.
     *
     * No converter is needed for any of this. What is being asserted is what
     * the form asks, not what comes back.
     */
    public function testTheChooserOffersThePaymentPartTickedByDefault(): void
    {
        $this->configureProfile('CHF', self::IBAN);
        [$invoice] = $this->anInvoiceAndATemplate();

        foreach (['/m/invoice/' . $invoice . '/document', '/m/invoice/' . $invoice] as $path) {
            $tick = $this->ticksOn($path);

            self::assertCount(1, $tick, 'one tick per offer, on ' . $path);
            self::assertNotNull($tick->attr('checked'), 'ticked by default, on ' . $path);
        }
    }

    /**
     * And the .docx silence is wired up, which is the one of the three this
     * suite cannot watch happen.
     *
     * The tick hides when somebody chooses the .docx, and that is a Stimulus
     * controller rather than a round trip: the format is picked in the same
     * form. Why there is no browser test of the behaviour is argued in
     * `assets/controllers/document_decorations_controller.js`; what is checked
     * here is the half that can rot silently, which is the four names the
     * template and the controller have to agree on. A renamed target leaves a
     * page that renders perfectly and does nothing at all.
     */
    public function testTheTickIsWiredToTheFormatItDependsOn(): void
    {
        $this->configureProfile('CHF', self::IBAN);
        [$invoice] = $this->anInvoiceAndATemplate();

        $page = $this->client->request('GET', $this->url('/m/invoice/' . $invoice . '/document'));

        self::assertCount(1, $page->filter('form[data-controller="document-decorations"]'));
        self::assertCount(1, $page->filter('select[name="format"][data-document-decorations-target="format"]'));
        self::assertCount(
            1,
            $page->filter('select[name="format"][data-action="change->document-decorations#refresh"]'),
        );
        self::assertCount(1, $page->filter('[data-document-decorations-target="field"] input[name="decorations[]"]'));
    }

    /**
     * And it is absent, silently, everywhere it could not mean anything.
     *
     * Two of the three silences the ticket asks for, and they are the two the
     * seam decides: a module that offers no decoration at all, and a tenant
     * whose settings could not produce a payment part. The third is the .docx,
     * which is the format select's business and is hidden by the controller in
     * `assets/controllers/`.
     *
     * **Absent rather than disabled.** A contact has no payment slip to want,
     * and a box explaining that on every contact in the system is noise on
     * every record of a module this feature is not about.
     */
    public function testNoTickWhereThereIsNothingToOffer(): void
    {
        $this->configureProfile('CHF', self::IBAN);

        $contact = $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Acme AG'],
            variant: 'company',
        ));
        $this->uploadTemplate('contact');

        self::assertCount(0, $this->ticksOn('/m/contact/' . $contact . '/document'), 'a contact has none to offer');

        // And an installation that could not make one either way. The invoice
        // module offers a payment part; this tenant cannot be paid through it.
        $this->configureProfile('USD', self::IBAN);
        [$invoice] = $this->anInvoiceAndATemplate();

        self::assertCount(0, $this->ticksOn('/m/invoice/' . $invoice . '/document'));
    }

    /**
     * Unticking, which is the whole ticket.
     *
     * The same invoice and the same template, downloaded twice: what changes is
     * the page the decorator adds at the very end and nothing else, which is
     * what "identical apart from the slip" means in a pipeline where unticking
     * skips one step after the conversion rather than changing anything the
     * template said. Structurally is how this suite proves things about PDFs
     * (§5.7's rule for the logo): a page count and an image object, in both
     * directions, so neither assertion can pass on a converter that puts a
     * picture in everything.
     */
    public function testUntickingProducesTheSameInvoiceWithoutThePaymentPart(): void
    {
        $this->configureProfile('CHF', self::IBAN);
        [$invoice, $template] = $this->anInvoiceAndATemplate();

        $ticked = $this->download($invoice, $template);

        if ($ticked === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        $unticked = (string) $this->download($invoice, $template, paymentPart: false);

        self::assertSame(2, $this->pagesIn($ticked), 'the letter, then the payment part');
        self::assertSame(1, $this->pagesIn($unticked), 'the letter, and nothing added to it');
        self::assertMatchesRegularExpression('#/Subtype\s*/Image#', $ticked);
        self::assertDoesNotMatchRegularExpression('#/Subtype\s*/Image#', $unticked, 'no QR went with it');

        // And nothing was said about it, because nothing went wrong: the person
        // asked for an invoice without a slip and got one. The sentences
        // XIV-152 writes are for an installation that *cannot* make a payment
        // part, and this one can.
        $next = $this->client->request('GET', $this->url('/m/invoice/' . $invoice))->filter('main')->text();

        self::assertStringNotContainsString('without a QR payment part', $next);
    }

    /**
     * A tick is a request, never a promise (XIV-164), and XIV-152's refusals
     * are untouched by it.
     *
     * The tick is not drawn for this tenant at all, so this is the form retyped
     * by hand, which is the only way the request can exist. It has to produce
     * exactly what XIV-152 produced: the invoice, no payment part, and the
     * sentence naming the currency. **Never an invalid QR** is a bound the
     * checkbox cannot lift.
     */
    public function testATickCannotProduceASlipTheStandardRefuses(): void
    {
        $this->configureProfile('USD', self::IBAN);
        [$invoice, $template] = $this->anInvoiceAndATemplate();

        $pdf = $this->download($invoice, $template);

        if ($pdf === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertSame(1, $this->pagesIn($pdf), 'asking loudly changed nothing');
        self::assertDoesNotMatchRegularExpression('#/Subtype\s*/Image#', $pdf);

        $next = $this->client->request('GET', $this->url('/m/invoice/' . $invoice))->filter('main')->text();

        self::assertStringContainsString('USD', $next, 'and the reason is still said');
    }

    /**
     * The send carries the same tick, and the timeline says what went out
     * (XIV-164).
     *
     * The sharp half of the ticket. §5.15 attaches through the same
     * `contents()` the download uses, which is why the slip reaches the emailed
     * copy at all, and a choice that existed on the download and not on the
     * send would be incoherent. So this sends the same invoice twice, once with
     * the tick and once without, and reads the attachment that actually left
     * rather than the form that asked for it.
     *
     * And the entry: one row, naming the attachment, and now naming what was on
     * it. "Was the payment part on the invoice we sent" is the question asked
     * months later, and `false` is as much an answer as `true` is.
     */
    public function testTheSendCarriesTheSameTickAndTheTimelineRecordsIt(): void
    {
        $this->configureProfile('CHF', self::IBAN);
        [$invoice, $template] = $this->anInvoiceAndATemplate();
        $email = $this->emailTemplate();

        $withSlip = $this->sendInvoice($invoice, $email, $template, paymentPart: true);

        if ($withSlip === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertSame(2, $this->pagesIn($withSlip), 'the mailed copy is the one a customer pays from');
        self::assertSame(
            ['payment_part' => true],
            $this->lastAttachmentEntry($invoice)['decorations'] ?? null,
        );

        $without = (string) $this->sendInvoice($invoice, $email, $template, paymentPart: false);

        self::assertSame(1, $this->pagesIn($without));
        self::assertSame(
            ['payment_part' => false],
            $this->lastAttachmentEntry($invoice)['decorations'] ?? null,
            'an invoice deliberately sent without a slip says so, rather than saying nothing',
        );

        // And it is on the page, in the reader's own language, because an
        // answer only the database holds is not the one anybody asks.
        $timeline = $this->client->request('GET', $this->url('/m/invoice/' . $invoice))->filter('main')->text();

        self::assertStringContainsString('QR-bill payment part included', $timeline);
        self::assertStringContainsString('QR-bill payment part left out', $timeline);
    }

    /**
     * And the preview agrees with the send it precedes.
     *
     * The preview exists so that what arrives holds no surprises (§5.15), and a
     * preview silent about the payment part would be a preview of a different
     * document. It generates for real, so what it reports is read off the file
     * rather than off the tick.
     */
    public function testThePreviewSaysWhetherThePaymentPartIsOnIt(): void
    {
        $this->configureProfile('CHF', self::IBAN);
        [$invoice, $template] = $this->anInvoiceAndATemplate();
        $email = $this->emailTemplate();

        $preview = $this->previewInvoice($invoice, $email, $template, paymentPart: true);

        if ($preview === null) {
            self::markTestSkipped('The document converter is not running.');
        }

        self::assertStringContainsString('QR-bill payment part included', $preview->filter('main')->text());

        $left = $this->previewInvoice($invoice, $email, $template, paymentPart: false);
        \assert($left instanceof Crawler);

        self::assertStringContainsString('QR-bill payment part left out', $left->filter('main')->text());

        // And pressing Send on a preview sends what was previewed. The ticks
        // travel that last step as hidden inputs, and this is the direction
        // that proves they do: an unticked box submits nothing, so a page that
        // forwarded every other answer and dropped these would look right on
        // the way out and wrong here.
        $this->client->submit($preview->selectButton('Send')->form());

        self::assertSame(
            ['payment_part' => true],
            $this->lastAttachmentEntry($invoice)['decorations'] ?? null,
            'the send after a preview is the send that was previewed',
        );
    }

    // -- helpers ------------------------------------------------------------

    /** The invoice the last invoicePdf() call generated from. */
    private int $invoice = 0;

    /**
     * The tenant as a creditor, through the same manager the profile page
     * writes with, so what is configured here is what a customer can actually
     * reach.
     */
    private function configureProfile(string $currency, string $iban): void
    {
        self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($currency, $iban): void {
            $manager = self::service(TenantProfileManager::class);

            $manager->apply('Muster AG', $currency, 'CH');
            $manager->applyPayment(PaymentSettings::from(
                $iban,
                ReferenceType::Scor,
                'Musterstrasse',
                '7',
                '8000',
                'Zürich',
            ));
        });
    }

    /**
     * An order, an invoice for it, a one-marker template, and the PDF from
     * the same download route a person clicks, with the payment part asked
     * for.
     *
     * @return string|null null when the converter is not running, the shape
     *                     the logo test settled for this suite
     */
    private function invoicePdf(): ?string
    {
        [$invoice, $template] = $this->anInvoiceAndATemplate();

        return $this->download($invoice, $template);
    }

    /**
     * The fixture, so a test can generate from it twice (XIV-164).
     *
     * Ticked and unticked have to be the *same* invoice and the *same*
     * template for "identical apart from the slip" to mean anything at all.
     *
     * @return array{int, int} the invoice's id and the template's
     */
    private function anInvoiceAndATemplate(): array
    {
        $company = $this->savedId($this->saveRecord(
            ContactModule::KEY,
            ['kind' => 'company', 'company_name' => 'Acme AG', 'email' => 'acme@example.test'],
            variant: 'company',
        ));

        $order = $this->savedId($this->saveRecord(
            OrderModule::KEY,
            ['contact' => (string) $company, 'ordered_on' => '2026-08-15', 'status' => OrderModule::DRAFT],
            [OrderModule::LINES => [self::row([
                OrderModule::KIND => OrderModule::CUSTOM_LINE,
                'description' => 'Consulting',
                'quantity' => '2',
                'unit_price' => '150.00',
            ])]],
        ));

        $this->invoice = $this->savedId($this->saveRecord(
            InvoiceModule::KEY,
            [
                InvoiceModule::ORDER => (string) $order,
                InvoiceModule::CONTACT => (string) $company,
                InvoiceModule::ISSUED_ON => '2026-08-15',
                InvoiceModule::STATUS => InvoiceModule::DRAFT,
            ],
            [InvoiceModule::LINES => [self::row([
                InvoiceModule::KIND => InvoiceModule::CUSTOM_LINE,
                InvoiceModule::DESCRIPTION => 'Consulting',
                InvoiceModule::QUANTITY => '2',
                InvoiceModule::UNIT_PRICE => '150.00',
            ])]],
        ));

        return [$this->invoice, $this->uploadTemplate()];
    }

    /**
     * The download route, with the tick on or off (XIV-164).
     *
     * Unticking is the *absence* of the parameter rather than a "no" in it,
     * because that is what an unticked checkbox does: it submits nothing. A
     * test that sent `decorations[]=` would be testing something the browser
     * never produces.
     *
     * @return string|null null when the converter is not running
     */
    private function download(int $invoice, int $template, bool $paymentPart = true): ?string
    {
        $query = ['template' => $template, 'format' => 'pdf'];

        if ($paymentPart) {
            $query['decorations'] = [InvoicePaymentPart::PAYMENT_PART];
        }

        $this->client->request('GET', $this->url(sprintf(
            '/m/invoice/%d/document/download?%s',
            $invoice,
            http_build_query($query),
        )));

        if ($this->client->getResponse()->isRedirection()) {
            return null;
        }

        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * Sends the invoice from the record page, and gives back what was attached.
     *
     * Through the form somebody actually presses, ticked or unticked, because
     * the default being *in the form* is half of what is under test: a helper
     * that posted `decorations[]` by hand would prove the pipeline and say
     * nothing about the screen.
     *
     * @return string|null the attached PDF, or null when the converter is not
     *                     running and the send was refused for it
     */
    private function sendInvoice(int $invoice, int $email, int $template, bool $paymentPart): ?string
    {
        $this->chooserFor($invoice, $email, $template, $paymentPart, 'Send');

        $message = self::getMailerMessage();

        if ($message === null) {
            return null;
        }

        self::assertInstanceOf(Email::class, $message);

        $attachments = $message->getAttachments();

        self::assertCount(1, $attachments, 'the invoice went with it');

        return $attachments[0]->getBody();
    }

    /** The same form, taking the other way out of it. */
    private function previewInvoice(int $invoice, int $email, int $template, bool $paymentPart): ?Crawler
    {
        $preview = $this->chooserFor($invoice, $email, $template, $paymentPart, 'Preview and send');

        // The preview draws the message in a sandboxed frame; the chooser it
        // falls back to when the document could not be made has none, which is
        // how a stopped converter is told apart from a preview here.
        return $preview->filter('iframe')->count() === 0 ? null : $preview;
    }

    /** @return Crawler whatever the button led to */
    private function chooserFor(int $invoice, int $email, int $template, bool $paymentPart, string $button): Crawler
    {
        $page = $this->client->request('GET', $this->url('/m/invoice/' . $invoice));
        $form = $page->selectButton($button)->form();

        $form['template'] = (string) $email;
        $form['document'] = (string) $template;
        $form['format'] = 'pdf';

        $ticks = $form['decorations'];
        \assert(\is_array($ticks));
        $tick = $ticks[0];
        \assert($tick instanceof ChoiceFormField);

        self::assertTrue($tick->hasValue(), 'the send chooser draws the tick, ticked');

        if (!$paymentPart) {
            $tick->untick();
        }

        return $this->client->submit($form);
    }

    /**
     * What the newest send wrote down about its attachment.
     *
     * @return array<string, mixed>
     */
    private function lastAttachmentEntry(int $invoice): array
    {
        $entries = self::service(TenantSwitcher::class)->runFor($this->tenant, function () use ($invoice): array {
            $definition = self::service(MetadataRepository::class)->get(InvoiceModule::KEY);

            return self::service(HistoryRepository::class)->findFor($definition, $invoice, 50);
        });

        foreach ($entries as $entry) {
            if ($entry->action === RecordAction::EmailSent) {
                $attachment = $entry->email()['attachment'] ?? null;
                \assert(\is_array($attachment));

                return $attachment;
            }
        }

        self::fail('the send was recorded on the timeline');
    }

    /** An email template for the invoice module, written through its own form. */
    private function emailTemplate(): int
    {
        $crawler = $this->client->request('GET', $this->url('/m/invoice/email-templates/new'));
        $this->client->submit($crawler->selectButton('Save')->form([
            'name' => 'Invoice mail',
            'subject' => 'Your invoice',
            'body' => 'The invoice is attached.',
        ]));
        $this->client->followRedirect();

        $written = self::service(TenantSwitcher::class)->runFor(
            $this->tenant,
            fn (): array => self::service(EmailTemplateRepository::class)->forModule(InvoiceModule::KEY),
        );

        self::assertNotEmpty($written, 'the email template was accepted');

        return (int) end($written)->getId();
    }

    /** Every payment-part tick the page draws, ticked or not. */
    private function ticksOn(string $path): Crawler
    {
        $page = $this->client->request('GET', $this->url($path));

        self::assertResponseIsSuccessful();

        return $page->filter(sprintf('input[name="decorations[]"][value="%s"]', InvoicePaymentPart::PAYMENT_PART));
    }

    /**
     * Page objects in the bytes, the count the reader's page list shows.
     *
     * `/Type /Page` dictionaries cannot live inside compressed object streams
     * in the files both converters here produce, which is what makes counting
     * them in the raw bytes honest; `[^s]` keeps the single `/Type /Pages`
     * tree node out of the count.
     */
    private function pagesIn(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    private function uploadTemplate(string $module = InvoiceModule::KEY): int
    {
        $path = sys_get_temp_dir() . '/' . uniqid('xivi-qr-template-', true) . '.docx';
        file_put_contents($path, self::aDocx());
        $this->files[] = $path;

        $crawler = $this->client->request('GET', $this->url('/m/' . $module . '/templates'));
        $form = $crawler->selectButton('Upload a template')->form(['name' => 'Invoice']);
        self::fileField($form)->upload($path);

        $this->client->submit($form);
        $this->client->followRedirect();

        $actions = $this->client->getCrawler()
            ->filter('form[action*="/templates/"]')
            ->each(static fn ($node): string => (string) $node->attr('action'));

        self::assertNotEmpty($actions, 'the template was accepted');

        return (int) preg_replace('#\D#', '', str_replace('/delete', '', (string) end($actions)));
    }

    /** The one file input on the upload form, typed. */
    private static function fileField(Form $form): FileFormField
    {
        $field = $form['template'];
        \assert($field instanceof FileFormField);

        return $field;
    }

    /**
     * The smallest .docx that is a document: one paragraph, one marker. No
     * images anywhere, which is what lets the tests above treat "an image in
     * the PDF" as "the QR code".
     */
    private static function aDocx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xivi-qr-docx-') . '.docx';

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
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t xml:space="preserve">Invoice [number]</w:t></w:r></w:p></w:body>'
            . '</w:document>');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function signIn(): void
    {
        $crawler = $this->client->request('GET', $this->url('/login'));
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

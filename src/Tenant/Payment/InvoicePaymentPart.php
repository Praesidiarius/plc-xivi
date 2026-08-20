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

namespace App\Tenant\Payment;

use App\Tenancy\TenantContext;
use App\Tenant\Document\GotenbergPaymentSlip;
use App\Tenant\Repository\TenantProfileRepository;
use Psr\Log\LoggerInterface;
use Sprain\SwissQrBill\PaymentPart\Output\HtmlOutput\HtmlOutput;
use Sprain\SwissQrBill\QrCode\QrCode;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Document\PdfDecorator;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Record;
use Xivi\Invoice\InvoiceModule;

/**
 * The application half of `PdfDecorator`: a Swiss QR-bill on every invoice
 * that can carry one (XIV-152).
 *
 * The pipeline position does the heavy lifting: running inside
 * DocumentGenerator::contents() is what puts the payment part on the download
 * *and* on the mailed copy without either caller knowing. What is decided here
 * is narrower: this decorator answers for the invoice module and nobody else,
 * and when there is no payment part to make it says why instead of failing,
 * because the invoice itself is a perfectly good document (see
 * PaymentPartUnavailable for that argument).
 *
 * **Where "why" is said.** A flash, when a session is there to carry one, so the
 * person who clicked download sees the sentence beside their PDF and can act
 * on it, which is the ticket's "told what is missing". And the log always,
 * because the same code runs under a console command and behind the send
 * screen, where a flash may surface late or never. Both channels get the same
 * sentence.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class InvoicePaymentPart implements PdfDecorator
{
    /** The four languages a payment part exists in, per the guidelines. */
    private const array LANGUAGES = ['de', 'fr', 'it', 'en'];

    public function __construct(
        private TenantContext $tenancy,
        private TenantProfileRepository $profiles,
        private InvoiceQrBill $bills,
        private GotenbergPaymentSlip $slips,
        private RequestStack $requests,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public function decorate(ModuleDefinition $module, Record $record, string $pdf): string
    {
        // By key, the way modules name each other everywhere (§3): whether the
        // installed module *is* the invoice module is a fact about the key, and
        // importing the class costs nothing because the application layer may
        // see every module.
        if ($module->getKey() !== InvoiceModule::KEY) {
            return $pdf;
        }

        // No tenant, no profile, no creditor: the same guard InstanceContext
        // keeps, for the same reason: a console command on a control-plane host
        // is not an error, it is a context with nothing to add.
        if (!$this->tenancy->hasTenant()) {
            return $pdf;
        }

        $profile = $this->profiles->current();
        $number = \is_string($record->data[InvoiceModule::NUMBER] ?? null) ? $record->data[InvoiceModule::NUMBER] : '';
        $total = \is_string($record->data[InvoiceModule::GROSS_TOTAL] ?? null) ? $record->data[InvoiceModule::GROSS_TOTAL] : null;

        try {
            $bill = $this->bills->assemble($profile, $number, $total);
        } catch (PaymentPartUnavailable $why) {
            $this->say($why);

            return $pdf;
        }

        // PNG rather than the library's default SVG, for the printed artefact:
        // the payload sizes the image at 300 dpi for the normative 46 mm, and a
        // raster QR embeds as an image object a test can find in the PDF, the
        // same proof §5.7 demands for the logo. The slip's *text* stays text.
        $html = (new HtmlOutput($bill, $this->language()))
            ->setQrCodeImageFormat(QrCode::FILE_FORMAT_PNG)
            ->getPaymentPart();

        return $this->slips->append($pdf, (string) $html);
    }

    /**
     * The reason, to the person and to the record of what happened.
     *
     * A warning rather than an error on both channels: the document was
     * produced and handed over, and the sentence is advice about what it
     * lacks.
     */
    private function say(PaymentPartUnavailable $why): void
    {
        $sentence = $why->sentence($this->translator);

        $this->logger->warning('Invoice PDF generated without a QR-bill payment part: {reason}', [
            'reason' => $why->getMessage(),
        ]);

        $session = $this->requests->getCurrentRequest()?->hasSession() === true
            ? $this->requests->getSession()
            : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', $sentence);
        }
    }

    /**
     * Which of the standard's four languages this payment part prints in.
     *
     * The reader's own locale, because the person generating the document is
     * the nearest thing to knowing what the *recipient* reads, the same
     * approximation every template already makes, since the letter around the
     * slip is written in whatever language the template's author typed.
     * Anything outside the four falls to German: the feature exists for
     * invoices into Switzerland, and German is that market's majority answer.
     * The locale comes off the request rather than the translator because the
     * contract-level TranslatorInterface deliberately has no getLocale(), and
     * outside a request, in a console command, German is the right fallback
     * anyway.
     */
    private function language(): string
    {
        $locale = substr((string) $this->requests->getCurrentRequest()?->getLocale(), 0, 2);

        return \in_array($locale, self::LANGUAGES, true) ? $locale : 'de';
    }
}

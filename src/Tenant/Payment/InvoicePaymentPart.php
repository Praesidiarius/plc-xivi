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
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\QrCode\QrCode;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Xivi\Core\Document\Decoration;
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
 * ### Offered, then applied (XIV-164)
 *
 * The slip stopped being unconditional and became a tick on the chooser, which
 * splits this class's one question in two. {@see self::offers()} answers
 * whether there is a payment part to be had at all, silently, so a form can be
 * drawn; {@see self::decorate()} asks the same question again and then asks
 * whether anybody wanted the answer.
 *
 * **One predicate behind both**, {@see self::bill()}, and that is what keeps
 * XIV-152's refusals exactly where they were. A tenant with no IBAN, or a
 * currency the standard does not know, gets no tick, and still gets the
 * sentence saying why, on every generation, because the availability question
 * is asked *before* the tick is consulted. That ordering is the decision. The
 * alternative reads better in the code and is wrong on the screen: check the
 * tick first and a misconfigured installation, whose tick was never drawn and
 * therefore never ticked, would silently stop being told what is missing, which
 * is the one sentence XIV-152 was built around. Whereas somebody who *was*
 * offered a payment part and unticked it hears nothing, because a wish that was
 * granted needs no explanation. The cost is a payload assembled and thrown away
 * on a deliberately undecorated invoice: a profile read and a validation pass,
 * no converter, no network.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class InvoicePaymentPart implements PdfDecorator
{
    /**
     * What the tick is called in a request and on a timeline.
     *
     * The one string both sides of the round trip agree on, so it is a constant
     * rather than a literal in two files.
     */
    public const string PAYMENT_PART = 'payment_part';

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

    /**
     * One tick on an invoice this tenant could actually be paid through, and
     * nothing anywhere else (XIV-164).
     *
     * Every refusal here is silent, including the ones {@see self::decorate()}
     * says out loud. This method is called while a chooser is being drawn, and
     * a form is not the place to be told that a company profile is incomplete:
     * the sentence belongs beside the document that came out without a slip,
     * where it is about something that just happened rather than about a box
     * that is missing for reasons nobody asked about yet.
     */
    public function offers(ModuleDefinition $module, Record $record): array
    {
        if (!$this->appliesTo($module)) {
            return [];
        }

        try {
            $this->bill($record);
        } catch (PaymentPartUnavailable) {
            return [];
        }

        return [new Decoration(self::PAYMENT_PART, 'payment_part.include', 'payment_part.include_help')];
    }

    public function decorate(ModuleDefinition $module, Record $record, string $pdf, array $wanted): string
    {
        if (!$this->appliesTo($module)) {
            return $pdf;
        }

        try {
            $bill = $this->bill($record);
        } catch (PaymentPartUnavailable $why) {
            // Said whether or not a payment part was asked for, and see the
            // class docblock for why that is the right way round: on an
            // installation this refuses, no tick was ever drawn, so nobody
            // declined anything and everybody is owed the reason.
            $this->say($why);

            return $pdf;
        }

        // And only now the tick. A payload that assembles and is not wanted is
        // the ordinary "no thank you": a copy for the file, a proforma, an
        // invoice already paid. It goes out as quietly as it was asked for.
        if (!\in_array(self::PAYMENT_PART, $wanted, true)) {
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
     * Whether this decorator has anything to do with the module in hand.
     *
     * By key, the way modules name each other everywhere (§3): whether the
     * installed module *is* the invoice module is a fact about the key, and
     * importing the class costs nothing because the application layer may see
     * every module.
     *
     * The tenant is half of the same question. No tenant, no profile, no
     * creditor: the same guard InstanceContext keeps, for the same reason. A
     * console command on a control-plane host is not an error, it is a context
     * with nothing to add.
     */
    private function appliesTo(ModuleDefinition $module): bool
    {
        return $module->getKey() === InvoiceModule::KEY && $this->tenancy->hasTenant();
    }

    /**
     * This invoice as a payment payload, or the reason there is none.
     *
     * The one predicate both public methods stand on (XIV-164), which is what
     * makes "the tick is absent exactly where the slip would have been refused"
     * true by construction rather than by two conditions being kept in step.
     *
     * @throws PaymentPartUnavailable
     */
    private function bill(Record $record): QrBill
    {
        $number = \is_string($record->data[InvoiceModule::NUMBER] ?? null) ? $record->data[InvoiceModule::NUMBER] : '';
        $total = \is_string($record->data[InvoiceModule::GROSS_TOTAL] ?? null) ? $record->data[InvoiceModule::GROSS_TOTAL] : null;

        return $this->bills->assemble($this->profiles->current(), $number, $total);
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

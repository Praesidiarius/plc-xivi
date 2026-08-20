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

namespace App\Tests\Unit\Payment;

use App\Tenant\Entity\TenantProfile;
use App\Tenant\Payment\InvoiceQrBill;
use App\Tenant\Payment\PaymentPartUnavailable;
use App\Tenant\Payment\ReferenceType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * The SPC payload behind an invoice's payment part (XIV-152).
 *
 * A unit test because the assembler was shaped for exactly this: profile in,
 * payload out, no kernel, no converter, no PDF. What is asserted is the
 * *structure the banking app scans*, line by line where it matters, because
 * "the QR renders" proves nothing about whether the twenty-eighth line is a
 * reference a bank will reconcile. The rendering half of the ticket is the
 * functional test's job (InvoiceQrBillPdfTest), and whether a real banking app
 * accepts the result stays a human check by design.
 *
 * The payload's shape, for the reader with the guidelines closed: line 0 `SPC`
 * and 1 `0200` are the header, 3 the IBAN, 4 to 10 the creditor's structured
 * address, 11 to 17 an empty block the standard reserves, 18/19 amount and
 * currency, 20 to 26 the (deliberately open) debtor, 27/28 reference type and
 * reference, 29 the free-text message, 30 the `EPD` trailer.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class InvoiceQrBillTest extends TestCase
{
    /** An ordinary CH IBAN, the example the guidelines themselves use. */
    private const string IBAN = 'CH9300762011623852957';

    /** A QR-IBAN: institution id 31999, inside the 30000-31999 QR range. */
    private const string QR_IBAN = 'CH4431999123000889012';

    public function testTheDefaultScorPayloadCarriesTheInvoiceNumberAsACreditorReference(): void
    {
        $bill = (new InvoiceQrBill())->assemble($this->profile(), 'INV-2026-0001', '1234.55');
        $lines = explode("\n", $bill->getQrCode()->getText());

        self::assertSame('SPC', $lines[0], 'the Swiss Payments Code marker');
        self::assertSame('0200', $lines[1], 'version 2.0, the one in the field');
        self::assertSame('1', $lines[2], 'coding: UTF-8 restricted');
        self::assertSame(self::IBAN, $lines[3]);
        self::assertSame(['S', 'Muster AG', 'Musterstrasse', '7', '8000', 'Zürich', 'CH'], \array_slice($lines, 4, 7), 'the structured creditor address, from the profile');
        self::assertSame('1234.55', $lines[18], 'the derived gross total, two decimals');
        self::assertSame('CHF', $lines[19]);
        self::assertSame(['', '', '', '', '', '', ''], \array_slice($lines, 20, 7), 'the debtor block stays open on purpose');
        self::assertSame('SCOR', $lines[27]);
        self::assertSame('RF16INV20260001', $lines[28], 'ISO 11649 over the number minus its separators, check digits included');
        self::assertSame('INV-2026-0001', $lines[29], 'the number as the payer sees it');
        self::assertSame('EPD', $lines[30], 'the trailer');

        self::assertTrue($bill->isValid(), 'the library itself signs the payload off');
    }

    public function testAQrIbanProfileMakesAQrrReferenceFromTheNumbersDigits(): void
    {
        $profile = $this->profile(iban: self::QR_IBAN, type: ReferenceType::Qrr);

        $bill = (new InvoiceQrBill())->assemble($profile, 'INV-2026-0001', '99.95');
        $lines = explode("\n", $bill->getQrCode()->getText());

        self::assertSame('QRR', $lines[27]);
        // 26 digits, zero-padded, from the number's digits (20260001), plus the
        // recursive modulo-10 check digit the library computes.
        self::assertMatchesRegularExpression('/^\d{27}$/', $lines[28]);
        self::assertStringEndsWith('20260001' . $lines[28][26], $lines[28]);
        self::assertTrue($bill->isValid());
    }

    public function testNonMeansNoReferenceButTheNumberStillTravelsAsText(): void
    {
        $bill = (new InvoiceQrBill())->assemble($this->profile(type: ReferenceType::Non), 'INV-2026-0001', '10.00');
        $lines = explode("\n", $bill->getQrCode()->getText());

        self::assertSame('NON', $lines[27]);
        self::assertSame('', $lines[28], 'NON carries no reference');
        self::assertSame('INV-2026-0001', $lines[29], 'so the free text is all the payer has');
        self::assertTrue($bill->isValid());
    }

    public function testEurIsTheOtherCurrencyTheStandardAllows(): void
    {
        $profile = $this->profile();
        $profile->setCurrency('EUR');

        $bill = (new InvoiceQrBill())->assemble($profile, 'INV-2026-0001', '50.00');

        self::assertSame('EUR', explode("\n", $bill->getQrCode()->getText())[19]);
        self::assertTrue($bill->isValid());
    }

    public function testAMissingTotalLeavesTheAmountOpenRatherThanInventingOne(): void
    {
        $bill = (new InvoiceQrBill())->assemble($this->profile(), 'INV-2026-0001', null);
        $lines = explode("\n", $bill->getQrCode()->getText());

        self::assertSame('', $lines[18], 'an open amount is the standard\'s own way of saying "payer decides"');
        self::assertTrue($bill->isValid());
    }

    public function testAnyOtherCurrencyRefusesWithTheCurrencyNamed(): void
    {
        $profile = $this->profile();
        $profile->setCurrency('USD');

        try {
            (new InvoiceQrBill())->assemble($profile, 'INV-2026-0001', '10.00');
            self::fail('USD must not make a payment part');
        } catch (PaymentPartUnavailable $why) {
            self::assertStringContainsString('USD', $why->sentence($this->translator()));
        }
    }

    public function testNoCurrencyAtAllRefusesTooBecauseGuessingOneWouldPrintIt(): void
    {
        $profile = $this->profile();
        $profile->setCurrency(null);

        $this->expectException(PaymentPartUnavailable::class);

        (new InvoiceQrBill())->assemble($profile, 'INV-2026-0001', '10.00');
    }

    /**
     * The ticket's own acceptance criterion: a profile that cannot yet be a
     * creditor is told *what* is missing, all of it at once, before anything
     * broken ships. The sentence is checked through the stub catalogue below,
     * so what is asserted is that every gap's label reached the text through
     * the translator.
     */
    public function testAnEmptyProfileIsToldEverythingItIsMissingAtOnce(): void
    {
        $profile = new TenantProfile();
        $profile->setCurrency('CHF');

        try {
            (new InvoiceQrBill())->assemble($profile, 'INV-2026-0001', '10.00');
            self::fail('an empty profile has no creditor to print');
        } catch (PaymentPartUnavailable $why) {
            $sentence = $why->sentence($this->translator());

            // The stub catalogue translates each label key to itself in
            // brackets, so finding the bracketed key proves the label went
            // through the translator rather than being printed raw.
            self::assertStringContainsString('[profile.payment_iban]', $sentence);
            self::assertStringContainsString('[profile.company_name]', $sentence);
            self::assertStringContainsString('[profile.address_postal_code]', $sentence);
            self::assertStringContainsString('[profile.address_city]', $sentence);
            self::assertStringContainsString('[profile.region]', $sentence);
        }
    }

    public function testAStreetlessAddressIsStillACreditor(): void
    {
        $profile = $this->profile();
        $profile->setAddress('', '', '8000', 'Zürich');

        $bill = (new InvoiceQrBill())->assemble($profile, 'INV-2026-0001', '10.00');

        self::assertTrue($bill->isValid(), 'the structured address type makes the street optional');
    }

    /**
     * A QRR reference is digits or nothing, so a numbering pattern with no
     * digits in it cannot feed one. The refusal names the number, because the
     * fix is the tenant's numbering pattern rather than any code.
     */
    public function testADigitlessNumberCannotMakeAQrrReference(): void
    {
        $profile = $this->profile(iban: self::QR_IBAN, type: ReferenceType::Qrr);

        try {
            (new InvoiceQrBill())->assemble($profile, 'DRAFT', '10.00');
            self::fail('there is no digit to build a QRR from');
        } catch (PaymentPartUnavailable $why) {
            self::assertStringContainsString('DRAFT', $why->sentence($this->translator()));
        }
    }

    /** The creditor's name is truncated to the payload's 70 characters, not refused. */
    public function testAnOverlongCompanyNameIsTruncatedToThePayloadWidth(): void
    {
        $profile = $this->profile();
        $profile->setCompanyName(str_repeat('Very Long Company Name ', 10));

        $bill = (new InvoiceQrBill())->assemble($profile, 'INV-2026-0001', '10.00');

        self::assertSame(70, mb_strlen(explode("\n", $bill->getQrCode()->getText())[5]));
        self::assertTrue($bill->isValid());
    }

    /**
     * A translator with the shape of the real catalogue rather than the real
     * catalogue: each sentence key becomes its placeholders, each label key
     * becomes itself in brackets. What these tests assert is that the right
     * facts reach the sentence, which survives every rewording of the prose;
     * the prose itself is the catalogue's own concern.
     */
    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'payment_part.skipped_missing' => 'missing: %fields%',
            'payment_part.skipped_currency' => 'unsupported: %currency%',
            'payment_part.skipped_no_currency' => 'no currency chosen',
            'payment_part.skipped_reference' => 'number %number% will not make a %type% reference',
            'payment_part.skipped_invalid' => 'the library said no',
            'profile.payment_iban' => '[profile.payment_iban]',
            'profile.company_name' => '[profile.company_name]',
            'profile.address_postal_code' => '[profile.address_postal_code]',
            'profile.address_city' => '[profile.address_city]',
            'profile.region' => '[profile.region]',
        ], 'en');

        return $translator;
    }

    private function profile(string $iban = self::IBAN, ReferenceType $type = ReferenceType::Scor): TenantProfile
    {
        $profile = new TenantProfile();
        $profile->setCompanyName('Muster AG');
        $profile->setCurrency('CHF');
        $profile->setRegion('CH');
        $profile->setPaymentIban($iban);
        $profile->setPaymentReferenceType($type->value);
        $profile->setAddress('Musterstrasse', '7', '8000', 'Zürich');

        return $profile;
    }
}

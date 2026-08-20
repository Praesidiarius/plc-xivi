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

use App\Tenant\Entity\TenantProfile;
use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\Reference\QrPaymentReferenceGenerator;
use Sprain\SwissQrBill\Reference\RfCreditorReferenceGenerator;

/**
 * One invoice, as the data behind a Swiss QR-bill (XIV-152).
 *
 * Everything about the payment part that is *decision* rather than rendering
 * lives here: who the creditor is (the tenant, from their own profile, never
 * the platform, §8.6), which reference type carries the invoice number, and
 * when there is honestly no payment part to make. Rendering and stapling are
 * InvoicePaymentPart's problem; this class never sees a PDF, which is what
 * makes the payload testable without a converter running.
 *
 * **The debtor is deliberately left open.** The standard makes "payable by"
 * optional, and the printed slip then shows blank corner-marked boxes the
 * payer's own banking app fills from their profile. The alternative, reading
 * the contact's address, founders on two facts this codebase already argues:
 * a contact's fields are the customer's own definitions and may not exist as
 * the blueprint shipped them (§6.1), and the contact's street is one free-text
 * line where the payload wants street and number apart since the combined
 * address type was withdrawn in 2025. A guessed split printed on a payment
 * slip is worse than an open field; when contacts one day carry structured
 * addresses, this is the one method to extend.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class InvoiceQrBill
{
    /**
     * The payload, proved by the library that defined it.
     *
     * @param TenantProfile $profile    the creditor, always the tenant
     * @param string        $number     the invoice's document number (§5.10)
     * @param string|null   $grossTotal the derived gross total, a decimal
     *                                  string per §5.9, or null when the
     *                                  record holds none
     *
     * @throws PaymentPartUnavailable when this installation, this currency or
     *                                this number cannot make a valid one, with
     *                                the reason spelled out, per the ticket's
     *                                "told what is missing" criterion
     */
    public function assemble(TenantProfile $profile, string $number, ?string $grossTotal): QrBill
    {
        // Currency first, because it is the bound the ticket sets in stone: the
        // standard knows CHF and EUR, and anything else must produce *no*
        // payment part rather than an invalid one. Checked before the profile
        // gaps so a USD installation is told about its currency, not nagged
        // into filling four address fields that would change nothing.
        $currency = $profile->getCurrency();

        if ($currency !== PaymentAmountInformation::CURRENCY_CHF && $currency !== PaymentAmountInformation::CURRENCY_EUR) {
            throw PaymentPartUnavailable::unsupportedCurrency($currency);
        }

        $missing = $this->missingFrom($profile);

        if ($missing !== []) {
            throw PaymentPartUnavailable::missingProfileData($missing);
        }

        $type = ReferenceType::tryFrom($profile->getPaymentReferenceType()) ?? ReferenceType::DEFAULT;

        $bill = QrBill::create();
        $bill->setCreditorInformation(CreditorInformation::create($profile->getPaymentIban()));
        $bill->setCreditor($this->creditor($profile));
        $bill->setPaymentAmountInformation(PaymentAmountInformation::create($currency, $this->amount($grossTotal)));
        $bill->setPaymentReference($this->reference($type, $number));

        // The invoice number rides along as free text whatever the reference
        // type: the reference is for the machine on the creditor's side, and
        // this line is what the *payer* sees in their app and on their
        // statement when deciding what they just paid.
        $bill->setAdditionalInformation(AdditionalInformation::create($number));

        if (!$bill->isValid()) {
            // Everything above was checked, so reaching this means the library
            // knows a rule this application does not, which is exactly why it
            // gets the final word ("the payload passes the library's own
            // validation" is an acceptance criterion, not a formality). The
            // violations go into the log through the exception message.
            $detail = [];

            foreach ($bill->getViolations() as $violation) {
                $detail[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
            }

            throw PaymentPartUnavailable::refusedByLibrary(implode('; ', $detail));
        }

        return $bill;
    }

    /**
     * What a valid payment part needs and this profile has not said, as the
     * label keys of the fields to fill in. The whole list at once, so fixing
     * it is one visit to the form.
     *
     * The street is deliberately not on the list: the payload's structured
     * address is valid without one, and a business that is "8000 Zürich,
     * Postfach" is not to be refused over an empty box the standard itself
     * leaves optional.
     *
     * @return list<string>
     */
    private function missingFrom(TenantProfile $profile): array
    {
        $missing = [];

        if ($profile->getPaymentIban() === '') {
            $missing[] = 'profile.payment_iban';
        }

        if ($profile->getCompanyName() === '') {
            $missing[] = 'profile.company_name';
        }

        if ($profile->getAddressPostalCode() === '') {
            $missing[] = 'profile.address_postal_code';
        }

        if ($profile->getAddressCity() === '') {
            $missing[] = 'profile.address_city';
        }

        if ($profile->getRegion() === null) {
            $missing[] = 'profile.region';
        }

        return $missing;
    }

    private function creditor(TenantProfile $profile): StructuredAddress
    {
        // The payload's name field takes 70 characters and the profile's takes
        // 255; the guidelines' own instruction for the overflow is to truncate,
        // and a shortened name on the slip beats no slip. The address fields
        // need no such treatment: their columns were sized to the payload's
        // widths from the start (see TenantProfile::setAddress()).
        $name = mb_substr($profile->getCompanyName(), 0, 70);

        // The country is §8.6's region: the profile already knows where this
        // company is, and asking again would invite the two answers to differ.
        $country = (string) $profile->getRegion();

        return $profile->getAddressStreet() === ''
            ? StructuredAddress::createWithoutStreet(
                $name,
                $profile->getAddressPostalCode(),
                $profile->getAddressCity(),
                $country,
            )
            : StructuredAddress::createWithStreet(
                $name,
                $profile->getAddressStreet(),
                $profile->getAddressBuildingNumber() === '' ? null : $profile->getAddressBuildingNumber(),
                $profile->getAddressPostalCode(),
                $profile->getAddressCity(),
                $country,
            );
    }

    /**
     * The one float on a money path in this codebase, and why it is allowed to
     * exist: the library's API takes nothing else. The value it receives is a
     * derived, already-rounded two-decimal string (§5.9), the standard caps
     * amounts at 999 999 999.99, and every two-decimal value under that cap
     * round-trips through a double exactly when formatted back at two decimals,
     * which is precisely what the library does with it (number_format at
     * scale 2, nothing arithmetic in between). So the conversion is at the
     * outermost edge, once, into a representation whose error cannot reach the
     * printed digits. No figure of ours is computed *from* the float.
     */
    private function amount(?string $grossTotal): ?float
    {
        if ($grossTotal === null || $grossTotal === '' || !is_numeric($grossTotal)) {
            // No amount is a state the standard supports: the amount box prints
            // open and the payer fills it, the honest rendering of an invoice
            // whose total the record does not carry.
            return null;
        }

        return (float) $grossTotal;
    }

    /**
     * The invoice number, in whichever shape the reference type demands.
     *
     * Both generators re-validate what they are handed and throw; those throws
     * are converted to "this number does not fit this type" rather than caught
     * per-case, because every one of their refusals *is* that sentence: too
     * long, empty once stripped, all zeros.
     */
    private function reference(ReferenceType $type, string $number): PaymentReference
    {
        try {
            return match ($type) {
                // The QRR is digits only, so the number's digits are what can
                // carry it: INV-2026-0001 becomes 20260001, left-padded to 26
                // by the generator, plus its check digit. Uniqueness survives
                // the stripping for any one numbering pattern (§5.10 patterns
                // interleave literals and counters, and stripping the same
                // literals from distinct digit strings cannot collide). A
                // customer whose pattern distinguishes invoices by *letters
                // alone* would break that, and the all-digits reference is the
                // documented cost of QRR.
                ReferenceType::Qrr => PaymentReference::create(
                    PaymentReference::TYPE_QR,
                    QrPaymentReferenceGenerator::generate(null, (string) preg_replace('/\D/', '', $number)),
                ),
                // ISO 11649 keeps letters, so the whole number survives minus
                // its separators: INV-2026-0001 → RF..INV20260001,
                // recognisable on the statement it comes back on.
                ReferenceType::Scor => PaymentReference::create(
                    PaymentReference::TYPE_SCOR,
                    RfCreditorReferenceGenerator::generate((string) preg_replace('/[^A-Za-z0-9]/', '', $number)),
                ),
                ReferenceType::Non => PaymentReference::create(PaymentReference::TYPE_NON),
            };
        } catch (\Exception) {
            throw PaymentPartUnavailable::numberUnusable($number, $type);
        }
    }
}

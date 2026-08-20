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

use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Symfony\Component\Validator\Constraints\Iban;
use Symfony\Component\Validator\Validation;

/**
 * A proved set of payment settings, for the profile page (XIV-152).
 *
 * This class exists for the reason LogoUpload does: the profile save writes in
 * sequence, and its standing rule is that a refused submission saved *nothing*.
 * Whatever can refuse must therefore refuse before the first flush of the
 * request, which means refusing in a constructor the controller runs early,
 * with the writing happening further down where nothing is left to object to.
 * See TenantProfileController::save() for the ordering argument in full.
 *
 * **What is proved here and what is deliberately not.** The IBAN and its
 * pairing with the reference type are proved, because a wrong account is money
 * arriving nowhere and a mismatched pair is a payment no bank executes; without
 * this, both are discovered on an invoice that has already gone out. The
 * address fields are *not* proved: any string is somebody's street, an empty
 * one means "not filled in yet", and the place where incompleteness matters is
 * the payment part, which reports the list of what is missing at generation
 * time (see InvoiceQrBill).
 *
 * **The IBAN's judge is the library that will print it.** Format by Symfony's
 * own Iban constraint, then the QR-bill-specific rules by sprain's
 * CreditorInformation: the component that has to build the payload out of
 * this value is the component whose opinion decides whether it can, the same
 * call TenantProfileManager::isAnAddress() makes with symfony/mime.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PaymentSettings
{
    private function __construct(
        /** Normalised: no spaces, upper case, '' when the feature is unconfigured. */
        public string $iban,
        public ReferenceType $referenceType,
        public string $street,
        public string $buildingNumber,
        public string $postalCode,
        public string $city,
    ) {
    }

    /**
     * Proves a submission, or refuses the whole of it.
     *
     * An empty IBAN is not a refusal: it is the state every installation starts
     * in, and it means "no payment part yet" rather than "broken". The
     * reference type is still stored against the day the account arrives.
     *
     * @throws PaymentSettingsRefused
     */
    public static function from(
        string $iban,
        ReferenceType $referenceType,
        string $street,
        string $buildingNumber,
        string $postalCode,
        string $city,
    ): self {
        // People copy IBANs out of e-banking with the display grouping intact;
        // `CH93 0076 2011 6238 5295 7` is the same account as its unspaced
        // form. Normalising here rather than on the way out means the stored
        // value has exactly one shape and every reader can compare it.
        $iban = strtoupper((string) preg_replace('/\s+/', '', $iban));

        if ($iban !== '') {
            $violations = Validation::createValidator()->validate($iban, new Iban());

            if (\count($violations) > 0) {
                throw PaymentSettingsRefused::notAnIban($iban);
            }

            // A structurally fine IBAN from the wrong country gets its own
            // sentence, because "not an IBAN" would be false and infuriating to
            // somebody holding a perfectly valid German one.
            if (!str_starts_with($iban, 'CH') && !str_starts_with($iban, 'LI')) {
                throw PaymentSettingsRefused::notASwissAccount($iban);
            }

            $account = CreditorInformation::create($iban);

            if ($referenceType === ReferenceType::Qrr && !$account->containsQrIban()) {
                throw PaymentSettingsRefused::qrrNeedsQrIban();
            }

            if ($referenceType !== ReferenceType::Qrr && $account->containsQrIban()) {
                throw PaymentSettingsRefused::qrIbanNeedsQrr();
            }
        }

        return new self(
            $iban,
            $referenceType,
            // Trimmed like every other profile string; length is the entity's
            // concern and emptiness is a real answer here (see the class
            // docblock for why an incomplete address is stored, not refused).
            trim($street),
            trim($buildingNumber),
            trim($postalCode),
            trim($city),
        );
    }
}

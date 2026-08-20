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

use Symfony\Component\Translation\TranslatableMessage;

/**
 * Payment settings the application will not store (XIV-152).
 *
 * Refused at the point of saving rather than discovered at the point of
 * invoicing, the same call MailSettingsRefused makes about a credential, and
 * for a sharper reason here: an account number that is wrong surfaces as money
 * arriving nowhere, and by the time a payment part misdirects a payer, the
 * invoice carrying it has been sent. The profile page is where somebody is
 * *looking at* these values and can fix them; the document download is not.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PaymentSettingsRefused extends \RuntimeException
{
    private TranslatableMessage $translatable;

    /** What to show the person who caused it, in their language (XIV-8). */
    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    public static function notAnIban(string $iban): self
    {
        $refusal = new self(sprintf('"%s" is not an IBAN.', $iban));
        $refusal->translatable = new TranslatableMessage(
            'refusal.payment_not_an_iban',
            ['%iban%' => $iban],
            'messages',
        );

        return $refusal;
    }

    /**
     * The QR-bill scheme is Swiss payment infrastructure: the creditor side of
     * a payment part must be a CH or LI account, and every banking app enforces
     * it. A German or Austrian IBAN is a perfectly good account. It is just
     * not one a QR-bill can pay into, and storing it here would mean every
     * invoice explaining a missing payment part instead of this page explaining
     * it once.
     */
    public static function notASwissAccount(string $iban): self
    {
        $refusal = new self(sprintf('"%s" is not a Swiss or Liechtenstein IBAN.', $iban));
        $refusal->translatable = new TranslatableMessage(
            'refusal.payment_not_swiss',
            ['%iban%' => $iban],
            'messages',
        );

        return $refusal;
    }

    /**
     * The QRR scheme runs on QR-IBANs and only on them; a bank rejects a QRR
     * payment aimed at an ordinary account. See ReferenceType::Qrr.
     */
    public static function qrrNeedsQrIban(): self
    {
        $refusal = new self('The QR reference type requires a QR-IBAN.');
        $refusal->translatable = new TranslatableMessage('refusal.payment_qrr_needs_qr_iban', [], 'messages');

        return $refusal;
    }

    /**
     * The mirror image: a payment *to* a QR-IBAN must carry a QRR reference,
     * so a QR-IBAN stored alongside SCOR or NON is a combination no bank will
     * execute. One of the two fields has to change, and the person on the
     * profile page is the only one who knows which.
     */
    public static function qrIbanNeedsQrr(): self
    {
        $refusal = new self('A QR-IBAN can only be used with the QR reference type.');
        $refusal->translatable = new TranslatableMessage('refusal.payment_qr_iban_needs_qrr', [], 'messages');

        return $refusal;
    }
}

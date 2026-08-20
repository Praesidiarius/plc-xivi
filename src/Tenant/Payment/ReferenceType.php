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

/**
 * How a payment finds its invoice: the QR-bill's three reference types
 * (XIV-152).
 *
 * The Swiss Implementation Guidelines allow exactly three, and which of them an
 * installation may use is a fact about their *bank relationship*, not about any
 * one invoice, which is why this is a tenant setting on the profile rather
 * than anything a document decides. The values are the standard's own tokens,
 * because they are printed verbatim into the SPC payload and inventing local
 * names for them would mean a translation table between us and every banking
 * app.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum ReferenceType: string
{
    /**
     * The QR reference: 26 digits plus a check digit, and it **requires a
     * QR-IBAN**: an account whose institution id (positions 5–9) is in the
     * 30000–31999 range that banks issue specifically for this scheme. The
     * successor of the orange inpayment slip's ESR number; payments arrive
     * pre-reconciled at banks that offer the matching service.
     */
    case Qrr = 'QRR';

    /**
     * The ISO 11649 creditor reference (`RF…`), on an **ordinary IBAN**.
     *
     * The default, and deliberately so: every business with a bank account has
     * an IBAN, while a QR-IBAN is something they must ask their bank for. An
     * installation that fills in nothing but its account still gets a payment
     * part whose reference carries the invoice number, check-digit protected,
     * into their camt statement.
     */
    case Scor = 'SCOR';

    /**
     * No reference at all. The invoice number still travels, but in the free
     * text message, where nothing validates it and no bank reconciles by it.
     * On offer because the standard offers it and some fiduciaries ask for it;
     * recommended to nobody.
     */
    case Non = 'NON';

    /**
     * What an installation that has never chosen gets (§8.6's chains end in an
     * answer; this one ends here rather than in null because, unlike a currency
     * or a term, SCOR is correct for *every* account that can receive a QR-bill
     * at all, so there is no way for this default to be quietly wrong).
     */
    public const self DEFAULT = self::Scor;

    /**
     * The three, as value => translation key, for the profile form.
     *
     * Off the enum rather than written into the template, so a page cannot go
     * on offering a type this class no longer knows, the same rule the VAT
     * modes and the logo formats already follow.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $choices = [];

        foreach (self::cases() as $type) {
            $choices[$type->value] = 'profile.reference_type_' . strtolower($type->value);
        }

        return $choices;
    }
}

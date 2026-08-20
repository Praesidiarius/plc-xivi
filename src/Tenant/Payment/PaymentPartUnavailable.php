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

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Why this invoice goes out without a payment part (XIV-152).
 *
 * Not a failure and never treated as one: the invoice itself is fine, and
 * refusing to produce it because the profile lacks a postal code would hold a
 * customer's billing hostage to a setting. What the ticket does demand is that
 * the absence is *said*: an invoice quietly missing its payment part looks
 * identical to one that was never supposed to have it, and the difference is a
 * customer not getting paid. So this carries the sentence to show, and
 * InvoicePaymentPart decides where to say it.
 *
 * A sentence built here rather than a bare TranslatableMessage, because the
 * missing-fields case names *fields*, each with a label of its own in the
 * reader's language, a nested translation the message class cannot do alone.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PaymentPartUnavailable extends \RuntimeException
{
    /** The translation key of the sentence. */
    private string $key;

    /** @var array<string, string> parameters that go in as they are */
    private array $parameters = [];

    /** @var list<string> label keys to translate and join into %fields% */
    private array $missingLabelKeys = [];

    /** What to show whoever generated the document, in their language. */
    public function sentence(TranslatorInterface $translator): string
    {
        $parameters = $this->parameters;

        if ($this->missingLabelKeys !== []) {
            $parameters['%fields%'] = implode(', ', array_map(
                static fn (string $label): string => $translator->trans($label),
                $this->missingLabelKeys,
            ));
        }

        return $translator->trans($this->key, $parameters);
    }

    /**
     * The profile cannot yet say who is being paid.
     *
     * Carries every gap at once rather than the first one found, because the
     * person fixing this fills in a form: told one field at a time, they would
     * generate, fix, generate, fix, four round trips for four fields.
     *
     * @param list<string> $labelKeys translation keys of the profile fields
     *                                that are empty, e.g. `profile.payment_iban`
     */
    public static function missingProfileData(array $labelKeys): self
    {
        $unavailable = new self('The tenant profile is missing: ' . implode(', ', $labelKeys));
        $unavailable->key = 'payment_part.skipped_missing';
        $unavailable->missingLabelKeys = $labelKeys;

        return $unavailable;
    }

    /**
     * The standard allows CHF and EUR and nothing else, and an invalid QR is
     * the one thing worse than no QR (XIV-152's own bound). Null means the
     * installation has not chosen a currency at all, which reads as the same
     * sentence with "none yet" in the slot.
     */
    public static function unsupportedCurrency(?string $currency): self
    {
        $unavailable = new self(sprintf('The QR-bill supports CHF and EUR, not %s.', $currency ?? 'an unset currency'));
        $unavailable->key = $currency === null ? 'payment_part.skipped_no_currency' : 'payment_part.skipped_currency';
        $unavailable->parameters = $currency === null ? [] : ['%currency%' => $currency];

        return $unavailable;
    }

    /**
     * The invoice number cannot be turned into the configured reference: a
     * QRR wants digits and got none, or a customer's numbering pattern outgrew
     * the reference's length. Rare, and a fact about *their* numbering scheme,
     * so the sentence names the number that would not fit.
     */
    public static function numberUnusable(string $number, ReferenceType $type): self
    {
        $unavailable = new self(sprintf('"%s" cannot be turned into a %s reference.', $number, $type->value));
        $unavailable->key = 'payment_part.skipped_reference';
        $unavailable->parameters = ['%number%' => $number, '%type%' => $type->value];

        return $unavailable;
    }

    /**
     * The library's own validation said no, after everything this application
     * checks said yes. The last line of the "never an invalid QR" defence: a
     * payload sprain will not sign off on is a payload no payment part is made
     * from, whatever the reason turns out to be. The specifics go in the log
     * via the exception message; the person generating an invoice gets a
     * sentence they can forward, not a constraint-violation dump.
     */
    public static function refusedByLibrary(string $detail): self
    {
        $unavailable = new self('The QR-bill library refused the payload: ' . $detail);
        $unavailable->key = 'payment_part.skipped_invalid';

        return $unavailable;
    }
}

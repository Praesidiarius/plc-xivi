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

use App\Tenant\Payment\PaymentSettings;
use App\Tenant\Payment\PaymentSettingsRefused;
use App\Tenant\Payment\ReferenceType;
use PHPUnit\Framework\TestCase;

/**
 * What the profile page will and will not store as payment settings (XIV-152).
 *
 * These rules are the "before" half of the ticket's acceptance criterion: a
 * broken creditor account is refused where somebody is looking at the form,
 * not discovered on an invoice that already went out. Each refusal here is a
 * combination a bank would reject later, which is why none of them is a
 * warning.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PaymentSettingsTest extends TestCase
{
    private const string IBAN = 'CH9300762011623852957';
    private const string QR_IBAN = 'CH4431999123000889012';

    public function testAnIbanIsNormalisedTheWayEBankingDisplaysIt(): void
    {
        $settings = PaymentSettings::from('ch93 0076 2011 6238 5295 7', ReferenceType::Scor, '', '', '', '');

        self::assertSame(self::IBAN, $settings->iban, 'grouping spaces dropped, case folded up');
    }

    public function testAnEmptyIbanIsTheUnconfiguredStateAndNotARefusal(): void
    {
        $settings = PaymentSettings::from('', ReferenceType::Qrr, 'Musterstrasse', '7', '8000', 'Zürich');

        self::assertSame('', $settings->iban);
        self::assertSame(ReferenceType::Qrr, $settings->referenceType, 'the type is kept against the day the account arrives');
    }

    public function testSomethingThatIsNotAnIbanIsRefused(): void
    {
        $this->expectException(PaymentSettingsRefused::class);

        PaymentSettings::from('CH00NOTANIBAN', ReferenceType::Scor, '', '', '', '');
    }

    /**
     * Structurally valid and from the wrong country: a real account no QR-bill
     * can pay into. Its own refusal, because "not an IBAN" would be false.
     */
    public function testAGermanIbanIsRefusedAsNotAQrBillAccount(): void
    {
        try {
            PaymentSettings::from('DE89370400440532013000', ReferenceType::Scor, '', '', '', '');
            self::fail('a DE account cannot receive a QR-bill');
        } catch (PaymentSettingsRefused $refused) {
            self::assertStringContainsString('Liechtenstein', $refused->getMessage());
        }
    }

    public function testTheQrReferenceOnAnOrdinaryIbanIsRefused(): void
    {
        $this->expectException(PaymentSettingsRefused::class);

        PaymentSettings::from(self::IBAN, ReferenceType::Qrr, '', '', '', '');
    }

    public function testAQrIbanWithoutTheQrReferenceIsRefused(): void
    {
        $this->expectException(PaymentSettingsRefused::class);

        PaymentSettings::from(self::QR_IBAN, ReferenceType::Scor, '', '', '', '');
    }

    public function testTheMatchedPairsPass(): void
    {
        self::assertSame(
            ReferenceType::Qrr,
            PaymentSettings::from(self::QR_IBAN, ReferenceType::Qrr, '', '', '', '')->referenceType,
        );
        self::assertSame(
            ReferenceType::Non,
            PaymentSettings::from(self::IBAN, ReferenceType::Non, '', '', '', '')->referenceType,
        );
    }

    /**
     * The address is stored as typed, trimmed and nothing more: incompleteness
     * is the payment part's question, answered at generation time with a list
     * of what is missing, not the form's to refuse (see PaymentSettings).
     */
    public function testTheAddressIsTrimmedAndNeverRefused(): void
    {
        $settings = PaymentSettings::from(self::IBAN, ReferenceType::Scor, ' Musterstrasse ', ' 7 ', '', '');

        self::assertSame('Musterstrasse', $settings->street);
        self::assertSame('7', $settings->buildingNumber);
        self::assertSame('', $settings->postalCode);
    }
}

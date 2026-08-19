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

namespace Xivi\Core\Region;

/**
 * Which country this installation's data is about, asked for rather than known
 * (XIV-114).
 *
 * The answer lives in the tenant profile (§8.6), which is the application's and
 * not the engine's: core is handed a connection and never learns whose it is. So
 * core declares the question and the application answers it — the same seam
 * {@see \Xivi\Core\Money\InstanceCurrency} keeps for the currency,
 * {@see \Xivi\Core\Payment\DefaultPaymentTerms} for the payment terms and
 * {@see \Xivi\Core\Money\DefaultVatMode} for the VAT mode. **Not a fifth shape**:
 * one method and a null, deliberately, so whoever has read one of these has read
 * all of them.
 *
 * ### This is not a new setting, and that is the whole point
 *
 * [XIV-50] built the language-and-region chain and [XIV-83] extended its shape to
 * the timezone; §8.6 gives the installation a region. A field type that needed to
 * know which country a phone number was dialled in could have grown a fourth
 * variation on that — a country picker in the metadata editor, answered per
 * tenant, disagreeing with the profile the day somebody moved. This interface is
 * the opposite: a hole through which the answer that already exists arrives.
 *
 * ### Why the *installation's* region and not the reader's
 *
 * `FormattingLocale` walks person → installation → nothing, and this deliberately
 * starts one link down. The reader's own region is the right answer for how a
 * value is **shown** and the wrong one for how it is **stored**: a French
 * colleague at a Swiss company typing `079 123 45 67` into a customer record is
 * typing a Swiss number, and a chain that asked who was looking would store
 * `+33791234567` for them and `+41791234567` for everybody else — the same
 * digits becoming two different customers depending on whose screen they were
 * entered from. Storage has to be a function of the value, not of the session.
 *
 * Display is the other half and reads the locale that is already in force; see
 * {@see \Xivi\Core\Field\Type\PhoneFieldType::display()}.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface InstanceRegion
{
    /**
     * The ISO 3166-1 alpha-2 code, or null when nobody has chosen one.
     *
     * Null is a real answer and every caller has to have one. A console command
     * has no tenant and the login page may have none, and neither is an error —
     * the same condition `FormattingLocale::instanceRegion()` already handles by
     * falling through. What it means for a phone number is that only a value
     * carrying its own country code can be read at all, which is exactly as much
     * as anybody can honestly conclude from digits alone.
     */
    public function region(): ?string;
}

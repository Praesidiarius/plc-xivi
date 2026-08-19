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

namespace Xivi\Core\Phone;

/**
 * The four ways a phone number can be refused, and why they are four rather than
 * one (XIV-114).
 *
 * A single "that is not a phone number" would be true of all of them and useful
 * for none. What somebody has to do next is different in every case: one is a
 * typo, one is a setting nobody has filled in, one is digits that are the right
 * shape for nowhere, and one is a number that is fine except for the part this
 * field cannot keep. The messages are in
 * {@see DiallablePhoneNumber}, one per case, and each names the value and — where
 * the country is what decided it — the country it was read against.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum PhoneProblem
{
    /**
     * libphonenumber would not read it as a number at all: letters, three
     * digits, an empty pair of brackets.
     */
    case NotANumber;

    /**
     * No country code in the value, and no country known for the installation
     * (§8.6) or the field.
     *
     * The one refusal that is about the *installation* rather than about what
     * was typed, and the message says so: `079 123 45 67` is a perfectly good
     * number and there is genuinely no way to tell whose. Left as its own case
     * so that nobody is sent looking for a typo in a value that has not got one.
     */
    case NoCountry;

    /** Read as a number, and not one that can be dialled in that country. */
    case NotDiallable;

    /**
     * A real number with an extension on it — `+41 44 668 18 00 ext. 12`.
     *
     * Refused rather than kept, and the reason is arithmetic rather than taste:
     * E.164 has no room for an extension, so `format(E164)` **drops it
     * silently**. Storing it would mean the switchboard number and the twelve
     * people behind it all collapsing to one stored value — which, on a `unique`
     * field, refuses the twelfth colleague for a reason nobody could see, and on
     * an ordinary one loses the digits that made the record worth having. See
     * {@see \Xivi\Core\Field\Type\PhoneFieldType} for the decision and the two
     * alternatives it was weighed against.
     */
    case CarriesAnExtension;
}

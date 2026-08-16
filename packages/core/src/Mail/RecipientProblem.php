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

namespace Xivi\Core\Mail;

/**
 * Why there is nobody to send this record's mail to (XIV-39).
 *
 * A closed set rather than a message, for the reason every other refusal in this
 * codebase is: the sentence is the UI's and the translator's, and the *kind* of
 * problem is what the code has to decide with. The record page draws a send
 * button or an explanation depending on which of these came back, and it draws
 * nothing at all for {@see self::NotDeclared} — a module that never claimed to
 * send email is not a module with a problem.
 *
 * The sentences themselves are {@see Recipient::reason()}, because two of these
 * read differently depending on whether the address was expected on this record
 * or on the one it points at, and that is a fact about the resolution rather
 * than about the case.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum RecipientProblem: string
{
    /**
     * This module has not said where an address would come from — or the
     * customer's own definitions no longer have the field it named (§6.1).
     *
     * Not an error and not shown: an articles module has no customer to write
     * to, and a customer who deleted their email field has a shape that does not
     * send mail. Both are answers rather than faults.
     */
    case NotDeclared = 'not_declared';

    /** The reference the declaration hops through is empty: an invoice with no contact. */
    case NoLink = 'no_link';

    /**
     * The reference names a record that is not there any more.
     *
     * §7.6 allows exactly this: deleting a record others point at is allowed and
     * the link goes stale, because refusing would mean a contact can never be
     * deleted once anything named it. A stale link reads as `#id` everywhere
     * else; here it means there is no address, which is the same honesty.
     */
    case LinkGone = 'link_gone';

    /** The field is there and empty. Plenty of real contacts are a name and a phone number. */
    case NoAddress = 'no_address';

    /** Something is stored and it is not an address, so a send would bounce or throw. */
    case NotAnAddress = 'not_an_address';
}

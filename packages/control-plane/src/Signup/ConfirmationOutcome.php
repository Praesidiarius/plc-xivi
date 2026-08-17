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

namespace Xivi\ControlPlane\Signup;

/**
 * What happened when somebody followed a confirmation link (XIV-64).
 *
 * Five answers, and the interesting thing about the list is that only one of
 * them changes anything. A confirmation link is followed by a person, by that
 * person again a minute later because the page was still open, and — reliably,
 * in any company with a mail gateway — by a link scanner that fetched every URL
 * in the message before the human ever saw it. So "followed twice" is the
 * ordinary case rather than the attack, and the design has to be an idempotent
 * operation with a distinguishable second answer rather than a single-use token
 * with an error.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum ConfirmationOutcome
{
    /** This click is what confirmed it, and the name is now held. */
    case Confirmed;

    /**
     * It was already confirmed, and nothing happened.
     *
     * Not an error and not drawn as one. The reservation is untouched,
     * `confirmed_at` still says when the *first* click was, and no second mail
     * goes anywhere.
     */
    case AlreadyConfirmed;

    /**
     * The window closed with nobody answering.
     *
     * The row is still there and still holds the address — so signing up again
     * with the same address replaces it, which is exactly what the page tells
     * them to do.
     */
    case Expired;

    /**
     * No signup has this token.
     *
     * A token that never existed, a token superseded by a second submission, or
     * a signup that has since been provisioned and removed. **The three are
     * deliberately one answer**: distinguishing them would tell whoever is
     * holding the URL something about a row they cannot otherwise see, and the
     * only useful instruction is the same in all three cases.
     */
    case Unknown;

    /**
     * The name was free when they asked and is not free now.
     *
     * The narrow race the endpoint cannot design away: two people ask for `acme`
     * within a minute of each other, both are told it is available because
     * nothing is held until a confirmation, and the second one to click their
     * link finds the first has taken it. **That is the anti-squatting rule
     * costing somebody something, and it is the right side to take** — the
     * alternative is holding a name for an address that has proven nothing,
     * which is precisely how a name gets squatted. The page says the name is
     * gone and to sign up again with another; the row is left alone, so their
     * address is not stuck either.
     */
    case SlugTaken;
}

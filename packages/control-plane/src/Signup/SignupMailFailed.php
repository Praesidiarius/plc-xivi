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
 * A confirmation mail that could not be sent (XIV-64).
 *
 * Separate from `App\Tenant\Mail\MailSendFailed` rather than reusing it, and the
 * difference is the whole subject of {@see SignupMailer}: that class is about a
 * message sent *on a customer's behalf*, which is a question about whose SMTP
 * server and whose address. There is no customer here — that is what a signup
 * is — so none of the machinery on the other side applies and neither does its
 * exception.
 *
 * It is thrown rather than swallowed for the reason §8.7 gives about mail
 * generally: a send that failed silently is somebody waiting for a message that
 * is not coming, and the endpoint has to be able to say so.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class SignupMailFailed extends \RuntimeException
{
    public static function because(\Throwable $failure): self
    {
        return new self('The signup confirmation could not be sent: ' . $failure->getMessage(), 0, $failure);
    }

    /**
     * Neither `MAILER_SENDER` nor `SIGNUP_HOST` has a value.
     *
     * Unreachable in practice and kept anyway, which is worth a sentence.
     * {@see SignupMailer::senderAddress()} falls back to `no-reply@` at the
     * signup host, and an empty signup host means no route was ever registered —
     * so nothing can have called the mailer. What this covers is somebody
     * constructing the service by hand later and finding out immediately rather
     * than sending a message from `no-reply@`.
     */
    public static function noSenderAddress(): self
    {
        return new self(
            'Neither MAILER_SENDER nor SIGNUP_HOST has a value, so there is no address a signup '
            . 'confirmation could truthfully come from.',
        );
    }
}

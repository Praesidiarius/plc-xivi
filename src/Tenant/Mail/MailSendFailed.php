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

namespace App\Tenant\Mail;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A send that did not happen (XIV-37).
 *
 * **Nothing catches this quietly.** A document that fails to generate wastes
 * somebody's minute; an email is outbound and irreversible, and a send that
 * failed silently is a customer sitting there believing their invoice went out.
 * So every failure inside TenantMailer arrives here and is thrown on: the
 * person who pressed the button is told, and XIV-39 writes the attempt to the
 * record's timeline as a failure rather than leaving a gap that looks the same
 * as never having tried.
 *
 * One type for every cause on purpose — a refused transport, a server that will
 * not authenticate, a connection that times out — because the caller's decision
 * is the same in all three cases and `getPrevious()` still carries which it was.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MailSendFailed extends \RuntimeException
{
    private TranslatableMessage $translatable;

    /**
     * What to show the person who pressed the button, in their language (XIV-8).
     *
     * Deliberately not the underlying message: an SMTP rejection is written for
     * an administrator and may quote a host, a mailbox or a policy URL. The
     * exception's own message keeps all of it for the log.
     */
    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    public static function because(\Throwable $cause): self
    {
        $failure = new self(
            sprintf('The message could not be sent: %s', $cause->getMessage()),
            previous: $cause,
        );
        $failure->translatable = new TranslatableMessage('mail.send_failed', [], 'messages');

        return $failure;
    }

    /**
     * No usable sender could be worked out at all — the instance has named no
     * address of its own and the tenant no domain to fall back to, which is a
     * deployment that is not finished rather than a mail that bounced.
     */
    public static function noSenderAddress(string $slug): self
    {
        $failure = new self(sprintf(
            'No address to send as for tenant "%s": set a sender address in the company profile, or give the '
            . 'instance one in MAILER_SENDER.',
            $slug,
        ));
        $failure->translatable = new TranslatableMessage('mail.no_sender', [], 'messages');

        return $failure;
    }
}

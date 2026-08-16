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
 * Mail settings the application will not store (XIV-37).
 *
 * Refused at the point of saving rather than discovered at the point of sending,
 * which is the same call UserChangeRefused makes and for a sharper reason here:
 * the thing that would go wrong is an outbound message, and by the time it goes
 * wrong it has already gone.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MailSettingsRefused extends \RuntimeException
{
    private TranslatableMessage $translatable;

    /** What to show the person who caused it, in their language (XIV-8). */
    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    /**
     * Their server may claim their address and not ours (§8.7), so a server
     * without an address to go with it is a configuration that can only send
     * mail their own provider is entitled to reject.
     */
    public static function serverWithoutAnAddress(): self
    {
        $refusal = new self('An SMTP server needs a sender address to send as.');
        $refusal->translatable = new TranslatableMessage('refusal.mail_server_without_address', [], 'messages');

        return $refusal;
    }

    public static function notAnAddress(string $address): self
    {
        $refusal = new self(sprintf('"%s" is not an email address.', $address));
        $refusal->translatable = new TranslatableMessage(
            'refusal.mail_not_an_address',
            ['%address%' => $address],
            'messages',
        );

        return $refusal;
    }
}

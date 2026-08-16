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

use Symfony\Component\Mime\Address;

/**
 * Whose name and address a tenant's mail goes out under (XIV-37, §8.7).
 *
 * A value rather than two loose addresses, because the two are one decision: a
 * `Reply-To` exists precisely when the `From` is not the customer's own address,
 * and a caller free to set one without the other could produce a mail that looks
 * like it came from the customer and answers to us.
 *
 * XIV-39 wants to *show* this before anything is sent — a preview that does not
 * say who the mail will appear to be from is not a preview of the thing being
 * sent — which is why it is a value it can render rather than something buried
 * inside the send.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class SenderIdentity
{
    /**
     * @param Address      $from        what the recipient sees the mail came from
     * @param Address|null $replyTo     where an answer should go instead, when that is not $from
     * @param bool         $ownProvider whether it leaves through the customer's own SMTP server
     */
    public function __construct(
        public Address $from,
        public ?Address $replyTo,
        public bool $ownProvider,
    ) {
    }
}

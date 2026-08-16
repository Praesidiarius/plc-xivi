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

namespace App\Mail;

use Symfony\Component\Mailer\Exception\ExceptionInterface;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * A transport that could have reached a real mail server, asked for outside
 * production (XIV-37).
 *
 * Thrown where the transport would be *built*, not where a message would be
 * sent, which is the whole point: nothing that could deliver ever comes into
 * existence in dev or test, so there is no object left for a later bug to hand a
 * message to. See NonProductionMailGuard for why that is the seam.
 *
 * It implements Symfony's own mailer ExceptionInterface so that callers already
 * catching mailer failures catch this too rather than seeing it escape as
 * something unrelated.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RealMailRefused extends \RuntimeException implements ExceptionInterface
{
    public static function transport(Dsn $dsn, string $environment): self
    {
        // The DSN's user and password are deliberately not repeated here: this
        // message goes to a log and, through MailSendFailed, to a screen, and a
        // tenant's SMTP credential has no business on either. Scheme and host
        // are enough to recognise which configuration was at fault.
        return new self(sprintf(
            'Refusing to build a "%s" transport to "%s" in the "%s" environment: only production may reach a '
            . 'real mail server. Point the transport at a catcher, or leave it on "null://null" (XIV-37).',
            $dsn->getScheme(),
            $dsn->getHost(),
            $environment,
        ));
    }
}

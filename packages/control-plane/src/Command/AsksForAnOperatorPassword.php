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

namespace Xivi\ControlPlane\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Entity\Operator;

/**
 * The one way a control-plane password is typed at a console (XIV-57, XIV-92).
 *
 * Extracted when {@see ChangeOperatorPasswordCommand} arrived and needed exactly
 * what {@see CreateOperatorCommand} had been doing since XIV-57. The duplication
 * would have been about twenty lines, which is small enough to copy — and the
 * argument against copying it is not length. Both halves of what this does are
 * security decisions with a paragraph behind them, and two copies of a decision
 * drift by having one of them improved.
 *
 * **Asked for, never generated.** §8.5 generates a tenant administrator's first
 * password and prints it once, which is safe there only because
 * `must_change_password` holds the account on `/account` until its owner
 * replaces it. The control plane has no account page to hold anybody on, so a
 * generated password here would be a credential two people know and nothing ever
 * makes them rotate. Since the person at this console is either the operator
 * being created or the one rotating a leaked credential, asking is both simpler
 * and stronger than printing.
 *
 * **An unattended run without `--password` is refused rather than filled in.**
 * `--no-interaction` answers an unanswered question with its default, and a
 * default is not a password anybody owns.
 *
 * A trait rather than a service, deliberately: Symfony's PSR-4 service loader
 * skips traits, so this stays out of the container instead of becoming a
 * one-method object that two commands have to be handed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
trait AsksForAnOperatorPassword
{
    /**
     * The password to use, or null when the run cannot produce one — in which
     * case this has already said why and the caller returns `FAILURE`.
     *
     * @param string|null $given whatever `--password` carried, if anything
     */
    private function resolvePassword(
        SymfonyStyle $io,
        InputInterface $input,
        #[\SensitiveParameter] ?string $given,
    ): ?string {
        if ($given !== null) {
            return $given;
        }

        if (!$input->isInteractive()) {
            $io->error(
                'No password given and nothing to ask. Pass --password for an unattended run; '
                . 'nothing is generated here, because a control-plane password nobody typed is '
                . 'one nobody owns.',
            );

            return null;
        }

        return $this->askForPassword($io);
    }

    /**
     * Twice, hidden, and compared — the shape every password prompt has, for the
     * reason every password prompt has it: a typo in something the terminal does
     * not echo is otherwise discovered at the sign-in page with no way to tell it
     * apart from a forgotten password.
     *
     * Returns null when the two do not agree, and the caller fails; asking again
     * in a loop would be friendlier and would also make a scripted mistake
     * impossible to notice.
     */
    private function askForPassword(SymfonyStyle $io): ?string
    {
        $password = (string) $io->askHidden(sprintf(
            'Password (at least %d characters)',
            Operator::MINIMUM_PASSWORD_LENGTH,
        ));

        if ((string) $io->askHidden('Again') !== $password) {
            $io->error('The two passwords do not match.');

            return null;
        }

        return $password;
    }
}

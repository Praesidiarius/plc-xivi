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

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Security\OperatorChangeRefused;
use Xivi\ControlPlane\Security\OperatorManager;

/**
 * Rotate an operator's password (XIV-92).
 *
 * **Until this existed, a control-plane password could not be changed at all.**
 * Not from the console, and not from the web either: there is no `/account` on
 * the control plane and §8.9 leaned on exactly that absence when it decided
 * `control:operator:create` should *ask* for a password rather than print a
 * generated one — a generated credential with no way to rotate it is one two
 * people know for ever. That argument was sound and it left the installation
 * with no rotation route of any kind, which is the shape of gap that is only
 * noticed on the day a password has leaked.
 *
 * **It does not ask for the current password**, which is the one thing that
 * would look like a missing check. `UserManager::changeOwnPassword()` demands it
 * because an unattended browser is otherwise enough to take a tenant account
 * over; there is no browser here. Whoever runs this is at a console on the
 * machine the installation runs on, holding an entity manager pointed at the
 * control-plane database — a proof of identity in front of that is theatre, and
 * it would make the command useless in the case it mostly exists for, where the
 * password has leaked and its owner is not the one typing.
 *
 * **It works on a revoked account, and says so rather than refusing.** Rotating
 * a leaked credential and withdrawing an account are independent, and somebody
 * may want them in either order; refusing here would mean the only route to a
 * rotation is to restore access first, briefly re-admitting the person whose
 * credential leaked. What the command must not do is let that read as a
 * reinstatement, so it prints the account's state either way.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'control:operator:password',
    description: 'Set a new password for an operator',
)]
final readonly class ChangeOperatorPasswordCommand
{
    use AsksForAnOperatorPassword;

    public function __construct(private OperatorManager $operators)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'The operator\'s email address')]
        string $email,
        #[Option(description: 'The new password, for scripted runs; you are asked for it otherwise')]
        #[\SensitiveParameter]
        ?string $password = null,
    ): int {
        // The address is resolved *before* anybody is asked to type a password
        // twice. The other order is one line shorter and makes a typo in the
        // address cost two hidden prompts before it is reported.
        try {
            $operator = $this->operators->byEmail($email);
        } catch (OperatorChangeRefused $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $password = $this->resolvePassword($io, $input, $password);

        if ($password === null) {
            return Command::FAILURE;
        }

        try {
            $this->operators->changePassword($operator, $password);
        } catch (OperatorChangeRefused $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Operator "%s" has a new password.', $operator->getEmail()));

        // **Every session that account had is already over**, and it is worth a
        // line because it is the opposite of how revocation behaves. Symfony's
        // `ContextListener` compares the stored password hash against the
        // reloaded one when it restores a session, so writing a new hash
        // invalidates every live session for this operator with no listener of
        // ours involved. Somebody rotating a leaked credential is asking exactly
        // this question.
        $io->writeln(' Every session this account had is now signed out.');

        if (!$operator->isActive()) {
            $io->warning(
                'This account is still revoked, so the new password does not let anybody in. '
                . 'Use "control:operator:restore" if that was not the intention.',
            );
        }

        $io->newLine();

        return Command::SUCCESS;
    }
}

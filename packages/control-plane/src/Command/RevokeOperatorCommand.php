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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Security\OperatorChangeRefused;
use Xivi\ControlPlane\Security\OperatorManager;

/**
 * Take away an operator's access to this installation (XIV-92).
 *
 * The command §8.9 named as missing and called a gap rather than a decision.
 * Until it existed, withdrawing the identity with the most reach in the
 * installation meant a `DELETE` typed into `psql` — which is §4.1's failure
 * exactly, and it lands harder here than it does for tenants, because revoking
 * an operator is done in a hurry by construction. Somebody has left, or a
 * credential has leaked, and that is not the moment to be composing SQL against
 * a table whose name you are checking as you type it.
 *
 * **It deactivates rather than deletes**, and §8.9 argues that out at length.
 * The one-line version: deletion is the only lifecycle step nobody can undo, and
 * this is the step most likely to be taken at speed against an address somebody
 * has half-read off a chat message.
 *
 * **No confirmation prompt, unlike `tenant:deprovision`.** That command asks
 * because what it does cannot be undone and takes a customer's database with it;
 * this one is reversed by {@see RestoreOperatorCommand} in one line, and a
 * confirmation on a reversible act is a keystroke people learn to type without
 * reading — which is how they come to type it on the command that is not
 * reversible. What is guarded instead is the outcome that *is* irreversible in
 * practice, which is nobody being able to sign in at all; see
 * {@see OperatorManager::revoke()} for why that count is of active operators
 * rather than of rows.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'control:operator:revoke',
    description: 'Withdraw an operator\'s access, keeping the account',
)]
final readonly class RevokeOperatorCommand
{
    public function __construct(private OperatorManager $operators)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The operator\'s email address')]
        string $email,
    ): int {
        try {
            $operator = $this->operators->byEmail($email);
            $this->operators->revoke($operator);
        } catch (OperatorChangeRefused $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Operator "%s" can no longer sign in.', $operator->getEmail()));

        // **What this did *not* do, said out loud.** The account is still in the
        // list and still holds its password hash, and — the part worth a line on
        // the terminal — a session that operator already had is ended on their
        // next request rather than the moment this command returns. That is a
        // property of RevokedOperatorListener running at the front of a request,
        // and it is one request's worth of difference; somebody revoking a
        // credential during an incident should know it rather than infer it.
        $io->writeln(' The account is kept and can be restored with <info>control:operator:restore</info>.');
        $io->writeln(' Any session they already had ends on their next request.');
        $io->newLine();

        return Command::SUCCESS;
    }
}

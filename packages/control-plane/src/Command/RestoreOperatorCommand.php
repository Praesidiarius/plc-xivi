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
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorChangeRefused;
use Xivi\ControlPlane\Security\OperatorManager;

/**
 * Give a revoked operator their access back (XIV-92).
 *
 * **This is what makes "reversible" a fact rather than a claim.** §8.9 chooses
 * deactivation over deletion partly on the grounds that a revocation typed
 * against the wrong address can be undone — and a property with no route to it
 * is exactly the gap §4.1 is about, one step further along. Without this
 * command, undoing a mis-typed revocation would be `UPDATE operator SET
 * active = true`, which is the `psql` the whole ticket exists to remove.
 *
 * **Its own command rather than `control:operator:revoke --restore`.** A flag
 * that reverses the verb it is attached to reads as a contradiction at the one
 * moment somebody is studying `--help` carefully, and the two are not variants
 * of one act: one is done in a hurry and one is done after thinking about it.
 *
 * **It restores access, not a password.** Whatever hash the row carried before
 * the revocation is the hash it carries after, which is right for the ordinary
 * case — a colleague who was revoked while on leave — and is the wrong answer
 * for the case where the credential is *why* they were revoked. So the output
 * says so and names `control:operator:password`, rather than this command
 * quietly deciding which of those two situations it is in.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'control:operator:restore',
    description: 'Give a revoked operator their access back',
)]
final readonly class RestoreOperatorCommand
{
    public function __construct(
        private OperatorManager $operators,
        private ControlPlaneHost $controlPlane,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The operator\'s email address')]
        string $email,
    ): int {
        try {
            $operator = $this->operators->byEmail($email);
            $this->operators->restore($operator);
        } catch (OperatorChangeRefused $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Operator "%s" can sign in again.', $operator->getEmail()));

        $io->writeln(sprintf(
            ' Their old password still works. Set a new one with <info>control:operator:password %s</info>'
            . ' if that is why they were revoked.',
            $operator->getEmail(),
        ));

        // The same line `control:operator:create` prints, off the same
        // parameter the firewall matches on, so neither command can name an
        // address the application does not answer.
        $io->writeln(sprintf(
            ' Sign in at <info>https://%s%s/login</info>',
            $this->controlPlane->normalisedHost(),
            ControlPlaneHost::PATH_PREFIX,
        ));
        $io->newLine();

        return Command::SUCCESS;
    }
}

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
use Xivi\ControlPlane\Security\ControlPlaneHost;
use Xivi\ControlPlane\Security\OperatorAlreadyExists;
use Xivi\ControlPlane\Security\OperatorCreator;

/**
 * The only way an operator comes into existence (XIV-57).
 *
 * **The password is asked for rather than generated, which is a departure from
 * §8.5 and is deliberate.** A tenant admin created by `tenant:provision` gets a
 * generated password printed once, and that is safe only because of what comes
 * with it: `must_change_password` holds the account on `/account` until the
 * owner replaces it, because a password read off a screen and passed along by
 * chat or telephone is a way in rather than a credential. The control plane has
 * no account page to hold anybody on — [XIV-58] is the first screen it will have
 * at all — so a generated password here would be a credential two people know
 * and nothing ever makes them rotate. Since the person running this command is,
 * at the moment it matters, the operator being created, asking them is both
 * simpler and stronger than printing at them.
 *
 * `--password` exists for the test suite and for anything scripting this. An
 * unattended run without it is refused rather than silently generating
 * something, because a value nobody typed and nobody read is a credential nobody
 * owns. Both halves of that now live in {@see AsksForAnOperatorPassword},
 * shared with `control:operator:password`.
 *
 * **An address that already has an operator is an error, and stays one**
 * (XIV-92). Making this command double as a password change is the convenient
 * reading — one verb, no second command to remember — and it was rejected once
 * `control:operator:password` existed to do the job properly. Two reasons, and
 * the second is the one that decides it:
 *
 *   * A typo in an address would then be indistinguishable from a rotation. Type
 *     `alice@exmaple.com` when the account is `alice@example.com` and an
 *     overloaded `create` reports success either way — in the one case having
 *     changed a password, in the other having minted a *second* identity with
 *     the reach of the first, at an address nobody owns. A refusal is what turns
 *     that into a sentence on the terminal.
 *   * It would silently reinstate a revoked account. `create` on an address that
 *     was revoked yesterday would write a working password onto a row whose
 *     whole point is that it no longer works, which is a revocation undone by a
 *     command that never mentions revocation. So the refusal says which of the
 *     two situations it is, and names the command for each.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'control:operator:create',
    description: 'Create somebody who can sign in to the control plane',
)]
final readonly class CreateOperatorCommand
{
    use AsksForAnOperatorPassword;

    public function __construct(
        private OperatorCreator $operators,
        private ControlPlaneHost $controlPlane,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'Email address, which is also the login')]
        string $email,
        #[Option(description: 'Display name; defaults to the email')]
        ?string $name = null,
        #[Option(description: 'The password, for scripted runs; you are asked for it otherwise')]
        #[\SensitiveParameter]
        ?string $password = null,
    ): int {
        $password = $this->resolvePassword($io, $input, $password);

        if ($password === null) {
            return Command::FAILURE;
        }

        try {
            $operator = $this->operators->create($email, $name ?? $email, $password);
        } catch (OperatorAlreadyExists|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Operator "%s" created.', $operator->getEmail()));

        // Where to go and use it. The host is read from the same parameter the
        // firewall and the tenancy listener read, so this line cannot tell
        // somebody to visit an address the application does not answer on.
        $io->writeln(sprintf(
            ' Sign in at <info>https://%s%s/login</info>',
            $this->controlPlane->normalisedHost(),
            ControlPlaneHost::PATH_PREFIX,
        ));
        $io->newLine();

        return Command::SUCCESS;
    }
}

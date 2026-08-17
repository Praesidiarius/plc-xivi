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
use Xivi\ControlPlane\Entity\Operator;
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
 * owns.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'control:operator:create',
    description: 'Create somebody who can sign in to the control plane',
)]
final readonly class CreateOperatorCommand
{
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
        if ($password === null) {
            if (!$input->isInteractive()) {
                $io->error(
                    'No password given and nothing to ask. Pass --password for an unattended run; '
                    . 'nothing is generated here, because a control-plane password nobody typed is '
                    . 'one nobody owns.',
                );

                return Command::FAILURE;
            }

            $password = $this->askForPassword($io);

            if ($password === null) {
                return Command::FAILURE;
            }
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

    /**
     * Twice, hidden, and compared — the shape every password prompt has, for the
     * reason every password prompt has it: a typo in something the terminal does
     * not echo is otherwise discovered at the sign-in page with no way to tell it
     * from a forgotten password.
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

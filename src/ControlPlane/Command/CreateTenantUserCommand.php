<?php

declare(strict_types=1);

namespace App\ControlPlane\Command;

use App\ControlPlane\Repository\TenantRepository;
use App\Tenant\Security\UserAlreadyExists;
use App\Tenant\Security\UserCreator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tenant:user:create',
    description: "Create a user in one tenant's database",
)]
final readonly class CreateTenantUserCommand
{
    public function __construct(
        private TenantRepository $tenants,
        private UserCreator $users,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Tenant slug')]
        string $tenant,
        #[Argument(description: 'Email address, which is also the login')]
        string $email,
        #[Option(description: 'Display name; defaults to the email')]
        ?string $name = null,
        #[Option(description: 'Grant ROLE_ADMIN')]
        bool $admin = false,
    ): int {
        $found = $this->tenants->findOneBySlug($tenant);

        if ($found === null) {
            $io->error(sprintf('No tenant with slug "%s".', $tenant));

            return Command::FAILURE;
        }

        try {
            $password = $this->users->create(
                $found,
                $email,
                $name ?? $email,
                roles: $admin ? ['ROLE_ADMIN'] : [],
            );
        } catch (UserAlreadyExists $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Created %s in tenant "%s".', $email, $found->getSlug()));
        self::writePassword($io, $password);

        return Command::SUCCESS;
    }

    /**
     * The one credential in this system that a person has to read: it cannot be
     * delivered any other way until there is a mailer. Shown once, never stored
     * in plaintext, and worth changing immediately.
     */
    public static function writePassword(SymfonyStyle $io, string $password): void
    {
        $io->writeln(sprintf(' Password: <info>%s</info>', $password));
        $io->writeln(' <comment>Shown once. It is stored only as a hash — change it after signing in.</comment>');
        $io->newLine();
    }
}

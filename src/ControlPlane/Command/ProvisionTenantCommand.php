<?php

declare(strict_types=1);

namespace App\ControlPlane\Command;

use App\ControlPlane\Entity\TenantStatus;
use App\ControlPlane\Provisioning\ProvisioningFailed;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenant\Security\UserAlreadyExists;
use App\Tenant\Security\UserCreator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'tenant:provision',
    description: 'Create a tenant: control-plane row, database and schema',
)]
final readonly class ProvisionTenantCommand
{
    public function __construct(
        private TenantProvisioner $provisioner,
        private TenantDsnParser $dsnParser,
        private UserCreator $users,
    ) {
    }

    /**
     * @param list<string> $hostnames
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Machine identifier, also the database name suffix (e.g. "acme")')]
        string $slug,
        #[Argument(description: 'Hostnames routed to this tenant; the first one is primary')]
        array $hostnames,
        #[Option(description: 'Display name; defaults to the slug')]
        ?string $name = null,
        #[Option(description: 'Explicit DSN (role and database, no password); defaults to TENANT_DSN_TEMPLATE')]
        ?string $dsn = null,
        #[Option(description: 'Billing plan')]
        string $plan = 'standard',
        #[Option(description: 'Status once provisioned', name: 'status')]
        TenantStatus $status = TenantStatus::Active,
        #[Option(description: 'Email of the first admin user; a password is generated and shown once')]
        ?string $adminEmail = null,
        #[Option(description: 'Display name for that admin; defaults to the email')]
        ?string $adminName = null,
    ): int {
        try {
            $tenant = $this->provisioner->provision(
                slug: $slug,
                name: $name ?? $slug,
                hostnames: $hostnames,
                dsn: $dsn,
                plan: $plan,
                status: $status,
            );
        } catch (ProvisioningFailed $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Tenant "%s" provisioned.', $tenant->getSlug()));
        $io->definitionList(
            ['Status' => $tenant->getStatus()->value],
            ['Plan' => $tenant->getPlan()],
            ['Domains' => implode(', ', $tenant->getDomains()->map(static fn ($d) => $d->getHostname())->toArray())],
            // Never the DSN itself: it would put a credential into shell history,
            // CI logs and screenshots. The generated password is written to the
            // control plane encrypted and is not displayed anywhere.
            ['Database' => $this->dsnParser->databaseName($tenant->getDatabaseDsn())],
            ['Role' => $this->dsnParser->userName($tenant->getDatabaseDsn()) ?? '(none)'],
        );

        if ($adminEmail !== null) {
            try {
                $password = $this->users->create(
                    $tenant,
                    $adminEmail,
                    $adminName ?? $adminEmail,
                    roles: ['ROLE_ADMIN'],
                );
            } catch (UserAlreadyExists $e) {
                // The tenant itself is fine; only the user step failed.
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            $io->writeln(sprintf(' Admin: <info>%s</info>', $adminEmail));
            CreateTenantUserCommand::writePassword($io, $password);
        } else {
            $io->note(sprintf(
                'No users yet. Create one with: tenant:user:create %s <email> --admin',
                $tenant->getSlug(),
            ));
        }

        return Command::SUCCESS;
    }
}

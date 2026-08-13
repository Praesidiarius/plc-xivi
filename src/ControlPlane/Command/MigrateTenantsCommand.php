<?php

declare(strict_types=1);

namespace App\ControlPlane\Command;

use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Every schema change lands for every tenant (docs/architecture.md §4), so a deploy is not
 * finished until this has run across the whole registry.
 */
#[AsCommand(name: 'tenant:migrate', description: 'Migrate tenant databases to the latest version')]
final readonly class MigrateTenantsCommand
{
    public function __construct(
        private TenantRepository $tenants,
        private TenantProvisioner $provisioner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Migrate only this tenant')]
        ?string $slug = null,
    ): int {
        $tenants = $slug !== null
            ? array_filter([$this->tenants->findOneBySlug($slug)])
            : $this->tenants->findAllOrdered();

        if ($tenants === []) {
            $io->error($slug !== null ? sprintf('No tenant with slug "%s".', $slug) : 'No tenants to migrate.');

            return Command::FAILURE;
        }

        $failed = [];
        foreach ($tenants as $tenant) {
            try {
                $executed = $this->provisioner->migrate($tenant);
                $io->writeln(sprintf(
                    '<info>%s</info>: %s',
                    $tenant->getSlug(),
                    $executed === [] ? 'already up to date' : sprintf('%d migration(s) applied', \count($executed)),
                ));
            } catch (\Throwable $e) {
                // One tenant's failure must not stop the rest: leaving the
                // remaining tenants un-migrated after a deploy is worse.
                $failed[$tenant->getSlug()] = $e->getMessage();
                $io->writeln(sprintf('<error>%s</error>: %s', $tenant->getSlug(), $e->getMessage()));
            }
        }

        if ($failed !== []) {
            $io->error(sprintf('%d of %d tenants failed to migrate.', \count($failed), \count($tenants)));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d tenant(s) up to date.', \count($tenants)));

        return Command::SUCCESS;
    }
}

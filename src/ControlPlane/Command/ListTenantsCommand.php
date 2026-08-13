<?php

declare(strict_types=1);

namespace App\ControlPlane\Command;

use App\ControlPlane\Repository\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'tenant:list', description: 'List the tenants in the control plane')]
final readonly class ListTenantsCommand
{
    public function __construct(private TenantRepository $tenants)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->tenants->findAllOrdered() as $tenant) {
            $rows[] = [
                $tenant->getSlug(),
                $tenant->getName(),
                $tenant->getStatus()->value,
                implode(', ', $tenant->getDomains()->map(static fn ($d) => $d->getHostname())->toArray()),
                $tenant->getPlan(),
            ];
        }

        if ($rows === []) {
            $io->warning('No tenants provisioned yet. Create one with "bin/console tenant:provision".');

            return Command::SUCCESS;
        }

        $io->table(['Slug', 'Name', 'Status', 'Domains', 'Plan'], $rows);

        return Command::SUCCESS;
    }
}

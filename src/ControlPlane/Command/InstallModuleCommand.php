<?php

declare(strict_types=1);

namespace App\ControlPlane\Command;

use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleRegistry;

/**
 * Installs a module for one customer: its table and its field definitions, in
 * that customer's own database.
 *
 * Per tenant rather than per deploy, because "does this customer have this
 * module" is a runtime question (docs/architecture.md §3).
 */
#[AsCommand(
    name: 'tenant:module:install',
    description: "Install a module into one tenant's database",
)]
final readonly class InstallModuleCommand
{
    public function __construct(
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
        private ModuleRegistry $modules,
        private ModuleInstaller $installer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Tenant slug')]
        string $tenant,
        #[Argument(description: 'Module key, e.g. "contact"')]
        string $module,
    ): int {
        $found = $this->tenants->findOneBySlug($tenant);

        if ($found === null) {
            $io->error(sprintf('No tenant with slug "%s".', $tenant));

            return Command::FAILURE;
        }

        if (!$this->modules->has($module)) {
            $io->error(sprintf(
                'No module "%s" in this build. Available: %s.',
                $module,
                implode(', ', array_keys($this->modules->all())) ?: 'none',
            ));

            return Command::FAILURE;
        }

        $blueprint = $this->modules->get($module);

        try {
            $definition = $this->switcher->runFor($found, fn () => $this->installer->install($blueprint));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Module "%s" is installed for tenant "%s".', $module, $found->getSlug()));
        $io->definitionList(
            ['Table' => $definition->getTableName()],
            ['Fields' => implode(', ', $definition->getFieldKeys())],
        );

        return Command::SUCCESS;
    }
}

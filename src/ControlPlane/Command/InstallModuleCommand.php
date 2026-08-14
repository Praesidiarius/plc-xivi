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

namespace App\ControlPlane\Command;

use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModulePreset;
use Xivi\Core\Module\ModuleRegistry;

/**
 * Installs a module for one customer: its table and its field definitions, in
 * that customer's own database.
 *
 * Per tenant rather than per deploy, because "does this customer have this
 * module" is a runtime question (docs/architecture.md §3).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
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
        private MetadataRepository $metadata,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Tenant slug')]
        string $tenant,
        #[Argument(description: 'Module key, e.g. "contact"')]
        string $module,
        #[Option(description: 'Named field set to install with; the module decides the default')]
        ?string $preset = null,
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

        // Listed rather than left to be guessed: a preset is a choice somebody
        // makes once and lives with, since nothing retro-fits it afterwards (§6.1).
        if ($preset === null && $blueprint->presets !== []) {
            $io->text(sprintf('Presets for "%s" (installing "%s"):', $module, (string) $blueprint->defaultPreset));
            $io->listing(array_map(
                static fn (ModulePreset $p): string => sprintf('%s — %s', $p->key, $p->description),
                $blueprint->presets,
            ));
        }

        try {
            [$definition, $wasInstalled] = $this->switcher->runFor($found, function () use ($blueprint, $preset): array {
                // Asked before installing, because install() is idempotent and
                // hands back what is already there. Without this the summary would
                // report the preset it *would* have used on a run that did
                // nothing, which is a confident lie.
                $already = $this->metadata->find($blueprint->key) !== null;

                return [$this->installer->install($blueprint, $preset), !$already];
            });
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$wasInstalled) {
            $io->warning(sprintf(
                'Tenant "%s" already has "%s". Nothing changed — a preset only ever seeds a new '
                . 'installation (docs/architecture.md §6.1).',
                $found->getSlug(),
                $module,
            ));
        } else {
            $io->success(sprintf('Module "%s" is installed for tenant "%s".', $module, $found->getSlug()));
        }

        $rows = [['Table' => $definition->getTableName()]];

        // Only when it applied to anything. On a run that installed nothing, the
        // preset is not a fact about this tenant.
        if ($wasInstalled) {
            $rows[] = ['Preset' => $preset ?? $blueprint->defaultPreset ?? 'every field'];
        }

        $rows[] = ['Fields' => implode(', ', $definition->getFieldKeys())];
        $rows[] = ['Collections' => implode(', ', $definition->getCollectionKeys()) ?: 'none'];

        $io->definitionList(...$rows);

        return Command::SUCCESS;
    }
}

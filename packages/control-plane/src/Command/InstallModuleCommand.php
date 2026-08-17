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

use App\Registry\Catalog\ModuleCatalog;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\FollowUp\ModuleFollowUps;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModulePreset;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Module\ModuleRequirementMissing;

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
        private ModuleCatalog $catalog,
        private ModuleFollowUps $followUps,
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
        #[Option(description: 'Language to seed the labels in, e.g. "de"; the application default otherwise')]
        ?string $locale = null,
        /*
         * Off by default, because follow-ups are on by default (XIV-80). Phrased
         * as the negative for that reason and no other: `--follow-ups=false` on a
         * flag that is already true is a sentence nobody types correctly, and the
         * store's checkbox asks the same question the same way round.
         *
         * Unlike `--preset`, this decides nothing permanent. It is a boolean on
         * the module definition and there is no schema behind it, so a tenant
         * installed with it can be turned round later.
         */
        #[Option(description: 'Install without follow-ups on this module; they can be turned back on later')]
        bool $noFollowUps = false,
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
            [$definition, $wasInstalled] = $this->switcher->runFor($found, function () use ($blueprint, $preset, $locale, $noFollowUps): array {
                // Asked before installing, because install() is idempotent and
                // hands back what is already there. Without this the summary would
                // report the preset it *would* have used on a run that did
                // nothing, which is a confident lie.
                $already = $this->metadata->find($blueprint->key) !== null;
                $definition = $this->installer->install($blueprint, $preset, $locale);

                // Only on a run that actually installed something. Re-running the
                // command against a module the customer already has changes
                // nothing else about it (§6.1), and quietly switching a feature
                // off underneath them would be the one exception — from a command
                // whose whole message in that case is "nothing changed".
                if ($noFollowUps && !$already) {
                    $this->followUps->set($definition, false);
                }

                return [$definition, !$already];
            });
        } catch (ModuleRequirementMissing $e) {
            // Its own catch, because this one is worth acting on rather than
            // merely reading: it names the module to install first (XIV-23).
            $io->error($e->getMessage());
            $io->note(sprintf('bin/console tenant:module:install %s <module>', $found->getSlug()));

            return Command::FAILURE;
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

        // Named, never enforced (XIV-7): a module is developed by installing it
        // somewhere, so refusing the very case the state exists to describe would
        // be backwards. Saying it out loud is enough.
        $state = $this->catalog->state($module);

        $rows = [
            ['State' => $state->isOfferedInStore()
                ? $state->value
                : sprintf('%s — not offered in the store', $state->value)],
            ['Table' => $definition->getTableName()],
        ];

        // Only when it applied to anything. On a run that installed nothing, the
        // preset is not a fact about this tenant.
        if ($wasInstalled) {
            $rows[] = ['Preset' => $preset ?? $blueprint->defaultPreset ?? 'every field'];
        }

        // Read off the definition rather than off the flag that was passed in, so
        // that a re-run reports what the tenant *has* — which for a module
        // installed yesterday with --no-follow-ups and turned back on since is
        // not what either flag says (XIV-80).
        $rows[] = ['Follow-ups' => $definition->hasFollowUps() ? 'on' : 'off'];

        $rows[] = ['Fields' => implode(', ', $definition->getFieldKeys())];
        $rows[] = ['Collections' => implode(', ', $definition->getCollectionKeys()) ?: 'none'];

        $io->definitionList(...$rows);

        return Command::SUCCESS;
    }
}

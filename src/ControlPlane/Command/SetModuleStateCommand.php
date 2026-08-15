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

use App\ControlPlane\Entity\ModuleState;
use App\ControlPlane\Module\ModuleCatalog;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves a module between states — the process the platform publishes with (XIV-7).
 *
 * A command rather than a code change, because publishing is a decision about
 * whether customers may have a module, not a change to the module itself: the same
 * reason a tenant's status lives in the control plane (§4, §6.2). It takes no
 * tenant, and there is nowhere to name one: the answer is platform-wide.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'module:state',
    description: 'Set the platform-wide state of a module',
)]
final readonly class SetModuleStateCommand
{
    public function __construct(private ModuleCatalog $catalog)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Module key, e.g. "contact"')]
        string $module,
        #[Argument(description: 'The state to move it to')]
        ModuleState $state,
    ): int {
        $before = $this->catalog->state($module);

        try {
            $this->catalog->moveTo($module, $state);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($before === $state) {
            $io->info(sprintf('Module "%s" was already %s. Nothing changed.', $module, $state->value));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Module "%s" moved from %s to %s.', $module, $before->value, $state->value));

        $io->text($state->isOfferedInStore()
            ? 'Every tenant can see it in the store.'
            : 'It is no longer offered in the store. Tenants that already have it keep it: '
                . 'a state says what may be installed from here, never what is uninstalled.');

        return Command::SUCCESS;
    }
}

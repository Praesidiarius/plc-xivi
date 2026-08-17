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

namespace App\Command;

use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\Core\Demo\DemoLedger;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;

/**
 * Removes the records a generator made, and only those.
 *
 * It deletes by the ledger rather than by anything that looks synthetic, so a
 * record somebody typed by hand into the same module is not at risk — which is
 * the difference between an undo and a hopeful `DELETE`.
 *
 * A hard delete, unlike the rest of the engine (§5): soft deletion exists to
 * keep an audit trail a customer might want, and demo data has none worth
 * keeping. It asks first, because the one thing worse than demo data in a
 * database is real data missing from one.
 *
 * Registered in dev and test only, like the generator.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:demo:clear',
    description: 'Remove generated demo records from one tenant (development only)',
)]
final class ClearDemoDataCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantSwitcher $switcher,
        private readonly MetadataRepository $metadata,
        private readonly DemoLedger $ledger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Which tenant')
            ->addArgument('module', InputArgument::REQUIRED, 'Which module, as its key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slug = (string) $input->getArgument('slug');
        $moduleKey = (string) $input->getArgument('module');

        $tenant = $this->tenants->findOneBySlug($slug);

        if ($tenant === null) {
            $io->error(sprintf('No tenant "%s".', $slug));

            return Command::FAILURE;
        }

        return $this->switcher->runFor($tenant, function () use ($io, $moduleKey, $slug): int {
            try {
                $module = $this->metadata->get($moduleKey);
            } catch (ModuleNotInstalled $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            $held = $this->ledger->countFor($module->getKey());

            if ($held === 0) {
                $io->success(sprintf('No demo records in %s at %s.', $module->getLabel(), $slug));

                return Command::SUCCESS;
            }

            // Counted and confirmed before anything is deleted. The number is the
            // whole point: it says how much of the module is about to go, so a
            // surprising one can be answered with "no".
            $io->warning(sprintf(
                '%d generated record(s) in %s at %s will be deleted, with their collections and history.',
                $held,
                $module->getLabel(),
                $slug,
            ));

            if (!$io->confirm('Delete them?', false)) {
                $io->text('Nothing was deleted.');

                return Command::SUCCESS;
            }

            $io->success(sprintf('%d record(s) deleted.', $this->ledger->purge($module)));

            return Command::SUCCESS;
        });
    }
}

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

use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
use Xivi\Core\Demo\DemoDataGenerator;
use Xivi\Core\Demo\DemoLedger;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;

/**
 * Fills a module with plausible records, for finding out what happens at a size
 * nobody has typed by hand.
 *
 * **Registered in dev and test only** — see `config/services.yaml`. It does not
 * exist in a production image at all, which is a stronger guarantee than a flag
 * somebody could pass by accident against a customer's database.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:demo:generate',
    description: 'Generate demo records for one tenant (development only)',
)]
final class GenerateDemoDataCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantSwitcher $switcher,
        private readonly MetadataRepository $metadata,
        private readonly DemoDataGenerator $generator,
        private readonly DemoLedger $ledger,
        /**
         * Doctrine's development query log, if this environment has one.
         *
         * It keeps every statement *and a backtrace for each* so the profiler can
         * show them, which is exactly right for one web request and fatal here:
         * generating twenty thousand records ran out of memory after about
         * fourteen hundred, inside the logger rather than anywhere in the
         * generator. Emptied after each batch below.
         *
         * Optional because it does not exist when debug is off.
         */
        #[Autowire(service: 'doctrine.debug_data_holder')]
        private readonly ?DebugDataHolder $queryLog = null,
        /**
         * The second thing that remembers every query (XIV-74).
         *
         * Doctrine also *logs* each statement to the `doctrine` channel, and a
         * debug build keeps every log record in memory so the profiler has a panel
         * to render. Emptying the query log above and not this one leaves a
         * smaller version of the same ceiling in place: the two accumulate
         * together, the first is simply the greedier because it carries a
         * backtrace per statement. Ten thousand invoices — the heaviest module,
         * lifecycle walks and line items included — survived on the query log
         * reset alone, so this was not the failure here that it was in
         * `tenant:reset`; it is emptied anyway, because "the number nobody has
         * typed yet" is the entire premise of this command.
         *
         * @see ResetTenantCommand::forgetQueries() for the argument
         *      about why this is a reset at every seam and not a mute switch
         */
        #[Autowire(service: 'debug.log_processor')]
        private readonly ?DebugLoggerInterface $logRecords = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Which tenant')
            ->addArgument('module', InputArgument::REQUIRED, 'Which module, as its key')
            ->addOption('amount', 'a', InputOption::VALUE_REQUIRED, 'How many records', '50')
            ->addOption('seed', 's', InputOption::VALUE_REQUIRED, 'Makes the run repeatable')
            ->setHelp(<<<'HELP'
                Generates records from the module's own field definitions, so a field added
                in the editor is filled in without this command knowing it exists.

                    <info>%command.full_name% acme contact --amount=5000</info>

                Pass <info>--seed</info> to get the same records every time, which is what makes a
                bug found at record 4,312 reproducible.

                Everything it creates is written down in the demo_record table, so
                <info>tenant:demo:clear</info> can remove exactly these records and nothing else.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slug = (string) $input->getArgument('slug');
        $moduleKey = (string) $input->getArgument('module');
        $amount = (int) $input->getOption('amount');
        $seed = $input->getOption('seed');

        if ($amount < 1) {
            $io->error('Ask for at least one record.');

            return Command::INVALID;
        }

        $tenant = $this->tenants->findOneBySlug($slug);

        if ($tenant === null) {
            $io->error(sprintf('No tenant "%s". Run tenant:list to see them.', $slug));

            return Command::FAILURE;
        }

        return $this->switcher->runFor($tenant, function () use ($io, $moduleKey, $amount, $seed, $slug): int {
            try {
                $module = $this->metadata->get($moduleKey);
            } catch (ModuleNotInstalled $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

            $io->title(sprintf('%d %s for %s', $amount, mb_strtolower($module->getLabel()), $slug));

            $progress = $io->createProgressBar($amount);
            $progress->start();
            $last = 0;

            $made = $this->generator->generate(
                module: $module,
                amount: $amount,
                seed: $seed === null ? null : (int) $seed,
                onBatch: function (int $total) use ($progress, &$last): void {
                    $progress->advance($total - $last);
                    $last = $total;

                    // Or the run dies in the logs rather than in anything it was
                    // meant to be testing.
                    $this->queryLog?->reset();
                    $this->logRecords?->clear();
                },
            );

            $progress->finish();
            $io->newLine(2);

            $io->success(sprintf(
                '%d generated. %d demo record(s) in %s altogether — remove them with tenant:demo:clear %s %s.',
                $made,
                $this->ledger->countFor($module->getKey()),
                $module->getLabel(),
                $slug,
                $module->getKey(),
            ));

            return Command::SUCCESS;
        });
    }
}

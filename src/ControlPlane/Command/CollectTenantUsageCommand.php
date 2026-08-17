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
use App\ControlPlane\Usage\CollectionOutcome;
use App\ControlPlane\Usage\UsageCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Walks every customer and writes down what they are using (XIV-59).
 *
 * **This is what makes the tenant list able to show usage without opening a
 * single tenant connection.** The page reads the control plane; this fills the
 * control plane in; and the two are separated by however long it is since this
 * last ran, which is why the page prints that time beside every figure (§8.11).
 *
 * ## A command and cron, not a queue
 *
 * There is no worker process in this deployment and no message consumer — the
 * same constraint that made mail synchronous in [XIV-37]. So "periodically" means
 * what it has always meant on a server: a line in the deployment's crontab,
 * running this. Nightly is the obvious cadence and nothing here assumes it; the
 * figures are stamped with when they were taken, so an installation that runs it
 * hourly and one that runs it weekly both tell the truth about themselves.
 *
 * Introducing a queue to avoid a cron entry would be adding a runtime component —
 * a consumer to supervise, restart and monitor — to a system that has none, for a
 * job that takes seconds and that nobody is waiting on. When there is a consumer
 * for other reasons, moving this onto it is a small change; inventing one for this
 * is not.
 *
 * ## What a failing tenant does to the run
 *
 * Nothing, except to itself. The collector records the failure against that
 * customer and this loop moves to the next one, because a run that stopped at the
 * first unreachable database would leave every customer after it in the
 * alphabet with figures from whenever it last worked — and they would be drawn on
 * the page as *current*, since a figure's timestamp cannot tell you about a run
 * that never reached it.
 *
 * **It still exits non-zero when anything failed.** Under cron a non-zero exit is
 * how somebody finds out at all: the mail lands, it names the customers, and the
 * remaining forty-nine have their figures anyway. A run that swallowed the
 * failure would be a quieter one and a worse one, because the page's own report —
 * "could not be read" on one row — is only ever seen by somebody who has already
 * gone looking.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:usage:collect',
    description: 'Collect what each tenant uses — users, last sign-in, records — into the control plane',
)]
final readonly class CollectTenantUsageCommand
{
    public function __construct(
        private TenantRepository $tenants,
        private UsageCollector $collector,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Collect only this tenant')]
        ?string $slug = null,
    ): int {
        $tenants = $slug !== null
            ? array_values(array_filter([$this->tenants->findOneBySlug($slug)]))
            : $this->tenants->findAllOrdered();

        if ($tenants === []) {
            $io->error($slug !== null ? sprintf('No tenant with slug "%s".', $slug) : 'No tenants to collect.');

            return Command::FAILURE;
        }

        $failed = [];
        $rows = [];

        foreach ($tenants as $tenant) {
            // One at a time, and the collector closes the customer's database
            // before this loop comes round again — see UsageCollector for why
            // that is the difference between a run nobody notices and a run that
            // blocks a deprovision.
            $outcome = $this->collector->collect($tenant);

            if ($outcome->failed()) {
                $failed[$tenant->getSlug()] = (string) $outcome->reason;
            }

            $rows[] = self::row($tenant->getSlug(), $outcome);
        }

        $io->table(['Tenant', 'Users', 'Last sign-in', 'Records'], $rows);

        if ($failed !== []) {
            // The driver's own words, here and nowhere else. They name the host,
            // the port and the role, which is fine in the terminal of somebody
            // who already has the DSN and is exactly why they are not stored on
            // the row that ends up on a web page (see TenantUsage).
            $io->section('Could not be collected');

            foreach ($failed as $slug => $reason) {
                $io->text(sprintf(' <error>%s</error>: %s', $slug, $reason));
            }

            $io->newLine();
            $io->error(sprintf(
                '%d of %d tenants could not be collected. The rest were, and are stored.',
                \count($failed),
                \count($tenants),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d tenant(s) collected.', \count($tenants)));

        return Command::SUCCESS;
    }

    /**
     * One line of the report, in the vocabulary the page uses.
     *
     * A failed tenant says so across the whole row rather than showing zeroes,
     * for the reason the page draws it that way too: a zero is a fact about a
     * customer and a failure is a fact about the attempt, and a table that prints
     * them the same way is one somebody makes a decision on.
     *
     * @return list<string>
     */
    private static function row(string $slug, CollectionOutcome $outcome): array
    {
        if ($outcome->failed()) {
            return [$slug, 'could not be read', 'could not be read', 'could not be read'];
        }

        $usage = $outcome->usage;

        return [
            $slug,
            (string) $usage->getUserCount(),
            $usage->getLastLoginAt()?->format('Y-m-d H:i') ?? 'never',
            (string) $usage->getRecordCount(),
        ];
    }
}

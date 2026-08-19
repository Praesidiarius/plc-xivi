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

use App\Registry\Repository\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Support\SupportTicketCollector;

/**
 * Walks every customer and brings back the questions they have asked (XIV-123).
 *
 * **This is what puts a customer's ticket in front of an operator at all.** The
 * ticket is written into the customer's own database, because §4.4's grant
 * leaves nowhere else for a customer's request to write; the operator's screen
 * reads the control plane; and this is the only thing that joins the two.
 * {@see SupportTicketCollector} has the full argument, including the shape that
 * was rejected — the customer's instance posting to a control-plane endpoint
 * over HTTP, which would hand the public image a credential the database
 * deliberately denies it.
 *
 * ## A command and cron, for [XIV-59]'s reason
 *
 * There is no worker process in this deployment and no message consumer — the
 * constraint that made mail synchronous in [XIV-37] and every collection here a
 * cron entry. `App\Monitoring\ScheduledJobs` is where this is written down
 * (XIV-126) and is the list `deploy:crontab` prints, so an installation that
 * takes its crontab from this repository gets this job without anybody
 * remembering it — which is the failure that ticket exists to prevent and that
 * `tenant:purchase:collect` had already suffered.
 *
 * **Run it often.** This is the job with a person on the far end who has just
 * described a problem and is now watching a screen that says nobody has it yet.
 * The suggested cadence in `ScheduledJobs` is five minutes, which is
 * `signup:provision`'s rather than the purchase collector's ten, and for
 * `signup:provision`'s reason: somebody is waiting rather than something is
 * being counted.
 *
 * **Nothing here can be made to run less often to save work.** A customer with
 * no tickets costs one `SELECT` in their database, which is the same read the
 * usage collector was already making a connection for.
 *
 * ## What a failing tenant does to the run
 *
 * Nothing, except to itself. A customer whose database did not answer keeps
 * whatever was collected from them last time — blanking somebody's outstanding
 * questions because their database was briefly unreachable is the wrong kind of
 * honest, and the operator's half of those rows exists nowhere else.
 *
 * **It still exits non-zero when anything failed**, because under cron the exit
 * status is how anybody finds out — and here a failure has somebody with a
 * problem on the other end of it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:support:collect',
    description: 'Collect the support tickets each tenant has raised into the control plane',
)]
final readonly class CollectSupportTicketsCommand
{
    public function __construct(
        private TenantRepository $tenants,
        private SupportTicketCollector $collector,
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
        $tickets = 0;
        $new = 0;

        foreach ($tenants as $tenant) {
            // One at a time, and the switcher closes the customer's database
            // before this loop comes round again — the property [XIV-94] made
            // load-bearing: a run that sat attached to every customer at once is
            // what gets killed halfway through by somebody's deprovision.
            $report = $this->collector->collect($tenant);

            if ($report->failed()) {
                $failed[$tenant->getSlug()] = (string) $report->reason;
                $rows[] = [$tenant->getSlug(), 'could not be read', ''];

                continue;
            }

            $tickets += $report->collected;
            $new += $report->new;
            $rows[] = [$tenant->getSlug(), (string) $report->collected, (string) $report->new];
        }

        $io->table(['Tenant', 'Tickets', 'New'], $rows);

        if ($failed !== []) {
            // The driver's own words, here and nowhere else — they name the host,
            // the port and the role, which is fine in the terminal of somebody
            // who already has the DSN and is exactly why nothing stores them.
            $io->section('Could not be collected');

            foreach ($failed as $tenantSlug => $reason) {
                $io->text(sprintf(' <error>%s</error>: %s', $tenantSlug, $reason));
            }

            $io->newLine();
            $io->error(sprintf(
                '%d of %d tenants could not be collected. What was collected from the rest is stored, '
                . 'and what was collected from these before is untouched.',
                \count($failed),
                \count($tenants),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d tenant(s) collected, %d ticket(s), %d of them new.',
            \count($tenants),
            $tickets,
            $new,
        ));

        return Command::SUCCESS;
    }
}

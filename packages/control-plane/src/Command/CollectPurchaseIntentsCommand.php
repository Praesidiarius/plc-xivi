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
use Xivi\ControlPlane\Purchase\PurchaseIntentCollector;

/**
 * Walks every customer and writes down what they have asked to buy (XIV-102).
 *
 * **This is what puts a purchase request in front of an operator at all.** The
 * request is written into the customer's own database, because §4.4's grant
 * leaves nowhere else for a customer's request to write; the operator's screen
 * reads the control plane; and this is the only thing that joins the two.
 * `PurchaseIntentCollector` has the full argument, including the shape that was
 * rejected — the store posting to a control-plane endpoint over HTTP, which would
 * hand the public image a credential the database deliberately denies it.
 *
 * ## A command and cron, for [XIV-59]'s reason
 *
 * There is no worker process in this deployment and no message consumer — the
 * constraint that made mail synchronous in [XIV-37] and usage collection a cron
 * job in [XIV-59]. So "periodically" is a line in the deployment's crontab,
 * running this. Every row it writes is stamped with when it was written and the
 * screen draws that beside the request, so an installation that runs this every
 * five minutes and one that runs it nightly both tell the truth about themselves.
 *
 * **Run it more often than the usage collector.** They are the same shape and
 * they are not the same urgency: usage figures are a background fact about a
 * customer, and a purchase request is somebody waiting for an answer. Nothing
 * here enforces a cadence — a deployment that wants both on one line can put them
 * there — but the sentence belongs in the file somebody reads when they are
 * writing that line.
 *
 * ## What a failing tenant does to the run
 *
 * Nothing, except to itself, and rather less than it does in the usage collector.
 * A customer whose database did not answer keeps whatever was collected from them
 * last time — see {@see PurchaseIntentCollector::collect()} for why blanking
 * their requests over a network hiccup would be the wrong kind of honest.
 *
 * **It still exits non-zero when anything failed**, because under cron the exit
 * status is how anybody finds out. And unlike the usage collector's, a failure
 * here has a customer on the other end of it who is waiting.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:purchase:collect',
    description: 'Collect the modules each tenant has asked to buy into the control plane',
)]
final readonly class CollectPurchaseIntentsCommand
{
    public function __construct(
        private TenantRepository $tenants,
        private PurchaseIntentCollector $collector,
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
        $requests = 0;

        foreach ($tenants as $tenant) {
            // One at a time, and the switcher closes the customer's database
            // before this loop comes round again — the property [XIV-94] made
            // load-bearing: a run that sat attached to every customer at once is
            // the thing that gets killed halfway through by somebody's
            // deprovision, silently, from the operator's side.
            $report = $this->collector->collect($tenant);

            if ($report->failed()) {
                $failed[$tenant->getSlug()] = (string) $report->reason;
                $rows[] = [$tenant->getSlug(), 'could not be read', ''];

                continue;
            }

            $requests += $report->collected;
            $rows[] = [$tenant->getSlug(), (string) $report->collected, (string) $report->removed];
        }

        $io->table(['Tenant', 'Requests', 'Withdrawn'], $rows);

        if ($failed !== []) {
            // The driver's own words, here and nowhere else — they name the host,
            // the port and the role, which is fine in the terminal of somebody who
            // already has the DSN and is exactly why nothing stores them.
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
            '%d tenant(s) collected, %d outstanding purchase request(s).',
            \count($tenants),
            $requests,
        ));

        return Command::SUCCESS;
    }
}

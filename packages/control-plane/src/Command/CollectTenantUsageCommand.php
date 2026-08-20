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
use Xivi\ControlPlane\Usage\CollectionOutcome;
use Xivi\ControlPlane\Usage\UsageCollector;

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
 * ## One thing it says beyond the figures (XIV-125)
 *
 * The run names the customers **nobody has ever signed in to**, which is what an
 * abandoned tenant looks like from the outside: a database that exists and is
 * not being used. It is a report and only ever a report. See
 * {@see reportUnused()} for why there is no threshold in it, why it does not
 * touch the exit code, and why nothing in this repository will ever remove a
 * customer's database on a schedule.
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
        $unused = [];

        foreach ($tenants as $tenant) {
            // One at a time, and the collector closes the customer's database
            // before this loop comes round again — see UsageCollector for why
            // that is the difference between a run nobody notices and a run that
            // blocks a deprovision.
            $outcome = $this->collector->collect($tenant);

            if ($outcome->failed()) {
                $failed[$tenant->getSlug()] = (string) $outcome->reason;
            } elseif ($outcome->usage->getLastLoginAt() === null) {
                // Nobody has ever signed in to this customer's database
                // (XIV-125). Collected here rather than worked out later
                // because this is where the answer is known to be a *reading*
                // rather than a missing row: a failed collection has no figures
                // and must never be reported as an empty one.
                $unused[$tenant->getSlug()] = $tenant->getProvisionedAt() ?? $tenant->getCreatedAt();
            }

            $rows[] = self::row($tenant->getSlug(), $outcome);
        }

        $io->table(['Tenant', 'Users', 'Last sign-in', 'Records'], $rows);

        self::reportUnused($io, $unused);

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
     * The customers whose databases exist and have never been signed in to
     * (XIV-125).
     *
     * ## Why this is reported and never acted on
     *
     * A tenant provisioned and never used is a real cost: a PostgreSQL database,
     * a role, a hostname and a line on every screen that lists customers. It is
     * also, from here, indistinguishable from a customer who bought the thing on
     * Friday and starts on Monday. **So it is reported, and nothing in this
     * repository will ever remove one on a timer.** §4.1 makes deprovision loud,
     * interactive and refused unattended, and §4.6 says in as many words that no
     * automatic state may destroy a database on its own; a cron job that dropped
     * a customer's data because nobody had logged in yet would be both of those
     * decisions undone by a scheduled task. The operator reads this and runs
     * `tenant:deprovision` themselves, or does not.
     *
     * ## Why there is no threshold
     *
     * "Abandoned" would need a number of days, and §8.10 already rejected
     * inventing one on the tenant list: a tenant nobody has touched for
     * twenty-three days is exactly as unused as one at twenty-five, and a rule
     * that draws the line between them teaches its reader that everything under
     * the line is fine. So this section reports the *fact*, that nobody has ever
     * signed in, together with the date the tenant started existing, and the
     * reader supplies the judgement. The consequence is that a customer
     * provisioned this afternoon appears here tonight, with today's date beside
     * them, which reads as exactly what it is.
     *
     * ## Why it lives in this run rather than on the page
     *
     * The figures are already in hand here, and this run happens on a schedule,
     * which is the difference that matters: the tenant list says "nobody has
     * signed in" on a row somebody has to open the page to read, and this arrives
     * in the operator's mail without anybody going looking (§4.5). It also does
     * not touch the exit code, because nothing is broken. A non-zero exit is
     * this command's way of saying a customer could not be *read*, and spending
     * it on "a customer has not started yet" would teach somebody to ignore it.
     *
     * @param array<string, \DateTimeImmutable> $unused slug to when the tenant
     *                                                  began existing, newest
     *                                                  first once sorted
     */
    private static function reportUnused(SymfonyStyle $io, array $unused): void
    {
        if ($unused === []) {
            return;
        }

        // Oldest first: the customer who was provisioned in March and never came
        // back is the one worth reading about, and the one from this morning is
        // noise until it is not. A list that put them the other way round would
        // bury the finding under the ordinary.
        asort($unused);

        $io->section('Nobody has ever signed in');

        foreach ($unused as $slug => $since) {
            $io->text(sprintf(
                ' <comment>%s</comment>: exists since %s (%d day(s))',
                $slug,
                $since->format('Y-m-d'),
                (int) $since->diff(new \DateTimeImmutable())->days,
            ));
        }

        $io->newLine();
        $io->text(
            'These databases exist and nobody has used them. Nothing removes one automatically: '
            . '`tenant:deprovision` is deliberately manual and asks before it drops anything (§4.1).',
        );
        $io->newLine();
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

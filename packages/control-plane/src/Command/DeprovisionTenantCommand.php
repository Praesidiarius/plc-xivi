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
use App\Tenancy\Dbal\TenantDsnParser;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\ControlPlane\Usage\RecordCounter;

/**
 * Removes a customer: the control-plane row, the database and the role.
 *
 * The counterpart `tenant:provision` never had (XIV-72). Until this existed, the
 * only way to undo a provision was to read `TenantProvisioner::deprovision()` and
 * reimplement it by hand in `psql` — which is how somebody ends up dropping
 * `tenant_<slug>` on a tenant whose DSN says otherwise, because the method
 * resolves the database and role out of the stored DSN and improvised SQL
 * assumes they follow the slug.
 *
 * **This one ships.** Unlike the demo commands it is not excluded from the
 * production image in `config/services.yaml`, because removing a customer is a
 * real operation and an operator who cannot do it from the console will do it
 * from `psql` instead — which is the failure this replaces, not an alternative
 * to it. Everything below is about making it hard to do by accident rather than
 * hard to do.
 *
 * **The record count in the confirmation is no longer counted here** (XIV-59).
 * "Switch into the tenant, read its own metadata, count each shape" is now
 * {@see RecordCounter}, because the usage collector asks the identical question
 * of every customer on a schedule and two copies of it would have drifted at the
 * first change to any of the three steps. Nothing about what this command prints
 * has changed; the docblock that used to live on the private method — including
 * why it is allowed to throw, and why the connection it opens must be shut before
 * `DROP DATABASE` runs — moved with the code.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:deprovision',
    description: 'Remove a tenant completely: control-plane row, database and role',
)]
final readonly class DeprovisionTenantCommand
{
    public function __construct(
        private TenantRepository $tenants,
        private TenantProvisioner $provisioner,
        private TenantDsnParser $dsnParser,
        private RecordCounter $records,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        InputInterface $input,
        #[Argument(description: 'Tenant slug')]
        string $slug,
        #[Option(description: 'Remove it without asking. Required for an unattended run')]
        bool $force = false,
    ): int {
        $tenant = $this->tenants->findOneBySlug($slug);

        if ($tenant === null) {
            // Named plainly, with the way to see what does exist. The failure
            // this replaces was somebody discovering the typo from a Postgres
            // error about a database that was never there.
            $io->error(sprintf('No tenant with slug "%s".', $slug));
            $io->note('bin/console tenant:list shows the registry.');

            return Command::FAILURE;
        }

        $database = $this->dsnParser->databaseName($tenant->getDatabaseDsn());
        $role = $this->dsnParser->userName($tenant->getDatabaseDsn()) ?? '(none)';
        $hostnames = $tenant->getDomains()->map(static fn ($d) => $d->getHostname())->toArray();

        $unreadable = null;

        try {
            $records = $this->records->countFor($tenant);
        } catch (\Throwable $e) {
            $records = null;
            $unreadable = $e->getMessage();
        }

        $io->warning(sprintf('About to permanently remove tenant "%s".', $tenant->getSlug()));
        $io->definitionList(
            ['Name' => $tenant->getName()],
            ['Status' => $tenant->getStatus()->value],
            ['Hostnames' => implode(', ', $hostnames) ?: '(none)'],
            ['Database' => $database],
            ['Role' => $role],
            ['Records' => $records === null ? 'could not be read' : self::describe($records)],
        );

        // Said under the table rather than in it, because a driver error is three
        // lines long and would push every other row off the side of a terminal.
        if ($unreadable !== null) {
            $io->text([' <comment>The database did not answer:</comment> ' . $unreadable, '']);
        }

        // The one fact the status line above does not shout: this customer is
        // being served *right now*. §4.1 argues why `suspended` is not made a
        // hard prerequisite; saying it here is what that argument owes in return.
        if ($tenant->getStatus()->servesRequests()) {
            $io->text(sprintf(
                ' <comment>This tenant is %s and answering requests. Suspend it first if you are not sure.</comment>',
                $tenant->getStatus()->value,
            ));
            $io->newLine();
        }

        if (!$force) {
            // **`--no-interaction` is deliberately not enough.** Symfony answers
            // an unanswered question with its default, so a `-n` run would take
            // whatever the default happens to be — and the failure this guards
            // against is a script removing a customer's database because nobody
            // was there to say no. So the unattended path is refused outright
            // rather than defaulted, and the flag that opens it has to be typed.
            // The interactive default below is `no` as well, so neither route
            // can reach a drop by pressing return.
            if (!$input->isInteractive()) {
                $io->error('Refusing to deprovision unattended: pass --force to mean it.');

                return Command::INVALID;
            }

            if (!$io->confirm(sprintf('Remove "%s" and everything in it?', $tenant->getSlug()), false)) {
                $io->text('Nothing was removed.');

                return Command::SUCCESS;
            }
        }

        $this->provisioner->deprovision($tenant);

        $io->success(sprintf('Tenant "%s" is gone.', $slug));
        $io->text([
            sprintf(' Database <info>%s</info> dropped, role <info>%s</info> dropped, control-plane row deleted.', $database, $role),
            ' <comment>Unrecoverable from here.</comment> Anything that was in that database is only in a'
                . ' backup now, and the hostnames above resolve to no tenant.',
        ]);
        $io->newLine();

        return Command::SUCCESS;
    }

    /** @param array<string, int> $counts */
    private static function describe(array $counts): string
    {
        if ($counts === []) {
            return 'no modules installed';
        }

        return sprintf(
            '%d in %d module(s) — %s',
            array_sum($counts),
            \count($counts),
            implode(', ', array_map(
                static fn (string $key, int $count): string => sprintf('%s %d', $key, $count),
                array_keys($counts),
                $counts,
            )),
        );
    }
}

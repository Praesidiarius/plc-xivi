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
use App\Tenancy\TenantSwitcher;
use App\Tenant\Attachment\AttachmentStore;
use App\Tenant\Attachment\AttachmentUsage;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Provisioning\TenantProvisioner;
use Xivi\ControlPlane\Provisioning\TenantRemovalFailed;
use Xivi\ControlPlane\Usage\RecordCounter;
use Xivi\Core\Field\AttachmentLimit;

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
 * **A removal that stops part-way now says so** (XIV-94). It used to be able to
 * fail with a driver exception about SQLSTATE 55006 after the control-plane row
 * had already gone, which is the one wreckage nothing else in this project can
 * see. The order was turned around and the failure given words; see
 * {@see reportPartialRemoval()} for what an operator reads and
 * `TenantProvisioner::deprovision()` for why the cluster is emptied before the
 * registry is. Nothing about what this command *asks* changed, deliberately —
 * §4.1 settled that and the ticket left it alone.
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
        // What the tenant is holding on the filesystem ([XIV-115]). Counted for
        // the confirmation and deleted by the provisioner, which is the same
        // split the record count already has: this command says what will go and
        // the removal is what makes it go.
        private AttachmentStore $attachments,
        private TenantSwitcher $switcher,
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

        // **Counted here rather than described afterwards** ([XIV-115]). §4.1 asks
        // the confirmation to name what is about to be destroyed, and until this
        // ticket "everything in it" meant rows. A customer's contracts and signed
        // delivery notes are the part of a deprovision that cannot be typed
        // again, so the number and the weight of them belong in the sentence
        // somebody says yes to.
        //
        // Read through a switch that opens no database connection: the store asks
        // the resolved tenant for its database name and then talks to a
        // filesystem, so this works even for a tenant whose provisioning died
        // before the database existed, which is precisely the row somebody wants
        // to remove.
        $files = null;

        try {
            $files = $this->switcher->runFor($tenant, $this->attachments->usage(...));
        } catch (\Throwable $e) {
            $unreadable = trim(($unreadable ?? '') . ' ' . $e->getMessage());
        }

        $io->warning(sprintf('About to permanently remove tenant "%s".', $tenant->getSlug()));
        $io->definitionList(
            ['Name' => $tenant->getName()],
            ['Status' => $tenant->getStatus()->value],
            ['Hostnames' => implode(', ', $hostnames) ?: '(none)'],
            ['Database' => $database],
            ['Role' => $role],
            ['Records' => $records === null ? 'could not be read' : self::describe($records)],
            ['Files' => $files instanceof AttachmentUsage ? self::describeFiles($files) : 'could not be read'],
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

        try {
            $this->provisioner->deprovision($tenant);
        } catch (TenantRemovalFailed $e) {
            return $this->reportPartialRemoval($io, $e);
        }

        $io->success(sprintf('Tenant "%s" is gone.', $slug));
        $io->text([
            sprintf(
                ' Database <info>%s</info> dropped, role <info>%s</info> dropped, %s deleted, control-plane row deleted.',
                $database,
                $role,
                $files instanceof AttachmentUsage ? self::describeFiles($files) : 'the files',
            ),
            ' <comment>Unrecoverable from here.</comment> Anything that was in that database is only in a'
                . ' backup now, and the hostnames above resolve to no tenant.',
        ]);
        $io->newLine();

        return Command::SUCCESS;
    }

    /**
     * A removal that stopped part-way, said as a sentence (XIV-94).
     *
     * The bug this replaces is the whole reason the ticket exists: a deprovision
     * that met an open session surfaced as `SQLSTATE[55006]: Object in use: 7
     * ERROR: database "…" is being accessed by other users`, thrown out of
     * `TenantProvisioner.php:194` with a stack trace and no statement at all
     * about what had and had not happened to the customer. An operator standing
     * in front of that has two questions — *why* and *what now* — and a driver
     * exception answers the first one in a dialect and the second one not at all.
     *
     * So the three parts here are the three parts of the answer, in the order
     * they are wanted: what went wrong in one sentence, what exists right now,
     * and the line to type next. It is deliberately the same shape as
     * {@see ResetProgress::report()} — that is [XIV-74]'s "say what is gone and
     * what is standing" applied to the other destructive command, and two
     * commands that leave wreckage should describe it the same way rather than
     * each inventing a house style.
     *
     * **Caught rather than re-thrown**, which is the opposite of what
     * `tenant:reset` does with its own failures, and the difference is what the
     * exception knows. A reset dies of something nobody predicted — an out of
     * memory, a module that cannot spell its own name — so the trace is the only
     * description of it there is, and §4.1 keeps it. Everything reaching here has
     * already been identified by `TenantProvisioner`, down to the SQLSTATE, so
     * the trace adds a file and a line number to a sentence that already says
     * more than they do. The driver's own words are still printed, under the
     * report, for the same reason the unreadable-database note above is printed
     * where it is: wanted, but not at the top.
     */
    private function reportPartialRemoval(SymfonyStyle $io, TenantRemovalFailed $failure): int
    {
        $io->error($failure->reason());

        $io->section(sprintf('Where "%s" stands now', $failure->slug));
        $io->definitionList(...$failure->state());

        $io->text([
            ' Nothing above is rolled back, and running the removal again is safe from here — both',
            ' drops pass over what is already gone:',
            '',
            sprintf('   <info>%s</info>', $failure->nextStep()),
        ]);
        $io->newLine();

        $previous = $failure->getPrevious();

        if ($previous instanceof \Throwable) {
            $io->text([' <comment>The database said:</comment> ' . $previous->getMessage(), '']);
        }

        return Command::FAILURE;
    }

    /**
     * The files, in the same voice as the record count above.
     *
     * A weight beside the number, because "412 files" and "412 files, 3.1 GB" are
     * different sentences to somebody deciding whether they still have a backup.
     */
    private static function describeFiles(AttachmentUsage $usage): string
    {
        if ($usage->files === 0) {
            return 'none';
        }

        return sprintf(
            '%d file%s, %s',
            $usage->files,
            $usage->files === 1 ? '' : 's',
            AttachmentLimit::shown($usage->bytes),
        );
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

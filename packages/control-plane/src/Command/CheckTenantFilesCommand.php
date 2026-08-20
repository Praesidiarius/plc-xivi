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

use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Attachment\AttachmentStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\Core\Field\AttachmentLimit;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\HoldsAFile;
use Xivi\Core\Field\StoredFile;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Record\RecordRepository;

/**
 * Two things that could disagree, asked whether they do (XIV-115).
 *
 * A record's metadata is in the customer's database and its bytes are on a
 * filesystem (§5.30), which is a second place for the truth to live. This
 * project's standing answer to that shape is a check that fails rather than a
 * promise to be careful: `deploy:check-hosts` and `deploy:check-grants` are the
 * same command one noun over.
 *
 * Two findings, and they are not the same kind of thing:
 *
 *  * **A record pointing at a file that is not there.** Somebody clicks a
 *    download and gets a 404, and this is the only thing that turns that into a
 *    list. Always worth a look: a restore that brought the database back without
 *    the volume produces exactly this, in bulk.
 *  * **A file no record claims.** Normal in small numbers and named as such
 *    below: an upload is written before the save is validated, so a refused save
 *    leaves one (see `App\Record\RecordUploads`, which argues why that is the
 *    lesser evil). Worth watching, and worth investigating when it is thousands.
 *
 * ## Why it is run on demand rather than by `bin/deploy`
 *
 * §4.2's checks all share a property this one does not have: they are about the
 * *deployment* being correct, they are cheap, and their failure is an outage.
 * Grants that were not applied are a 500 for every user of every tenant, and a
 * hostname nothing answers to is a customer who cannot sign in, so both belong
 * in front of a container swap where a non-zero exit is cheap.
 *
 * Drift between records and files is neither. It does not make a release wrong,
 * it costs a full directory walk per customer, and the *expected* steady state
 * is a handful of orphans. A release blocked at three in the morning by
 * somebody's abandoned upload is how a check stops being read (§4.4 makes that
 * argument about `doctrine:schema:validate`, and eleven `SERIAL` columns are
 * what it cost). So this ships in the image, exits with the same three codes
 * every other check publishes, and is run when somebody wants the answer: after
 * a restore, after a volume change, or on a schedule an installation chooses.
 *
 * **It reports and never repairs**, on `deploy:check-grants`' precedent: the
 * repair for an orphan is `rm`, and a command that deleted a customer's file
 * because a database was temporarily unreadable would be a worse bug than the
 * one it was fixing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:files:check',
    description: 'Report records pointing at missing files, and files no record claims',
)]
final readonly class CheckTenantFilesCommand
{
    /**
     * At least one tenant has drift, and the run itself was fine.
     *
     * The same three codes `tenant:migrate` publishes and for the same reason:
     * 2 is Symfony's "you typed it wrong" and borrowing it would make the number
     * lie to the first tool that reads it generically.
     */
    public const int DRIFTED = 3;

    public function __construct(
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
        private MetadataRepository $metadata,
        private RecordRepository $records,
        private AttachmentStore $attachments,
        private FieldTypeRegistry $fieldTypes,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Check only this tenant')]
        ?string $slug = null,
        #[Option(description: 'Print every finding rather than the first few of each kind')]
        bool $all = false,
    ): int {
        $tenants = $slug !== null
            ? array_filter([$this->tenants->findOneBySlug($slug)])
            : $this->tenants->findAllOrdered();

        if ($tenants === []) {
            $io->error($slug !== null ? sprintf('No tenant with slug "%s".', $slug) : 'No tenants to check.');

            return Command::FAILURE;
        }

        $drifted = 0;
        $unreadable = 0;

        foreach ($tenants as $tenant) {
            try {
                $findings = $this->checkOne($tenant);
            } catch (\Throwable $e) {
                // One customer's unreadable database or unmounted directory must
                // not cost the other forty-nine their answer. `tenant:migrate`
                // takes the same decision for the same reason.
                ++$unreadable;
                $io->writeln(sprintf('<error>%s</error>: %s', $tenant->getSlug(), $e->getMessage()));

                continue;
            }

            if ($findings->isClean()) {
                $io->writeln(sprintf(
                    '<info>%s</info>: %d file(s), all accounted for',
                    $tenant->getSlug(),
                    $findings->claimed,
                ));

                continue;
            }

            ++$drifted;
            $this->report($io, $tenant, $findings, $all);
        }

        if ($drifted === 0 && $unreadable === 0) {
            $io->success(sprintf('Records and files agree across %d tenant(s).', \count($tenants)));

            return Command::SUCCESS;
        }

        $io->error(sprintf(
            '%d of %d tenant(s) have drift%s.',
            $drifted,
            \count($tenants),
            $unreadable === 0 ? '' : sprintf(', and %d could not be read', $unreadable),
        ));

        return self::DRIFTED;
    }

    /**
     * One customer: what its records claim, against what is on the disk.
     *
     * **The records first and the directory second**, which is the order that
     * makes the answer least wrong while people are working. A file uploaded
     * between the two passes is seen by the second and not the first, so it is
     * reported as an orphan, which is a harmless false positive somebody can
     * re-run away. The other order would report a file as *missing* from a record
     * that had just been saved, which is the finding that sends an operator
     * looking for a restore.
     */
    private function checkOne(Tenant $tenant): FileFindings
    {
        /** @var FileFindings $findings */
        $findings = $this->switcher->runFor($tenant, function (): FileFindings {
            $claimed = [];
            $missing = [];

            // The customer's own definitions rather than the module catalogue
            // (§6.1): a tenant who never installed `invoice` has no invoice
            // table to walk. Modules only, and no collections, because a
            // collection row cannot hold a file: the editor and the installer
            // both refuse it, and this loop is where that refusal pays for
            // itself.
            foreach ($this->metadata->all() as $module) {
                foreach ($module->getFields() as $field) {
                    if (!$this->fieldTypes->get($field->getType()) instanceof HoldsAFile) {
                        continue;
                    }

                    // Soft-deleted records included, and that is the line this
                    // whole method turns on: a deleted record still holds its
                    // value (§5.4 keeps values, and a delete here is a flag), so
                    // its file is still claimed. Walking live rows only would
                    // report every deleted record's attachment as an orphan, and
                    // a check whose normal output is wrong is a check nobody
                    // reads.
                    foreach ($this->records->valueHolders($module, $field, includeDeleted: true) as $row) {
                        $file = StoredFile::parse($row['value']);

                        if ($file === null) {
                            // A value in a file column that is not a file. It
                            // claims no bytes, so it is not this check's finding:
                            // the record's own validation is what names it, on the
                            // next save or the next import.
                            continue;
                        }

                        $claimed[$file->token] = true;

                        if (!$this->attachments->has($file)) {
                            $missing[] = sprintf('%s #%d %s: %s', $module->getKey(), $row['id'], $field->getKey(), $file->name);
                        }
                    }
                }
            }

            $orphans = [];
            $orphanBytes = 0;

            foreach ($this->attachments->tokens() as $token => $size) {
                if (isset($claimed[$token])) {
                    continue;
                }

                $orphans[] = sprintf('%s (%s)', $token, AttachmentLimit::shown($size));
                $orphanBytes += $size;
            }

            return new FileFindings(\count($claimed), $missing, $orphans, $orphanBytes);
        });

        return $findings;
    }

    /**
     * What one tenant's drift looks like on a terminal.
     *
     * Capped unless `--all`, because an installation that lost a volume has one
     * finding per record and a screen of them says nothing the count does not.
     */
    private function report(SymfonyStyle $io, Tenant $tenant, FileFindings $findings, bool $all): void
    {
        $io->section($tenant->getSlug());

        if ($findings->missing !== []) {
            $io->writeln(sprintf('<error>%d record(s) point at a file that is not there:</error>', \count($findings->missing)));
            $io->listing($findings->shortened($findings->missing, $all));
        }

        if ($findings->orphans !== []) {
            $io->writeln(sprintf(
                '<comment>%d file(s) no record claims, %s:</comment>',
                \count($findings->orphans),
                AttachmentLimit::shown($findings->orphanBytes),
            ));
            $io->listing($findings->shortened($findings->orphans, $all));
            $io->text([
                ' A few of these are ordinary: a file is written before the record that names it is',
                ' validated, so an upload somebody abandoned leaves one. Nothing is deleted by this',
                ' command, deliberately.',
            ]);
        }
    }
}

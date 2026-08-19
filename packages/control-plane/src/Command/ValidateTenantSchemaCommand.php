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
use App\Tenancy\TenantSwitcher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `doctrine:schema:validate`, for a database this process has to be let into
 * first (XIV-97).
 *
 * ## The gap this fills
 *
 * The control plane can be checked with one line — `doctrine:schema:validate
 * --em=control` — because that connection's DSN is known at deploy time. The
 * tenant connection's is not: it comes out of the `tenant` row of whichever
 * customer is being served, and a console command is not serving anybody
 * (docs/architecture/open-questions.md §7.4). `--em=tenant` therefore does not fail with a
 * useful message; it fails at `TenantDriver`, because there is nothing to
 * connect to.
 *
 * So until this existed there was **no way at all** to ask whether a customer's
 * database still matched the mapping the code expects, and the honest state of
 * that question was "nobody knows". That is the more expensive half of the
 * ticket this comes from: the control plane is five tables in one installation,
 * and this is every customer's database. XIV-97 found more drift out here than
 * in there, and found it by running this.
 *
 * ## What it does, and what it deliberately does not
 *
 * It walks the registry, enters each customer through
 * `TenantSwitcher::runFor()` — the same door `tenant:usage:collect` and
 * `tenant:migrate` use, and the only supported one — and runs Doctrine's own
 * `SchemaValidator` inside. Nothing here reimplements a comparison; the whole
 * value is in reaching the database, and the answer has to be the same answer
 * `doctrine:schema:validate` gives or it is a second opinion nobody asked for.
 *
 * ## The part that is *not* just "the same command with a tenant"
 *
 * **A tenant database legitimately holds tables Doctrine has never heard of, and
 * that is the whole design rather than an accident.** Records are not entities
 * (§5): `contact`, `article`, their history tables and their collection tables
 * are created by `ModuleInstaller` out of the customer's own metadata, and which
 * of them exist is a runtime fact about that customer. `demo_record`,
 * `number_sequence` and `doctrine_migration_versions` are unmapped too.
 *
 * Point Doctrine's plain comparison at that and it proposes dropping every one
 * of them — the first run of this produced `DROP TABLE contact` — because "in
 * the database and not in the mapping" is the only conclusion it can reach about
 * a table it does not own. **A tenant database can therefore never be in sync in
 * the unrestricted sense**, which is not a fact about this installation but a
 * permanent property of the architecture, and a check that can never pass is
 * worth strictly less than no check at all.
 *
 * So the comparison is narrowed to the tables Doctrine actually maps, using
 * DBAL's own `setSchemaAssetsFilter` and nothing hand-rolled. The list is not
 * written down anywhere: `SchemaTool` already whitelists everything in the
 * target schema, so a filter that admits nothing else leaves exactly the mapped
 * tables — which means adding an entity extends the check by itself and there is
 * no second list to forget to update.
 *
 * The filter is set here, around this command's own loop, rather than as
 * `schema_filter` on the tenant connection in `doctrine.yaml`. It looks like
 * configuration and must not be: `ModuleInstaller` asks the schema manager
 * whether a customer's record table exists before it creates one, and that call
 * reads the same filter. A connection-wide filter admitting only the mapped
 * tables would tell the installer that `contact` does not exist, in a tenant
 * where it does.
 *
 * **It writes nothing, and that is a constraint rather than a preference.** A
 * check somebody is nervous about running against production is a check that
 * gets run once. `getUpdateSchemaList()` is the read-only half of what
 * `doctrine:schema:update` would do — it produces the statements and this prints
 * them; applying them is `tenant:migrate`'s job and a migration's, never this
 * command's, because §4 wants every schema change expand/contract and written
 * down rather than inferred from a diff.
 *
 * **The mapping is checked once, not once per customer.** Mapping validity is a
 * fact about the code — whether `mappedBy` points at something real, whether a
 * property's PHP type agrees with its Doctrine type — and is identical in all
 * fifty databases. Reporting it fifty times would bury the half that actually
 * varies. It still happens inside a tenant, because building the metadata needs
 * a platform and the platform comes from a connection.
 *
 * ## Why the report groups customers rather than listing them
 *
 * On an installation that is behaving, every tenant differs from the mapping in
 * exactly the same way or not at all — they run the same migrations from the
 * same code. So the interesting output is not fifty repetitions of six ALTER
 * statements; it is *how many distinct answers there are*. One group means a
 * fleet that moves together, and whatever it says applies to everybody. Two
 * groups means some customers were migrated and some were not, which is the
 * failure this command is most likely to be reached for and which a per-tenant
 * listing makes you spot by eye.
 *
 * A customer whose database cannot be opened at all is reported as that, and the
 * run carries on to the next one — same reasoning as `tenant:usage:collect`
 * (§8.11): stopping at the first unreachable database would leave every customer
 * after it in the alphabet unexamined while the command exits looking like it
 * had an opinion about them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:schema:validate',
    description: "Check each tenant's database against the mapping the code expects",
)]
final readonly class ValidateTenantSchemaCommand
{
    public function __construct(
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
        // The tenant entity manager is a lazy proxy, so holding it here stays
        // correct across `TenantSwitcher::switchTo()` replacing the manager —
        // the same reason `TenantMigrator` injects it this way.
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Validate only this tenant')]
        ?string $slug = null,
    ): int {
        $tenants = $slug !== null
            ? array_values(array_filter([$this->tenants->findOneBySlug($slug)]))
            : $this->tenants->findAllOrdered();

        if ($tenants === []) {
            $io->error($slug !== null ? sprintf('No tenant with slug "%s".', $slug) : 'No tenants to validate.');

            return Command::FAILURE;
        }

        /** @var array<string, list<string>>|null $mappingErrors class => messages, read from the first tenant reached */
        $mappingErrors = null;

        /** @var array<string, list<string>> $differences slug => the statements that would bring that database into line */
        $differences = [];

        /** @var array<string, string> $unreachable slug => what the driver said */
        $unreachable = [];

        $configuration = $this->entityManager->getConnection()->getConfiguration();
        $previousFilter = $configuration->getSchemaAssetsFilter();

        // Nothing beyond what `SchemaTool` whitelists for itself, which is the
        // set of mapped tables — see the class docblock for why that narrowing
        // is the difference between a check and a permanent red light. Restored
        // afterwards because this object belongs to the connection rather than
        // to this command, even though nothing else runs in this process.
        $configuration->setSchemaAssetsFilter(static fn (): bool => false);

        try {
            foreach ($tenants as $tenant) {
                try {
                    $this->switcher->runFor($tenant, function () use ($tenant, &$mappingErrors, &$differences): void {
                        // Built inside the switch and thrown away after it. The
                        // validator holds the entity manager it was made with,
                        // and that manager is replaced on every switch — one kept
                        // across the loop would be reporting on the previous
                        // customer (docs/architecture/open-questions.md §7.4).
                        $validator = new SchemaValidator($this->entityManager);

                        // `??=` rather than a flag: an empty array is a real
                        // answer — the mapping is fine — and only null means
                        // nobody has asked yet.
                        $mappingErrors ??= $validator->validateMapping();

                        $differences[$tenant->getSlug()] = array_values($validator->getUpdateSchemaList());
                    });
                } catch (\Throwable $failure) {
                    // The driver's own words, which name the host, the port and
                    // the role. Acceptable in the terminal of somebody who
                    // already holds the DSN, and the reason this is a console
                    // command rather than something rendered on the tenant list.
                    $unreachable[$tenant->getSlug()] = $failure->getMessage();
                }
            }
        } finally {
            $configuration->setSchemaAssetsFilter($previousFilter);
        }

        $io->section('Mapping');
        $mappingOk = $this->reportMapping($io, $mappingErrors);

        $io->section('Databases');
        $schemaOk = $this->reportSchema($io, $differences, $unreachable, \count($tenants));

        return $mappingOk && $schemaOk ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Whether the code's own mapping is internally consistent.
     *
     * Null means no tenant was reachable, so nothing was asked. Reporting that
     * as "the mapping is correct" would be the exact failure mode this ticket is
     * about — a green that means "not checked" rather than "checked and fine".
     *
     * @param array<string, list<string>>|null $errors
     */
    private function reportMapping(SymfonyStyle $io, ?array $errors): bool
    {
        if ($errors === null) {
            $io->warning('Not checked: no tenant database could be opened.');

            return false;
        }

        if ($errors === []) {
            $io->success('The mapping files are correct.');

            return true;
        }

        foreach ($errors as $class => $messages) {
            $io->text(sprintf('<error>[FAIL]</error> <comment>%s</comment>:', $class));
            $io->listing($messages);
        }

        return false;
    }

    /**
     * What each customer's database says, grouped by what it says.
     *
     * @param array<string, list<string>> $differences
     * @param array<string, string>       $unreachable
     */
    private function reportSchema(SymfonyStyle $io, array $differences, array $unreachable, int $total): bool
    {
        if ($unreachable !== []) {
            $io->text('Could not be opened:');

            foreach ($unreachable as $slug => $reason) {
                $io->text(sprintf(' <error>%s</error>: %s', $slug, $reason));
            }

            $io->newLine();
        }

        /** @var array<string, list<string>> $bySlugs the joined statements => the customers giving that answer */
        $bySlugs = [];

        foreach ($differences as $slug => $statements) {
            $bySlugs[implode("\n", $statements)][] = $slug;
        }

        foreach ($bySlugs as $joined => $slugs) {
            $who = sprintf('%d of %d tenant(s): %s', \count($slugs), $total, implode(', ', $slugs));

            if ($joined === '') {
                $io->text(sprintf('<info>in sync</info> — %s', $who));

                continue;
            }

            $statements = explode("\n", $joined);

            $io->text(sprintf('<error>%d difference(s)</error> — %s', \count($statements), $who));

            foreach ($statements as $statement) {
                $io->text(sprintf('    %s;', $statement));
            }

            $io->newLine();
        }

        $drifted = array_filter($differences, static fn (array $statements): bool => $statements !== []);

        if ($drifted === [] && $unreachable === []) {
            $io->success(sprintf('%d tenant database(s) in sync with the mapping files.', $total));

            return true;
        }

        $io->error(sprintf(
            '%d of %d tenant database(s) are not in sync; %d could not be read.',
            \count($drifted),
            $total,
            \count($unreachable),
        ));

        return false;
    }
}

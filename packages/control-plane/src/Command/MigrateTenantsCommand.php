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
use Xivi\ControlPlane\Provisioning\TenantProvisioner;

/**
 * Every schema change lands for every tenant (docs/architecture.md §4), so a deploy is not
 * finished until this has run across the whole registry.
 *
 * ## Carrying on is right; reporting success afterwards is not (XIV-61)
 *
 * The loop below catches per tenant and keeps going, and that is deliberate: one
 * unreachable database must not cost the other forty-nine theirs, because the
 * alternative — stopping at the first failure — leaves everybody *after* it in
 * the registry serving new code against the old schema, which is the situation
 * this command exists to end.
 *
 * That is correct for the command and was not correct for the thing calling it.
 * Until XIV-61 the run said `%d of %d tenants failed` and exited **1**, which is
 * the same 1 that "no tenants to migrate" and "no tenant with slug x" exit with.
 * Measured on this branch before the change, both of those returned 1, so a
 * deploy script could not tell "the registry is empty, nothing to do" from
 * "forty-nine of your fifty customers are migrated and one is not". Those are
 * opposite situations: the first is usually fine and the second is a deploy that
 * must not be allowed to report success.
 *
 * So the exit codes are three, and they are the command's published contract:
 *
 *   0  {@see Command::SUCCESS}      every tenant asked about is at the latest version
 *   1  {@see Command::FAILURE}      the run could not happen — an empty registry, or a
 *                                   slug nothing answers to. Nothing was changed
 *   3  {@see self::TENANT_FAILED}   the run happened and at least one tenant is behind.
 *                                   The others were migrated and are fine
 *
 * A deploy stops on anything non-zero. What the distinction buys is what it says
 * *afterwards* — and, more usefully, that a partial failure can be retried with
 * `--slug` for the named tenants rather than by re-running the whole registry
 * and hoping.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(name: 'tenant:migrate', description: 'Migrate tenant databases to the latest version')]
final readonly class MigrateTenantsCommand
{
    /**
     * At least one tenant is behind, and the rest are not.
     *
     * Three rather than two, because {@see Command::INVALID} is 2 and means "you
     * typed the command wrong" everywhere else in Symfony; borrowing it for
     * "your customer's database refused a connection" would make the number lie
     * to the first tool that reads it generically. Three is the first code
     * Symfony reserves nothing at.
     */
    public const int TENANT_FAILED = 3;

    public function __construct(
        private TenantRepository $tenants,
        private TenantProvisioner $provisioner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Migrate only this tenant')]
        ?string $slug = null,
    ): int {
        $tenants = $slug !== null
            ? array_filter([$this->tenants->findOneBySlug($slug)])
            : $this->tenants->findAllOrdered();

        if ($tenants === []) {
            $io->error($slug !== null ? sprintf('No tenant with slug "%s".', $slug) : 'No tenants to migrate.');

            return Command::FAILURE;
        }

        $failed = [];
        foreach ($tenants as $tenant) {
            try {
                $executed = $this->provisioner->migrate($tenant);
                $io->writeln(sprintf(
                    '<info>%s</info>: %s',
                    $tenant->getSlug(),
                    $executed === [] ? 'already up to date' : sprintf('%d migration(s) applied', \count($executed)),
                ));
            } catch (\Throwable $e) {
                // One tenant's failure must not stop the rest: leaving the
                // remaining tenants un-migrated after a deploy is worse.
                $failed[$tenant->getSlug()] = $e->getMessage();
                $io->writeln(sprintf('<error>%s</error>: %s', $tenant->getSlug(), $e->getMessage()));
            }
        }

        if ($failed !== []) {
            // Both halves of the count, and the slugs, because this is the
            // message a deploy captures and the one somebody reads an hour
            // later. "3 of 50 failed" without "47 are at the latest version"
            // reads as a run that did nothing, and the difference decides
            // whether the answer is to roll back or to fix three databases.
            $migrated = \count($tenants) - \count($failed);

            $io->error(sprintf(
                '%d of %d tenant(s) failed to migrate; the other %d %s at the latest version.',
                \count($failed),
                \count($tenants),
                $migrated,
                $migrated === 1 ? 'is' : 'are',
            ));

            $io->writeln('Retry the ones that failed, one at a time:');
            foreach (array_keys($failed) as $slug) {
                $io->writeln(sprintf('    bin/console tenant:migrate --slug=%s', $slug));
            }

            $io->newLine();
            $io->writeln(sprintf(
                'Exit code %d — a tenant failed. That is deliberately not the %d this command '
                . 'exits with when it could not run at all.',
                self::TENANT_FAILED,
                Command::FAILURE,
            ));

            return self::TENANT_FAILED;
        }

        $io->success(sprintf('%d tenant(s) up to date.', \count($tenants)));

        return Command::SUCCESS;
    }
}

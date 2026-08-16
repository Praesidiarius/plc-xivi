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

use App\ControlPlane\Command\CreateTenantUserCommand;
use App\ControlPlane\Entity\Tenant;
use App\ControlPlane\Provisioning\TenantProvisioner;
use App\ControlPlane\Repository\TenantRepository;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\Core\Demo\DemoDataGenerator;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleInstaller;
use Xivi\Core\Module\ModuleInstallOrder;
use Xivi\Core\Module\ModuleRegistry;

/**
 * Throw a test tenant away and build it again: deprovision, provision, install
 * modules, fill them with demo records, print the admin password (XIV-72).
 *
 * Six commands and a password to copy, done as one — which is what was actually
 * typed by hand the afternoon this was written down, and got typed wrong.
 *
 * **Registered in dev and test only** — see `config/services.yaml`, beside the
 * demo commands. It is not excluded because it is dangerous (`tenant:deprovision`
 * is more dangerous and ships); it is excluded because it is *meaningless* in
 * production. A command whose second act is to generate three hundred fictional
 * contacts has no business existing where the contacts are real, and not existing
 * is a stronger guarantee than a flag somebody could pass.
 *
 * That difference is also why this one asks more gently than `tenant:deprovision`
 * does. There the unattended path is refused outright; here `--no-interaction`
 * simply takes the default and goes ahead, because "yes, rebuild my scratch
 * tenant" is the right answer every time and this command cannot be pointed at a
 * customer's database in the first place.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:reset',
    description: 'Rebuild a tenant from scratch with modules and demo data (development only)',
)]
final readonly class ResetTenantCommand
{
    /** Enough to see a list paginate and a picker cap itself, small enough to wait for. */
    private const int DEFAULT_RECORDS = 50;

    public function __construct(
        private TenantRepository $tenants,
        private TenantProvisioner $provisioner,
        private TenantSwitcher $switcher,
        private UserCreator $users,
        private ModuleRegistry $modules,
        private ModuleInstallOrder $installOrder,
        private ModuleInstaller $installer,
        private MetadataRepository $metadata,
        private DemoDataGenerator $generator,
    ) {
    }

    /**
     * @param list<string> $hostnames
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Tenant slug; it is rebuilt whether or not it exists')]
        string $slug,
        #[Argument(description: 'Hostnames routed to it; defaults to <slug>.localhost')]
        array $hostnames = [],
        #[Option(description: 'Modules to install, comma separated; every module in this build otherwise')]
        ?string $modules = null,
        #[Option(description: 'Demo records to generate in each module; 0 installs the modules empty')]
        int $records = self::DEFAULT_RECORDS,
        #[Option(description: 'Makes the generated records the same every time')]
        ?int $seed = null,
        #[Option(description: 'Email of the admin user; defaults to admin@<first hostname>')]
        ?string $adminEmail = null,
        #[Option(description: 'Display name; defaults to the slug')]
        ?string $name = null,
    ): int {
        if ($records < 0) {
            $io->error('A negative number of records is not a thing. Use 0 for none.');

            return Command::INVALID;
        }

        $hostnames = $hostnames === [] ? [$slug . '.localhost'] : array_values($hostnames);
        $adminEmail ??= 'admin@' . $hostnames[0];

        // **Everything that can be refused is refused here**, before the existing
        // tenant is touched. A run that destroys a database and then discovers it
        // cannot spell "invoice" has left the developer worse off than the state
        // they asked to leave, which is the one outcome a reset command must not
        // produce.
        $ordered = $this->orderedModules($io, $modules);

        if ($ordered === null) {
            return Command::INVALID;
        }

        if (!$this->hostnamesAreFree($io, $slug, $hostnames)) {
            return Command::INVALID;
        }

        $existing = $this->tenants->findOneBySlug($slug);

        if ($existing instanceof Tenant && !$this->confirmRemoval($io, $existing)) {
            $io->text('Nothing was removed.');

            return Command::SUCCESS;
        }

        if ($existing instanceof Tenant) {
            $io->section(sprintf('Removing "%s"', $slug));
            $this->provisioner->deprovision($existing);
            $io->text(' Database, role and control-plane row dropped.');
        }

        $io->section(sprintf('Provisioning "%s"', $slug));

        // ProvisioningFailed and UserAlreadyExists are both RuntimeExceptions, and
        // so is everything the installer throws. Caught as one because the
        // recovery is the same for all of them and it is this command's own name:
        // whatever went wrong, running it again starts from nothing.
        try {
            $tenant = $this->provisioner->provision($slug, $name ?? $slug, $hostnames);
            $password = $this->users->create($tenant, $adminEmail, $adminEmail, roles: ['ROLE_ADMIN']);

            $io->text(sprintf(' %s, migrated, with %s as admin.', implode(', ', $hostnames), $adminEmail));

            $installed = $this->installAndFill($io, $tenant, $ordered, $records, $seed);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            $io->note(sprintf('Half a tenant is left behind. bin/console tenant:reset %s starts again.', $slug));

            return Command::FAILURE;
        }

        $io->success(sprintf('Tenant "%s" is new again.', $slug));
        $io->definitionList(
            ['Sign in at' => 'https://' . $hostnames[0]],
            ['Admin' => $adminEmail],
            ['Modules' => implode(', ', $installed) ?: 'none'],
            ['Records' => sprintf('%d in each module%s', $records, $seed === null ? '' : sprintf(', from seed %d', $seed))],
        );

        // The whole point of the exercise: without this the developer has a fresh
        // tenant they cannot sign in to, and the six commands start again.
        CreateTenantUserCommand::writePassword($io, $password);

        return Command::SUCCESS;
    }

    /**
     * The requested modules in an order that works, or null having said why not.
     *
     * @return list<string>|null
     */
    private function orderedModules(SymfonyStyle $io, ?string $modules): ?array
    {
        $requested = $modules === null
            ? array_keys($this->modules->all())
            : array_values(array_filter(array_map(trim(...), explode(',', $modules)), static fn (string $k): bool => $k !== ''));

        $unknown = array_values(array_filter($requested, fn (string $key): bool => !$this->modules->has($key)));

        if ($unknown !== []) {
            $io->error(sprintf(
                'No module named %s in this build. Available: %s.',
                implode(', ', array_map(static fn (string $k): string => sprintf('"%s"', $k), $unknown)),
                implode(', ', array_keys($this->modules->all())) ?: 'none',
            ));

            return null;
        }

        try {
            // Asked in this order on purpose: the closure is what the list should
            // have said, so the refusal can be a corrected command line rather
            // than the name of one missing module and an invitation to guess how
            // many more there are behind it.
            $closure = $this->installOrder->closureOf($requested);
            $missing = array_values(array_diff($closure, $requested));

            if ($missing !== []) {
                $io->error(sprintf(
                    '%s cannot be installed without %s.',
                    implode(', ', array_map(static fn (string $k): string => sprintf('"%s"', $k), $requested)),
                    implode(', ', array_map(static fn (string $k): string => sprintf('"%s"', $k), $missing)),
                ));
                $io->note(sprintf('--modules=%s', implode(',', $closure)));

                return null;
            }

            return $this->installOrder->of($requested);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return null;
        }
    }

    /**
     * Nothing is destroyed for a run that cannot finish: a hostname another tenant
     * already answers on makes `provision()` throw, and by then this one's database
     * would be gone.
     *
     * @param list<string> $hostnames
     */
    private function hostnamesAreFree(SymfonyStyle $io, string $slug, array $hostnames): bool
    {
        foreach ($hostnames as $hostname) {
            $owner = $this->tenants->findOneByHostname(TenantResolver::normalize($hostname));

            if ($owner instanceof Tenant && $owner->getSlug() !== $slug) {
                $io->error(sprintf('Hostname "%s" belongs to tenant "%s".', $hostname, $owner->getSlug()));

                return false;
            }
        }

        return true;
    }

    /**
     * Default *yes*, which `tenant:deprovision` would never do.
     *
     * The difference is the exclusion in `config/services.yaml`, not a judgement
     * about how careful people are: this command does not exist in a build that
     * has customers, so the worst an unattended run can cost is a scratch tenant
     * somebody asked to reset. See the class docblock.
     */
    private function confirmRemoval(SymfonyStyle $io, Tenant $tenant): bool
    {
        $io->warning(sprintf(
            'Tenant "%s" exists and will be destroyed: its database, its role and everything in it.',
            $tenant->getSlug(),
        ));

        return $io->confirm('Throw it away and build it again?', true);
    }

    /**
     * Installs each module and fills it, one module at a time in dependency order.
     *
     * **Filled as it goes rather than after everything is installed**, because the
     * generator picks the values for a reference field out of the records that
     * exist when it runs: orders generated before there is a single contact would
     * have nobody to name. The order that makes the installs legal is the same
     * order that makes the data plausible, which is not a coincidence — both come
     * from the same `requires`.
     *
     * **One record count for every module**, deliberately. A per-module map
     * (`contact=300,article=40`) would be a second syntax to learn for something
     * `tenant:demo:generate` already does one module at a time; what this command
     * is asked for is a tenant of roughly a given size, and one number says that.
     * Sizes that have to differ per module are a second command away.
     *
     * @param list<string> $ordered
     *
     * @return list<string> what ended up installed
     */
    private function installAndFill(
        SymfonyStyle $io,
        Tenant $tenant,
        array $ordered,
        int $records,
        ?int $seed,
    ): array {
        if ($ordered === []) {
            return [];
        }

        $io->section('Modules');

        return $this->switcher->runFor($tenant, function () use ($io, $ordered, $records, $seed): array {
            $installed = [];

            foreach ($ordered as $key) {
                $definition = $this->installer->install($this->modules->get($key));
                $installed[] = $key;

                if ($records === 0) {
                    $io->text(sprintf(' <info>%s</info> installed.', $key));

                    continue;
                }

                // Re-read rather than reusing what install() handed back: the
                // generator wants the definition as the tenant's database now has
                // it, and a module installed a moment ago is exactly the case
                // where a stale copy would be invisible.
                $made = $this->generator->generate(
                    module: $this->metadata->get($key),
                    amount: $records,
                    seed: $seed,
                );

                $io->text(sprintf(' <info>%s</info> installed, %d record(s) — %s.', $key, $made, $definition->getTableName()));
            }

            return $installed;
        });
    }
}

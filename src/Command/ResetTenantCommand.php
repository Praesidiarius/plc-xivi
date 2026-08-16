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
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantSwitcher;
use App\Tenant\Security\UserCreator;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
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
 * **Doing all of it in one process is what made XIV-74 possible.** Six separate
 * commands each had their own memory budget and their own Doctrine query log;
 * folded into one run, the log is one log and it is never emptied, so the
 * profiler's record of a deprovision was still resident while the fourth module
 * was being filled. See {@see forgetQueries()} — the fix is small, the failure
 * it produced was not, and the reason it was not is the second thing this class
 * now takes seriously: a reset that dies half way has already destroyed
 * something.
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
        private TenantDsnParser $dsnParser,
        /**
         * Doctrine's development query log, if this environment has one.
         *
         * Optional because it does not exist when debug is off, which is also the
         * shape `tenant:demo:generate` uses — the two commands have the same
         * problem and now solve it the same way rather than in two dialects.
         *
         * @see forgetQueries() for what it is doing here
         */
        #[Autowire(service: 'doctrine.debug_data_holder')]
        private ?DebugDataHolder $queryLog = null,
        /**
         * And the *other* thing that remembers every query, which is the half of
         * XIV-74 that only showed up once the first half was fixed.
         *
         * Doctrine logs every statement it runs to the `doctrine` channel at debug
         * level, and in a debug build Monolog carries a processor that keeps every
         * record it sees so the profiler's log panel has something to show. Two
         * independent accumulators, both fed by the same INSERTs. The query log is
         * the greedier of the two — it is the one that died first, because it
         * carries a backtrace per statement — and emptying it simply moved the
         * wall: at half the memory limit the same run then ran out inside Monolog,
         * encoding a log line about an order line.
         *
         * Taken as the interface rather than as `DebugProcessor`, since `clear()`
         * is exactly what is wanted here and it is the interface's whole third
         * method.
         *
         * @see forgetQueries()
         */
        #[Autowire(service: 'debug.log_processor')]
        private ?DebugLoggerInterface $logRecords = null,
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

        $typedHostnames = $hostnames;
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

        $progress = new ResetProgress($slug, $ordered);

        if ($existing instanceof Tenant) {
            $io->section(sprintf('Removing "%s"', $slug));
            $this->provisioner->deprovision($existing);
            $progress->destroyedTheOldTenant();
            $io->text(' Database, role and control-plane row dropped.');
        }

        $io->section(sprintf('Provisioning "%s"', $slug));

        // **Everything from here on is caught, and nothing from here on is
        // recoverable**, which is why the handler's whole job is to describe
        // rather than to repair. `\Throwable` and not `\RuntimeException`: the
        // narrower catch this replaces looked right and was not, because
        // `Doctrine\DBAL\Exception` extends `\Exception` and `UserCreator`
        // rejects an empty address with an `\InvalidArgumentException` — so the
        // two most ordinary ways for the second half of a reset to fail were
        // precisely the two that walked past the handler and printed a stack
        // trace with no mention that a database had just been dropped.
        //
        // Re-thrown rather than swallowed. Turning an exception into a tidy
        // message and `Command::FAILURE` would cost the stack trace that `-v`
        // exists to show, and deciding how somebody else's exception is rendered
        // is Symfony's job and not this command's. What is this command's job is
        // the paragraph printed first: what is gone, what is standing, what to
        // type next.
        try {
            $tenant = $this->provisioner->provision($slug, $name ?? $slug, $hostnames);
            $progress->provisioned($this->dsnParser->databaseName($tenant->getDatabaseDsn()));
            $this->forgetQueries();

            $password = $this->users->create($tenant, $adminEmail, $adminEmail, roles: ['ROLE_ADMIN']);
            $progress->createdTheAdminUser();
            $this->forgetQueries();

            $io->text(sprintf(' %s, migrated, with %s as admin.', implode(', ', $hostnames), $adminEmail));

            $installed = $this->installAndFill($io, $tenant, $ordered, $records, $seed, $progress);
        } catch (\Throwable $e) {
            $progress->report($io, $this->describeRegistry($slug), self::restartLine($slug, $typedHostnames, $modules, $records, $seed));

            throw $e;
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
     * Empties everything in this process that is remembering queries (XIV-74).
     *
     * The log keeps every statement it sees, with its bound parameters and — in
     * this application, because `config/packages/doctrine.yaml` turns
     * `profiling_collect_backtrace` on with debug — a full backtrace for each. In
     * a web request that is exactly right: a few hundred queries, held for as long
     * as it takes the profiler to render them, and then the process ends. Here the
     * process does not end. A reset is a deprovision, a provision, forty
     * migrations, a user, four module installs and four generations, and at
     * `--records=2000` it died of the log rather than of anything it was doing:
     * 128 MB gone before the *first* module had finished filling, with the fatal
     * landing inside var-dumper as it tried to render the out-of-memory error it
     * had itself caused by cloning.
     *
     * **Emptied at every seam instead of disabled**, and the difference is worth
     * being clear about, because [XIV-74] asked for the middleware to be turned
     * off. There is no supported way to do that from inside a running command:
     * the middleware is composed into the DBAL driver when the connection is
     * built, the holder it writes to is a constructor-injected `readonly`
     * property, and the only lever Symfony exposes on it is `reset()`. Reaching
     * past that — a subclass registered over `doctrine.debug_data_holder` with a
     * mute switch — would be a service that exists so that one command can lie to
     * the profiler, and it would buy nothing this does not: resetting between
     * phases and after every generated batch makes the log's cost a function of
     * the batch size, which is a constant, rather than of `--records`, which is
     * the number a developer turns up. Flat is flat.
     *
     * That is also why this is not the refusal the ticket held in reserve. A
     * command that told developers to pass `--no-debug` would be a convenience
     * command with a flag to memorise, and the acceptance criterion was somebody
     * typing a plausible number and it simply working.
     *
     * **Both logs, not one.** Doctrine's profiler log is the greedy one and was
     * the one in the stack trace, but Monolog's debug processor is keeping its own
     * copy of every `doctrine` channel record for the profiler's log panel, and
     * emptying only the first just moves the ceiling somewhere less obvious. That
     * was measured rather than guessed: with the query log reset and the memory
     * limit halved to 64 MB, the same run died again at 66 MB — this time inside
     * `Monolog\Utils::jsonEncode`, on a `sales_order_line` INSERT.
     *
     * **What is deliberately left growing, and why.** There is a third debug
     * accumulator: the profiler's stopwatch, which the same Doctrine middleware
     * starts and stops around every statement, and which keeps a
     * `StopwatchPeriod` for each. It is not emptied here. `Stopwatch::reset()` is
     * the only lever it has and it throws the sections away wholesale, while
     * `ConsoleProfilerListener` has a section open across the whole command and
     * closes it at `console.terminate` — so resetting mid-run would trade a
     * command that gets slowly heavier for one that reliably explodes on its last
     * line, after the work succeeded. That is a bad trade twice over.
     *
     * Measured, so the residual is a number rather than a worry: with both logs
     * emptied, the run that started this ticket — 2,000 records in each of four
     * modules — peaks at about 44 MB against a 128 MB limit, and grows by roughly
     * a quarter of a kilobyte per statement, which is the periods. That moves the
     * ceiling from "the first count anybody turned up" to tens of thousands of
     * records per module. Past that, `--no-debug` still exists and
     * `tenant:demo:generate` is a fresh process per module by construction.
     *
     * Either log is null when debug is off, in which case there is nothing to do.
     */
    private function forgetQueries(): void
    {
        $this->queryLog?->reset();
        $this->logRecords?->clear();
    }

    /**
     * What the control plane says about this slug *after* a failure.
     *
     * Read back rather than deduced from how far the run got, because the two can
     * disagree in the one direction that matters: `TenantProvisioner::provision()`
     * persists its row before it creates the role and the database, so a failure
     * in the middle of provisioning leaves a row this command never received a
     * `Tenant` object for. Deducing would report "never created" over the top of a
     * registry entry that is sitting right there.
     *
     * Allowed to fail in turn. If the exception being reported came out of a
     * flush, the control-plane entity manager is closed and this query throws too
     * — which is worth saying rather than worth crashing the report over, since
     * "the registry could not be read" is itself a fact about the state.
     */
    private function describeRegistry(string $slug): string
    {
        try {
            $tenant = $this->tenants->findOneBySlug($slug);

            if (!$tenant instanceof Tenant) {
                return sprintf('no row for "%s"; the slug is free', $slug);
            }

            return sprintf(
                'a row for "%s" in status "%s", database "%s"',
                $slug,
                $tenant->getStatus()->value,
                $this->dsnParser->databaseName($tenant->getDatabaseDsn()),
            );
        } catch (\Throwable $e) {
            return 'could not be read — ' . $e->getMessage();
        }
    }

    /**
     * The command line that starts this run over, built out of what was typed.
     *
     * Reconstructed rather than printed as `tenant:reset <slug>`, because the
     * options are the part somebody has to remember and a reset that failed at
     * module four is exactly the moment they are least likely to. The hostnames
     * are included only when they were given: the report should be a line to
     * paste, not a lecture about defaults.
     *
     * @param list<string> $hostnames as typed, before defaulting
     */
    private static function restartLine(string $slug, array $hostnames, ?string $modules, int $records, ?int $seed): string
    {
        $line = 'bin/console tenant:reset ' . $slug;

        foreach ($hostnames as $hostname) {
            $line .= ' ' . $hostname;
        }

        if ($modules !== null) {
            $line .= ' --modules=' . $modules;
        }

        $line .= ' --records=' . $records;

        if ($seed !== null) {
            $line .= ' --seed=' . $seed;
        }

        return $line;
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
     *
     * The second sentence is [XIV-74]'s and it is not decoration. A reset cannot
     * build the replacement beside the original — they want the same slug, the
     * same hostnames, the same database name and the same role — so the drop
     * genuinely is the first act and everything after it is unprotected. That is
     * the fact the confirmation owes the person answering it, and saying it here
     * is the honest half of choosing to report a partial failure rather than to
     * engineer one away.
     */
    private function confirmRemoval(SymfonyStyle $io, Tenant $tenant): bool
    {
        $io->warning(sprintf(
            'Tenant "%s" exists and will be destroyed: its database, its role and everything in it.'
                . ' It is destroyed first and rebuilt after, so a failure part-way leaves it gone —'
                . ' the run will say exactly what it left behind.',
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
        ResetProgress $progress,
    ): array {
        if ($ordered === []) {
            return [];
        }

        $io->section('Modules');

        return $this->switcher->runFor($tenant, function () use ($io, $ordered, $records, $seed, $progress): array {
            $installed = [];

            foreach ($ordered as $key) {
                $definition = $this->installer->install($this->modules->get($key));
                $installed[] = $key;
                $progress->installedModule($key);
                $this->forgetQueries();

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
                    // Per batch and not per module. Two thousand records is ten
                    // batches, and a log that survived a whole module would still
                    // grow with the number a developer typed — which is the only
                    // way this can go wrong again.
                    onBatch: $this->forgetQueries(...),
                    seed: $seed,
                );

                $progress->filledModule($key);
                $this->forgetQueries();

                $io->text(sprintf(' <info>%s</info> installed, %d record(s) — %s.', $key, $made, $definition->getTableName()));
            }

            return $installed;
        });
    }
}

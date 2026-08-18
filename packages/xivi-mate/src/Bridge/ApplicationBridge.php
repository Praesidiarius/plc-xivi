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

namespace Xivi\Mate\Bridge;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;

/**
 * How a Mate tool reaches this application (XIV-76).
 *
 * Mate's MCP server is **its own process with its own DI container** — the one
 * built by `Symfony\AI\Mate\Container\ContainerFactory`, which knows about
 * extensions and nothing about Xivi. So a tool cannot simply be handed a
 * `TenantRepository`: the application's container does not exist until somebody
 * builds it. What the process *does* have is the project's Composer autoloader
 * (`vendor/bin/mate` loads `getcwd().'/vendor/autoload.php'` before anything
 * else), so `App\Kernel` is loadable and the kernel can be booted from here.
 *
 * That is the entire trick, and it is what keeps the promise the ticket made:
 * the tools call the same services the application calls, rather than
 * reimplementing the queries behind them. A second way of asking the engine what
 * it holds would be a second thing to keep in step with the engine.
 *
 * **A fresh kernel per call, shut down afterwards.** The obvious optimisation is
 * to boot once and keep it, and it is wrong here for three separate reasons, any
 * one of which would be enough:
 *
 *   * `mate serve` is a long-running process, and a booted kernel holds a tenant
 *     connection and a metadata cache. §7.4 is entirely about that being the way
 *     one customer's field definitions get served to another; `TenantSwitcher`
 *     exists to make it survivable *within* a request, not across an afternoon.
 *   * A held connection to a tenant's database is in the way of `DROP DATABASE`.
 *     The lifecycle tools below deprovision and reset tenants, and a cached kernel
 *     would be the connection they have to deal with — the same one
 *     `TenantProvisioner::deprovision()` guards against from the other side by
 *     clearing the switcher first. Since [XIV-94] a removal terminates what it
 *     finds attached rather than refusing, so a cached kernel would no longer
 *     *block* the tool; it would be disconnected by it and go on holding a dead
 *     connection to a database that no longer exists, which is a worse thing for a
 *     server that lives for an afternoon to be left holding.
 *   * The server outlives the code. An agent that edits a service and calls a
 *     tool would be answered out of the container compiled before the edit,
 *     which is [XIV-63]'s stale-artifact failure wearing a different hat, and it
 *     arrives disguised as the tool being wrong.
 *
 * Booting costs a couple of hundred milliseconds against a call an agent waits
 * on anyway. Correctness is cheap here; staleness is not.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ApplicationBridge
{
    public function __construct(
        /** The project root, handed over as `%mate.root_dir%` — see config/config.php. */
        private string $rootDir,
    ) {
    }

    /**
     * Runs $work against a freshly booted application container.
     *
     * @template T
     *
     * @param callable(ContainerInterface):T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        $kernel = $this->boot();

        try {
            return $work($kernel->getContainer());
        } finally {
            // Closes the connections and drops the container. In a `finally` so
            // that a tool which throws still releases the tenant database it was
            // reading — otherwise the next lifecycle call inherits a blocked drop
            // from a failure two calls ago.
            $kernel->shutdown();
        }
    }

    /**
     * Runs a console command in-process and hands back everything it said.
     *
     * **This is how the lifecycle tools reuse the commands' own guardrails**
     * rather than reimplementing them. `tenant:deprovision` refuses an unattended
     * run without `--force`, `tenant:reset` refuses an unsatisfiable module set
     * before it destroys anything, and both name what they are about to remove.
     * Calling the command means all of that applies unchanged, and it means there
     * is exactly one implementation to keep correct.
     *
     * `--no-interaction` is not optional here and is not a way of getting past
     * anything: there is no terminal on the other end of an MCP call, so a
     * question would block the server forever. The commands are written for
     * precisely this — `tenant:deprovision` treats an unattended run as *not*
     * consent and refuses it outright, which is why the tool exposes `force` as
     * an argument an agent has to pass on purpose.
     *
     * Exceptions are caught rather than thrown so a failure comes back as a tool
     * result an agent can read. A stack trace on stderr of a process it does not
     * own is not a diagnostic it will ever see.
     *
     * @param array<string, scalar|array<int, string>|null> $input command name and arguments, in ArrayInput's shape
     *
     * @return array{exit_code: int, output: string}
     */
    public function console(array $input): array
    {
        return $this->run(function (ContainerInterface $container) use ($input): array {
            /** @var Kernel $kernel */
            $kernel = $container->get('kernel');

            $application = new Application($kernel);
            $application->setAutoExit(false);
            $application->setCatchExceptions(false);

            $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, decorated: false);
            $arguments = new ArrayInput([...$input, '--no-interaction' => true]);
            $arguments->setInteractive(false);

            try {
                $exitCode = $application->run($arguments, $output);
            } catch (\Throwable $e) {
                return [
                    'exit_code' => 1,
                    'output' => $output->fetch() . "\n" . $e::class . ': ' . $e->getMessage(),
                ];
            }

            return ['exit_code' => $exitCode, 'output' => trim($output->fetch())];
        });
    }

    /**
     * The application, in the environment this checkout is configured for.
     *
     * The environment is read rather than chosen. `dev` is what the container
     * runs as and what the tools need — the introspection service and
     * `tenant:reset` are registered in dev and test only, on purpose (see
     * `config/services.yaml`) — but hard-coding it here would mean a tool
     * silently ignoring an `APP_ENV` somebody set deliberately. Where the
     * environment cannot serve the tools, they say so; they do not quietly move
     * to one that can.
     */
    private function boot(): Kernel
    {
        // `vendor/bin/mate` loads the autoloader and nothing else, so none of the
        // .env files have been read — unlike `bin/console`, which goes through
        // autoload_runtime.php. Without this the DSN template and the secret keys
        // are simply absent and the first tenant query fails with a message about
        // a missing environment variable rather than about anything real.
        //
        // bootEnv leaves real environment variables alone, so everything compose
        // sets (DATABASE_URL, APP_ENV, TEST_RUN) still wins, exactly as it does
        // for the console.
        (new Dotenv())->bootEnv($this->rootDir . '/.env');

        $environment = (string) ($_SERVER['APP_ENV'] ?? 'dev');

        if ($environment === 'prod') {
            throw new \RuntimeException(
                'Xivi\'s MCP tools cannot run against APP_ENV=prod: the services they read are '
                . 'registered in dev and test only (config/services.yaml), and this extension is a '
                . 'dev dependency that is not installed in a production build at all.'
            );
        }

        $kernel = new Kernel($environment, (bool) ($_SERVER['APP_DEBUG'] ?? true));
        $kernel->boot();

        return $kernel;
    }
}

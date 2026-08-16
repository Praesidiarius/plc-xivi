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

namespace Xivi\Mate\Capability;

use App\ControlPlane\Introspection\TenantInspector;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Xivi\Mate\Bridge\ApplicationBridge;

/**
 * Throwing a tenant away, and building one again (XIV-76).
 *
 * **Why destructive tools exist at all**, since the first instinct is to expose
 * only reads and that instinct does not survive contact:
 *
 *   * An agent with a shell **can already run every one of these commands.**
 *     Withholding them from the tool surface changes ergonomics, not authority —
 *     it makes the capable path less discoverable without making it less
 *     available.
 *   * Worse, it pushes agents toward improvising. Before `tenant:deprovision`
 *     existed ([XIV-72]), rebuilding a test tenant here meant hand-written
 *     `DELETE`, `DROP DATABASE` and `DROP ROLE` — which is strictly more
 *     dangerous than a tool that names the database, the role and the record
 *     count before it acts.
 *   * The commands **already ship their own guardrails**, and calling the command
 *     reuses them rather than reimplementing them: the confirmation defaults to
 *     no, an unattended run is refused outright without `--force`, and
 *     `tenant:reset` refuses an unsatisfiable module set before touching
 *     anything.
 *
 * **What is carried across from a terminal is the part a terminal did for free:
 * somebody reading the warning.** A command prints what it is about to destroy
 * and waits; here nobody waits, and the agent reading the result is the only
 * reviewer there is. So both tools below take a *census before acting* — the
 * database, the role, the hostnames, the installed modules — and return it in the
 * result whether the run succeeded or not. An agent that has destroyed the wrong
 * tenant should be able to say which one, out of the same message that told it
 * the call worked.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantLifecycleTool
{
    public function __construct(private ApplicationBridge $application)
    {
    }

    /**
     * @param string      $tenant  the slug to rebuild; it does not have to exist yet
     * @param string|null $modules comma-separated module keys, or null for every module in this build
     * @param int         $records demo records to generate per module; 0 installs them empty
     * @param int|null    $seed    makes the generated records identical between runs
     */
    #[McpTool(
        name: 'xivi-tenant-reset',
        title: 'Rebuild a Xivi dev tenant',
        description: 'DESTRUCTIVE. Rebuilds a development tenant end to end: drops its database, role and '
            . 'control-plane row if it exists, provisions it again, installs modules in dependency order, '
            . 'generates demo records and prints the new admin password. Everything in that tenant is lost. '
            . 'Never point this at a tenant somebody is working in — the dev tenants on a developer machine '
            . 'are their working state. The result lists what was destroyed. Console equivalent: '
            . 'bin/console tenant:reset <slug>.',
    )]
    public function reset(
        string $tenant,
        ?string $modules = null,
        int $records = 50,
        ?int $seed = null,
        ?string $hostname = null,
    ): string {
        // Taken first, and this is the whole point of the tool over the command:
        // by the time the command has run there is nothing left to describe, and
        // its own printed warning went to a buffer nobody was watching.
        $before = $this->census($tenant);

        $input = ['command' => 'tenant:reset', 'slug' => $tenant, '--records' => $records];

        if ($hostname !== null) {
            $input['hostnames'] = [$hostname];
        }

        if ($modules !== null) {
            $input['--modules'] = $modules;
        }

        if ($seed !== null) {
            $input['--seed'] = $seed;
        }

        $result = $this->application->console($input);

        return ResponseEncoder::encode([
            'command' => 'tenant:reset ' . $tenant,
            'succeeded' => $result['exit_code'] === 0,
            'exit_code' => $result['exit_code'],
            // Null when the slug named nothing, which is the ordinary case for a
            // reset that is really a first build. An empty structure here would
            // read as "a tenant with no modules", which is a different fact.
            'destroyed' => $before,
            'output' => $result['output'],
        ]);
    }

    /**
     * @param string $tenant the slug to remove
     * @param bool   $force  must be true; the command refuses an unattended run without it
     */
    #[McpTool(
        name: 'xivi-tenant-deprovision',
        title: 'Remove a Xivi tenant',
        description: 'DESTRUCTIVE AND IRREVERSIBLE. Drops a tenant\'s database, its Postgres role and its '
            . 'control-plane row. Everything that customer had is gone, and their hostnames stop resolving. '
            . 'Requires force=true: without it the command refuses, because there is no terminal here and an '
            . 'unattended run is not consent. Call xivi-tenants first and read the result — the census this '
            . 'returns names the database, the role and the record counts that were removed. Console '
            . 'equivalent: bin/console tenant:deprovision <slug> --force.',
    )]
    public function deprovision(string $tenant, bool $force = false): string
    {
        $before = $this->census($tenant);

        // Passed straight through rather than defaulted to true. The command's
        // guardrail is that `--no-interaction` is treated as a default and not as
        // consent, so `--force` has to be typed by somebody who means it; making
        // the tool always pass it would be quietly deleting that guardrail while
        // claiming to reuse it.
        $result = $this->application->console(array_filter([
            'command' => 'tenant:deprovision',
            'slug' => $tenant,
            '--force' => $force ?: null,
        ], static fn (mixed $value): bool => $value !== null));

        return ResponseEncoder::encode([
            'command' => 'tenant:deprovision ' . $tenant,
            'succeeded' => $result['exit_code'] === 0,
            'exit_code' => $result['exit_code'],
            'destroyed' => $result['exit_code'] === 0 ? $before : null,
            'would_have_destroyed' => $result['exit_code'] === 0 ? null : $before,
            'output' => $result['output'],
        ]);
    }

    /**
     * Everything worth naming afterwards, read before anything is touched.
     *
     * Deliberately tolerant of a tenant whose database cannot be opened: half a
     * tenant — a row whose provisioning died before the database existed — is one
     * of the states these tools exist to clear, and a census that threw would
     * make the wreckage the one thing nothing could remove. `TenantInspector`
     * already answers that way, and `tenant:deprovision` makes the same allowance
     * where it counts records.
     *
     * @return array<string, mixed>|null null when no such tenant
     */
    private function census(string $tenant): ?array
    {
        return $this->application->run(static function (ContainerInterface $container) use ($tenant): ?array {
            $inspector = $container->get(TenantInspector::class);
            \assert($inspector instanceof TenantInspector);

            return $inspector->tenant($tenant);
        });
    }
}

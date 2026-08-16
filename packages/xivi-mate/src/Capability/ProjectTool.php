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
use App\ControlPlane\Introspection\UnknownTenant;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Xivi\Mate\Bridge\ApplicationBridge;

/**
 * The three questions this repository cannot answer about itself (XIV-76).
 *
 * Everything here is per installation and per tenant, which is the entire reason
 * these are tools rather than a paragraph in a document. §6.1 makes the
 * customer's own definitions the truth the moment a module is installed and
 * nothing retro-fits a blueprint change afterwards — so an agent that reads
 * `ContactModule.php` and assumes it describes a tenant is reading the *starting*
 * shape and will be wrong in a way nothing tells it about.
 *
 * **Deliberately not a wrapper around the commands.** `bin/console list tenant`
 * already prints nine commands with their descriptions, and wrapping something
 * that describes itself buys ergonomics while doubling the surface to keep in
 * step. What is exposed here is what has no command behind it, or — for the
 * catalogue — what a table in a terminal is a poor shape for and structured data
 * is a good one.
 *
 * Every one of these has a console twin all the same, `tenant:inspect`, so
 * nothing is tool-only. That matters more than it sounds: Mate's server has
 * dropped mid-session on this machine, and an agent told to prefer tools it can
 * no longer see is worse off than one that never had them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ProjectTool
{
    public function __construct(private ApplicationBridge $application)
    {
    }

    /**
     * @param bool $deep whether to open each tenant's database
     */
    #[McpTool(
        name: 'xivi-tenants',
        title: 'Xivi tenants',
        description: 'Every tenant in the control plane: slug, name, status, hostnames, database and role, '
            . 'plus (unless deep=false) whether its schema is current and which modules it has installed. '
            . 'Console equivalent: bin/console tenant:inspect.',
    )]
    public function tenants(bool $deep = true): string
    {
        return $this->encode($this->inspector(...), static fn (TenantInspector $inspector): array => [
            'tenants' => $inspector->tenants($deep),
            // Said out loud rather than left to be inferred from an absent key.
            // An agent that asked the cheap question and reads a null schema as
            // "unreachable" would raise an alarm about a database that is fine.
            'schema_and_modules_probed' => $deep,
        ]);
    }

    /**
     * @param string      $tenant the tenant slug, as shown by xivi-tenants
     * @param string|null $module a single module key, or null for every installed module
     */
    #[McpTool(
        name: 'xivi-tenant-shapes',
        title: 'Xivi tenant shapes',
        description: 'What one tenant\'s installed modules ACTUALLY look like in that tenant\'s own database: '
            . 'every field with its key, type, options, variants, and whether it is derived, system, required, '
            . 'listed or part of the title, plus each collection and its fields. Read this before writing any '
            . 'code against a tenant — a module\'s blueprint in packages/ is only the shape it was installed '
            . 'with, and customers diverge from it. Console equivalent: bin/console tenant:inspect <slug>.',
    )]
    public function shapes(string $tenant, ?string $module = null): string
    {
        return $this->encode($this->inspector(...), static function (TenantInspector $inspector) use ($tenant, $module): array {
            try {
                $shapes = $inspector->shapes($tenant, $module);
            } catch (UnknownTenant $e) {
                // Returned rather than thrown, and it carries the slugs that do
                // exist: this is the mistake an agent makes most often here, and
                // the correction is what stops it guessing a second time.
                return ['error' => $e->getMessage()];
            }

            return [
                'tenant' => $tenant,
                'modules' => $shapes,
                'note' => 'These are the tenant\'s own definitions and are authoritative. The module '
                    . 'blueprints in packages/*/src are the seed they were installed from and may differ.',
            ];
        });
    }

    #[McpTool(
        name: 'xivi-modules',
        title: 'Xivi module catalogue',
        description: 'Every module this build ships and every module the control plane holds a state for: '
            . 'its state (development or published, which is what decides whether the store offers it), '
            . 'whether it is in this build, what it requires, and its presets. This is platform-wide and '
            . 'never per tenant. Console equivalent: bin/console module:list, or tenant:inspect --modules.',
    )]
    public function modules(): string
    {
        return $this->encode($this->inspector(...), static fn (TenantInspector $inspector): array => [
            'modules' => $inspector->modules(),
        ]);
    }

    /**
     * Boots the application, resolves the inspector, formats whatever $describe
     * makes of it.
     *
     * The indirection is only here so that the three tools above are three
     * sentences rather than three copies of the same boot-resolve-encode dance;
     * put it in a base class and the tool classes would stop being readable as a
     * list of what is exposed.
     *
     * @param callable(ContainerInterface):TenantInspector   $resolve
     * @param callable(TenantInspector):array<string, mixed> $describe
     */
    private function encode(callable $resolve, callable $describe): string
    {
        return ResponseEncoder::encode(
            $this->application->run(static fn (ContainerInterface $container): array => $describe($resolve($container))),
        );
    }

    /**
     * The service is public in dev and test and does not exist in production —
     * see `config/services.yaml`, and see `ApplicationBridge` for why an absent
     * one is reported rather than worked around.
     */
    private function inspector(ContainerInterface $container): TenantInspector
    {
        $inspector = $container->get(TenantInspector::class);
        \assert($inspector instanceof TenantInspector);

        return $inspector;
    }
}

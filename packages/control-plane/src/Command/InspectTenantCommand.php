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

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xivi\ControlPlane\Introspection\TenantInspector;
use Xivi\ControlPlane\Introspection\UnknownTenant;

/**
 * What an installation looks like right now, from the console (XIV-76).
 *
 * **This exists so that nothing the MCP tools expose is tool-only.** The three
 * read tools in `xivi/mate` and this command call the same
 * {@see TenantInspector}, so a developer with no MCP server — or with one that
 * has dropped, which happens — can ask every question an agent can ask. That is a
 * constraint the tooling ticket set for itself and it is worth naming here,
 * because the temptation with a tool surface is always to let the interesting
 * capability exist only on the interesting side.
 *
 * `tenant:list` and `module:list` already answer two thirds of this, and this
 * command deliberately does not replace them: they are production commands that
 * print what an operator wants, and they ship. What has never been answerable
 * from a shell is the third — *what shape does this customer's module actually
 * have* — which §6.1 makes unreadable from the repository and which nothing else
 * prints.
 *
 * **Development and test only**, beside the demo commands in
 * `config/services.yaml`. It prints every field definition and every hostname a
 * customer has, which is not a thing to leave lying about in a production image
 * for the sake of a diagnostic the metadata editor (§5.4) already covers from the
 * inside.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AsCommand(
    name: 'tenant:inspect',
    description: 'Show tenants, their schema state and their real field definitions (development only)',
)]
final readonly class InspectTenantCommand
{
    public function __construct(private TenantInspector $inspector)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Tenant slug; every tenant is summarised when omitted')]
        ?string $slug = null,
        #[Argument(description: 'Module key, to narrow a tenant to one shape')]
        ?string $module = null,
        #[Option(description: 'Show the module catalogue and each module\'s state instead')]
        bool $modules = false,
        #[Option(description: 'Print the raw structure as JSON, the way the MCP tools return it')]
        bool $json = false,
    ): int {
        try {
            $data = match (true) {
                $modules => $this->inspector->modules(),
                $slug !== null => $this->inspector->shapes($slug, $module),
                default => $this->inspector->tenants(),
            };
        } catch (UnknownTenant $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // The same structure the tools hand an agent, byte for byte. Not a
        // convenience: it is how somebody debugging a tool result checks whether
        // the tool is wrong or the data is.
        if ($json) {
            $io->writeln(json_encode(
                $data,
                \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ));

            return Command::SUCCESS;
        }

        match (true) {
            $modules => $this->renderModules($io, $data),
            $slug !== null => $this->renderShapes($io, $slug, $data),
            default => $this->renderTenants($io, $data),
        };

        return Command::SUCCESS;
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderTenants(SymfonyStyle $io, array $rows): void
    {
        if ($rows === []) {
            $io->warning('No tenants provisioned yet. bin/console tenant:reset scratch builds one.');

            return;
        }

        $io->table(
            ['Slug', 'Status', 'Hostnames', 'Database', 'Schema', 'Modules'],
            array_map(static fn (array $row): array => [
                (string) $row['slug'],
                (string) $row['status'],
                implode(', ', (array) $row['hostnames']),
                (string) $row['database'],
                self::schemaCell($row),
                \is_array($row['modules'] ?? null) ? (implode(', ', $row['modules']) ?: '—') : '?',
            ], $rows),
        );

        $io->text(' <comment>tenant:inspect <slug></comment> shows what that tenant\'s modules actually look like.');
        $io->newLine();
    }

    /**
     * A schema that is behind is the thing this column exists to make impossible
     * to miss, so it says how far behind rather than merely "no".
     *
     * @param array<string, mixed> $row
     */
    private static function schemaCell(array $row): string
    {
        if (!\is_array($row['schema'] ?? null)) {
            return sprintf('<error>unreachable</error> — %s', $row['unreachable'] ?? 'unknown');
        }

        $schema = $row['schema'];

        if ($schema['up_to_date'] === true) {
            return '<info>current</info>';
        }

        return sprintf(
            '<comment>%d behind</comment> (at %s, latest %s)',
            \count((array) $schema['pending']),
            $schema['current'] ?? 'nothing',
            $schema['latest'] ?? 'none',
        );
    }

    /** @param list<array<string, mixed>> $modules */
    private function renderShapes(SymfonyStyle $io, string $slug, array $modules): void
    {
        if ($modules === []) {
            $io->warning(sprintf('Tenant "%s" has no matching module installed.', $slug));

            return;
        }

        foreach ($modules as $module) {
            $io->section(sprintf('%s — %s', $module['key'], $module['label']));
            $io->definitionList(
                ['Table' => (string) $module['table']],
                ['Variants' => $module['variant_field'] === null
                    ? 'none'
                    : sprintf('%s: %s', $module['variant_field'], implode(', ', array_keys((array) $module['variants'])))],
            );

            $io->table(
                ['Field', 'Type', 'Req', 'Derived', 'System', 'Variants', 'Options'],
                array_map(self::fieldRow(...), (array) $module['fields']),
            );

            foreach ((array) $module['collections'] as $collection) {
                $io->text(sprintf(
                    ' <info>%s</info> (collection, table %s%s)',
                    $collection['key'],
                    $collection['table'],
                    $collection['derived'] === true ? ', derived' : '',
                ));
                $io->table(
                    ['Field', 'Type', 'Req', 'Derived', 'System', 'Variants', 'Options'],
                    array_map(self::fieldRow(...), (array) $collection['fields']),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return list<string>
     */
    private static function fieldRow(array $field): array
    {
        // Options are the per-type settings §5.4 describes — a choice field's
        // choices, a reference's target module — and they are the reason to run
        // this at all. Encoded rather than summarised, because summarising means
        // knowing every field type, which is what the engine is built to avoid.
        $options = $field['options'] === [] ? '' : json_encode($field['options'], \JSON_UNESCAPED_SLASHES);

        return [
            (string) $field['key'],
            (string) $field['type'],
            $field['required'] === true ? 'yes' : '',
            $field['derived'] === true ? 'yes' : '',
            $field['system'] === true ? 'yes' : '',
            implode(', ', (array) $field['variants']),
            (string) $options,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderModules(SymfonyStyle $io, array $rows): void
    {
        if ($rows === []) {
            $io->warning('This build ships no modules.');

            return;
        }

        $io->table(
            ['Key', 'State', 'In store', 'In build', 'Requires', 'Presets'],
            array_map(static fn (array $row): array => [
                (string) $row['key'],
                (string) $row['state'],
                $row['offered_in_store'] === true ? 'yes' : 'no',
                $row['in_this_build'] === true ? 'yes' : 'no',
                implode(', ', (array) ($row['requires'] ?? [])),
                implode(', ', array_map(
                    static fn (array $preset): string => (string) $preset['key'],
                    (array) ($row['presets'] ?? []),
                )) ?: 'every field',
            ], $rows),
        );
    }
}

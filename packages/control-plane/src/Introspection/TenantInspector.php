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

namespace Xivi\ControlPlane\Introspection;

use App\Registry\Catalog\CatalogEntry;
use App\Registry\Catalog\ModuleCatalog;
use App\Registry\Entity\Tenant;
use App\Registry\Repository\TenantRepository;
use App\Tenancy\Dbal\TenantDsnParser;
use App\Tenancy\Migration\TenantMigrator;
use App\Tenancy\TenantSwitcher;
use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\CollectionBlueprint;
use Xivi\Core\Module\FieldBlueprint;
use Xivi\Core\Module\ModuleBlueprint;
use Xivi\Core\Module\ModulePreset;

/**
 * What this installation actually looks like right now, as plain arrays.
 *
 * **Why this exists at all.** §6.1 makes the customer's own definitions the truth
 * the moment a module is installed: the blueprint in code is the *starting* shape
 * and nothing retro-fits it afterwards. So the one question somebody has to
 * answer before writing a line of code against a tenant — what fields does this
 * customer's `contact` have, of which types, with which options — cannot be
 * answered by reading `ContactModule.php`, and cannot be answered from the
 * repository at all. It has to be read out of that customer's database.
 *
 * Everything here is a **read**, and every read goes through the services the
 * application itself uses — `TenantRepository`, `ModuleCatalog`,
 * `MetadataRepository` behind `TenantSwitcher`. Deliberately not one line of SQL:
 * a second way of asking the engine what it holds is a second thing to keep in
 * step with the engine, and it would drift the first time a definition grew a
 * column. What this class contributes is the *shape* of the answer, not the
 * answer.
 *
 * **Arrays rather than objects**, which is unusual for this codebase and is the
 * point: the two callers are a console command that prints a table and an MCP
 * tool that JSON-encodes the result for an agent. A DTO would be a third
 * vocabulary between the entities and the wire, and both callers would immediately
 * flatten it again.
 *
 * **Development and test only** — see the `exclude:` list in
 * `config/services.yaml`. Not because reading is dangerous, but because this
 * hands out every customer's field definitions and hostnames in one call, and the
 * project's own precedent for tooling that has no business near real records is
 * that it does not exist in a production build rather than being guarded by a
 * flag somebody could pass.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantInspector
{
    public function __construct(
        private TenantRepository $tenants,
        private TenantSwitcher $switcher,
        private TenantDsnParser $dsnParser,
        private TenantMigrator $migrator,
        private MetadataRepository $metadata,
        private ModuleCatalog $catalog,
    ) {
    }

    /**
     * Every tenant in the control plane, and — unless asked not to — how each
     * one's database is actually doing.
     *
     * `$deep` exists because the two halves cost wildly different amounts. The
     * registry is one query; the schema version and the installed module list are
     * a connection, a switch and two queries *per tenant*, and the switch drops
     * the metadata cache each time (see {@see TenantSwitcher}). On a handful of
     * dev tenants that is nothing and it is the interesting half, so it is on by
     * default. On an installation with three hundred customers it is not, and the
     * caller that only wants the registry should say so.
     *
     * @return list<array<string, mixed>>
     */
    public function tenants(bool $deep = true): array
    {
        return array_map(
            fn (Tenant $tenant): array => $this->describeTenant($tenant, $deep),
            $this->tenants->findAllOrdered(),
        );
    }

    /**
     * One tenant, or null if no such slug — the caller says what a miss means.
     *
     * @return array<string, mixed>|null
     */
    public function tenant(string $slug, bool $deep = true): ?array
    {
        $tenant = $this->tenants->findOneBySlug($slug);

        return $tenant === null ? null : $this->describeTenant($tenant, $deep);
    }

    /**
     * The shapes one customer actually has: every installed module, its fields,
     * its collections and their fields, exactly as that database holds them.
     *
     * This is the answer §6.1 makes impossible to get from the code, and the
     * reason the rest of this class exists.
     *
     * @param string|null $moduleKey narrows it to one module; null is all of them
     *
     * @return list<array<string, mixed>>
     *
     * @throws UnknownTenant
     */
    public function shapes(string $slug, ?string $moduleKey = null): array
    {
        $tenant = $this->tenants->findOneBySlug($slug) ?? throw UnknownTenant::named($slug, $this->slugs());

        /** @var list<array<string, mixed>> $shapes */
        $shapes = $this->switcher->runFor($tenant, function () use ($moduleKey): array {
            $modules = $moduleKey === null
                ? $this->metadata->all()
                : array_values(array_filter([$this->metadata->find($moduleKey)]));

            return array_map($this->describeModule(...), $modules);
        });

        return $shapes;
    }

    /**
     * The module catalogue: what this build ships, what state the platform has
     * each in, and what each declares about itself.
     *
     * Straight off {@see ModuleCatalog}, which is the one place the build's half
     * and the control plane's half are joined (§6.2) — including the case worth
     * seeing, a state row naming a module this deploy no longer carries.
     *
     * @return list<array<string, mixed>>
     */
    public function modules(): array
    {
        return array_map(
            static function (CatalogEntry $entry): array {
                $row = [
                    'key' => $entry->key,
                    'state' => $entry->state->value,
                    'offered_in_store' => $entry->state->isOfferedInStore(),
                    'in_this_build' => $entry->isInBuild(),
                    // "never" as null rather than as a date nobody set: the
                    // default state is a decision nobody has made yet, and that
                    // is the interesting part (§6.2).
                    'decided_at' => $entry->decision?->getUpdatedAt()->format(\DATE_ATOM),
                ];

                $blueprint = $entry->blueprint;

                if (!$blueprint instanceof ModuleBlueprint) {
                    return $row;
                }

                // The label is a translation key, resolved into the tenant's own
                // definitions at install time (XIV-8). Handed over untranslated
                // on purpose: out here there is no tenant and so no honest
                // language to render it in, and the key is the more useful fact
                // for anybody working on the module.
                return [
                    ...$row,
                    'label_key' => $blueprint->label,
                    'translation_domain' => $blueprint->domain(),
                    'table' => $blueprint->table,
                    'icon' => $blueprint->icon,
                    'variant_field' => $blueprint->variantField,
                    'requires' => $blueprint->requires,
                    'uses' => $blueprint->uses,
                    'default_preset' => $blueprint->defaultPreset,
                    'presets' => array_map(
                        static fn (ModulePreset $preset): array => [
                            'key' => $preset->key,
                            'description' => $preset->description,
                            'fields' => $preset->fields,
                        ],
                        $blueprint->presets,
                    ),
                    // What the module *would* install into a fresh tenant. Named
                    // "blueprint" rather than "fields" because the difference
                    // between these and what a given customer has is the whole
                    // subject of §6.1, and a tool result that blurred the two
                    // would be worse than one that omitted them.
                    'blueprint_field_keys' => array_map(
                        static fn (FieldBlueprint $field): string => $field->key,
                        $blueprint->fields,
                    ),
                    'blueprint_collection_keys' => array_map(
                        static fn (CollectionBlueprint $collection): string => $collection->key,
                        $blueprint->collections,
                    ),
                    'has_lifecycle' => $blueprint->lifecycle !== null,
                    'has_line_totals' => $blueprint->lineTotals !== null,
                    'made_from' => $blueprint->seed?->from,
                ];
            },
            $this->catalog->entries(),
        );
    }

    /** @return list<string> */
    public function slugs(): array
    {
        return array_map(
            static fn (Tenant $tenant): string => $tenant->getSlug(),
            $this->tenants->findAllOrdered(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function describeTenant(Tenant $tenant, bool $deep): array
    {
        $dsn = $tenant->getDatabaseDsn();

        $row = [
            'slug' => $tenant->getSlug(),
            'name' => $tenant->getName(),
            'status' => $tenant->getStatus()->value,
            'serves_requests' => $tenant->getStatus()->servesRequests(),
            'plan' => $tenant->getPlan(),
            'hostnames' => array_values($tenant->getDomains()
                ->map(static fn ($domain): string => $domain->getHostname())
                ->toArray()),
            'primary_hostname' => $tenant->getPrimaryDomain()?->getHostname(),
            // The database and the role, never the DSN: it carries a credential,
            // and a tool result is the least private place in this project — it
            // goes into an agent's context and from there into a transcript.
            // `tenant:provision` makes the same distinction for the same reason.
            'database' => $this->dsnParser->databaseName($dsn),
            'role' => $this->dsnParser->userName($dsn),
            'created_at' => $tenant->getCreatedAt()->format(\DATE_ATOM),
            'provisioned_at' => $tenant->getProvisionedAt()?->format(\DATE_ATOM),
        ];

        if (!$deep) {
            return $row;
        }

        // Both halves are allowed to fail and neither takes the row down with it.
        // Half a tenant — a row whose provisioning died before the database
        // existed — is exactly the state somebody runs this to *find*, and a
        // listing that throws on the broken one is a listing that cannot show you
        // which one is broken. `tenant:deprovision` makes the same allowance
        // where it counts the records it is about to destroy.
        return [...$row, ...$this->probe($tenant)];
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(Tenant $tenant): array
    {
        try {
            /** @var array{schema: array<string, mixed>, modules: list<string>} $probed */
            $probed = $this->switcher->runFor($tenant, function (): array {
                $status = $this->migrator->status();

                return [
                    'schema' => [
                        'current' => $status['current'],
                        'latest' => $status['latest'],
                        'up_to_date' => $status['pending'] === [],
                        'pending' => $status['pending'],
                    ],
                    'modules' => array_map(
                        static fn (ModuleDefinition $module): string => $module->getKey(),
                        $this->metadata->all(),
                    ),
                ];
            });

            return $probed;
        } catch (\Throwable $e) {
            return [
                'schema' => null,
                'modules' => null,
                'unreachable' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function describeModule(ModuleDefinition $module): array
    {
        return [
            'key' => $module->getKey(),
            'label' => $module->getLabel(),
            'icon' => $module->getIcon(),
            'table' => $module->getTableName(),
            'history_table' => $module->getHistoryTableName(),
            'installed_at' => $module->getInstalledAt()->format(\DATE_ATOM),
            // Which field decides the variant, and what the variants are — read
            // off that field's own options, so this says what the engine says
            // rather than a second list that could disagree (§5.5).
            'variant_field' => $module->getVariantField(),
            'variants' => $module->getVariants(),
            'fields' => array_map($this->describeField(...), $module->getFields()->toArray()),
            'collections' => array_map(
                fn (CollectionDefinition $collection): array => [
                    'key' => $collection->getKey(),
                    'label' => $collection->getLabel(),
                    'table' => $collection->getTableName(),
                    'position' => $collection->getPosition(),
                    // Worked out rather than typed into — a document's VAT
                    // breakdown (XIV-16). Read off the fields, as the entity does.
                    'derived' => $collection->isDerived(),
                    'variant_field' => $collection->getVariantField(),
                    'variants' => $collection->getVariants(),
                    'fields' => array_map($this->describeField(...), $collection->getFields()->toArray()),
                ],
                $module->getCollections()->toArray(),
            ),
        ];
    }

    /**
     * Everything on the row, and that is deliberate rather than lazy.
     *
     * A field's `options` is where a choice field's choices live, where a
     * reference field names the module it points at, and where a decimal's scale
     * is — §5.4's per-type settings. Summarising it would mean this class knowing
     * every field type, which is the one thing the engine is built not to require.
     *
     * @return array<string, mixed>
     */
    private function describeField(FieldDefinition $field): array
    {
        return [
            'key' => $field->getKey(),
            'label' => $field->getLabel(),
            'type' => $field->getType(),
            'required' => $field->isRequired(),
            'unique' => $field->isUnique(),
            'filterable' => $field->isFilterable(),
            'listed' => $field->isListed(),
            'title' => $field->isTitle(),
            // Computed, never offered for editing (XIV-20). The flag an agent
            // most needs: writing to a derived field is the mistake that looks
            // like it worked.
            'derived' => $field->isDerived(),
            // Installed by the module itself, so not the customer's to remove.
            'system' => $field->isSystem(),
            'position' => $field->getPosition(),
            // Null means "whatever this kind of field wants" and keeps following
            // it; a number means somebody chose (XIV-43).
            'width' => $field->getWidth(),
            'variants' => $field->getVariants(),
            'options' => $field->getOptions(),
        ];
    }
}

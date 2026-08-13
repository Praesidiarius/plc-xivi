<?php

declare(strict_types=1);

/*
 * Migration configuration for the *tenant* databases.
 *
 * Kept separate from config/packages/doctrine_migrations.yaml, which covers the
 * control plane: the two schemas have nothing in common and each database tracks
 * its own versions. This file is the single definition of the tenant set, read
 * both by App\Tenancy\Migration\TenantMigrator (which applies it per tenant) and
 * by the console when generating one:
 *
 *   TENANT=acme bin/console doctrine:migrations:diff \
 *       --em=tenant --configuration=config/migrations/tenant.php
 *
 * Every change here lands for every customer, so migrations must be
 * expand/contract — never destructive in a single step (docs/architecture.md §4).
 */

return [
    'table_storage' => [
        'table_name' => 'doctrine_migration_versions',
    ],
    'migrations_paths' => [
        'DoctrineMigrations\Tenant' => __DIR__ . '/../../migrations/tenant',
    ],
    'all_or_nothing' => true,
    'transactional' => true,
    'check_database_platform' => true,
];

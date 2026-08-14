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

namespace App\Tenancy\Migration;

use App\Tenancy\TenantContext;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the tenant-side migrations against whichever tenant is currently in
 * context.
 *
 * Tenant migrations are a separate set from the control plane's: the two
 * databases have nothing in common, and a control-plane table appearing inside a
 * customer's database (or the reverse) would be a hard bug to unpick. The
 * DoctrineMigrationsBundle configuration covers the control plane and its console
 * commands; the tenant set is driven from here, because it has to run once per
 * tenant rather than once per deploy.
 *
 * Every schema change lands for every tenant, so tenant migrations must be
 * expand/contract — never destructive in a single step (docs/architecture.md §4).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TenantMigrator
{
    public const string NAMESPACE = 'DoctrineMigrations\\Tenant';

    public function __construct(
        private TenantContext $context,
        // The tenant entity manager is a lazy proxy, so holding it here stays
        // correct across TenantSwitcher::switchTo() resetting the manager.
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Migrates the current tenant's database to the latest version.
     *
     * @return list<string> the executed migration versions
     */
    public function migrateToLatest(): array
    {
        // Reading the tenant is what makes running outside a tenant context an
        // error here rather than a silent migration of the wrong database.
        $tenant = $this->context->getTenant();

        $factory = $this->createDependencyFactory();
        $factory->getMetadataStorage()->ensureInitialized();

        $version = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($version);

        if (\count($plan) === 0) {
            return [];
        }

        $this->logger->info('Migrating tenant "{tenant}" to {version}.', [
            'tenant' => $tenant->getSlug(),
            'version' => (string) $version,
        ]);

        $factory->getMigrator()->migrate(
            $plan,
            (new MigratorConfiguration())->setAllOrNothing(true),
        );

        $executed = [];
        foreach ($plan->getItems() as $item) {
            $executed[] = (string) $item->getVersion();
        }

        return $executed;
    }

    /** The version the tenant databases are expected to be at after a deploy. */
    public function latestAvailableVersion(): string
    {
        return (string) $this->createDependencyFactory()->getVersionAliasResolver()->resolveVersionAlias('latest');
    }

    private function createDependencyFactory(): DependencyFactory
    {
        // The same file the console reads when generating a tenant migration, so
        // "which migrations are the tenant ones" has exactly one answer.
        /** @var array<string, mixed> $config */
        $config = require $this->projectDir . '/config/migrations/tenant.php';

        // Built per call rather than cached: the factory holds on to the schema
        // and metadata storage of the database it first saw, which under a
        // long-running worker would be the previous tenant's (docs/architecture.md §7.4).
        return DependencyFactory::fromEntityManager(
            new ConfigurationArray($config),
            new ExistingEntityManager($this->entityManager),
            $this->logger,
        );
    }
}

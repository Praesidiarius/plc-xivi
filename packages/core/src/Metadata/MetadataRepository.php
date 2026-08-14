<?php

declare(strict_types=1);

namespace Xivi\Core\Metadata;

use Doctrine\ORM\EntityManagerInterface;
use Xivi\Core\Entity\ModuleDefinition;

/**
 * Reads the module and field definitions installed for whichever database the
 * entity manager points at.
 *
 * Deliberately uncached. A per-tenant metadata cache is the obvious next
 * optimisation and also the obvious next way to serve one customer's field
 * definitions to another (§7.4), so it wants a tenant-scoped pool and a test,
 * not a quiet array on this class.
 *
 * Fields are fetch-joined rather than left lazy, and that is a correctness
 * decision, not a performance one. A definition read inside one tenant's context
 * and then touched outside it would load its fields on whatever connection is
 * current — throwing if no tenant is resolved, and quietly querying the *other*
 * customer's database if one is. Returning a fully loaded object means a
 * definition is safe to hold. Definitions are a handful of rows, so this costs
 * nothing.
 *
 * Collections and their own fields are joined for the same reason: a contact's
 * addresses are part of the shape, so "fully loaded" has to mean all of it.
 * Joining two collections at once produces a cartesian product — five fields
 * times five address fields is twenty-five rows, which is nothing, and one query
 * beats three round trips per request. The ORDER BY leads with the module's own
 * fields so that the hydrator meets each collection, and each collection's
 * fields, in the right order the first time.
 */
final readonly class MetadataRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $moduleKey): ?ModuleDefinition
    {
        return $this->entityManager
            ->createQuery(sprintf(
                'SELECT m, f, c, cf FROM %s m
                 LEFT JOIN m.fields f
                 LEFT JOIN m.collections c
                 LEFT JOIN c.fields cf
                 WHERE m.key = :key
                 ORDER BY f.position ASC, f.id ASC, c.position ASC, c.id ASC, cf.position ASC, cf.id ASC',
                ModuleDefinition::class,
            ))
            ->setParameter('key', $moduleKey)
            ->getOneOrNullResult();
    }

    /** @throws ModuleNotInstalled */
    public function get(string $moduleKey): ModuleDefinition
    {
        return $this->find($moduleKey) ?? throw ModuleNotInstalled::named($moduleKey);
    }

    public function isInstalled(string $moduleKey): bool
    {
        return $this->find($moduleKey) !== null;
    }

    /** @return list<ModuleDefinition> */
    public function all(): array
    {
        return $this->entityManager
            ->createQuery(sprintf(
                'SELECT m, f, c, cf FROM %s m
                 LEFT JOIN m.fields f
                 LEFT JOIN m.collections c
                 LEFT JOIN c.fields cf
                 ORDER BY m.key ASC, f.position ASC, f.id ASC, c.position ASC, c.id ASC, cf.position ASC, cf.id ASC',
                ModuleDefinition::class,
            ))
            ->getResult();
    }
}

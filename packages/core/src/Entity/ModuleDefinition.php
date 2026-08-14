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

namespace Xivi\Core\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one module looks like *for this customer*.
 *
 * Metadata is data, not code: a module bundle ships the definitions it needs to
 * function, they are written into the tenant's database when the module is
 * installed, and from then on the customer's copy is theirs to extend
 * (docs/architecture.md §5, §6). Two customers with the same module can have
 * different fields, which is the entire point.
 *
 * A module is the browsable kind of shape: it has a URL, it appears in the
 * navigation, and its records stand on their own. The other kind is
 * CollectionDefinition, which does none of those things.
 *
 * These entities have a fixed shape, so they are ordinary Doctrine entities. The
 * *records* they describe are not — see Xivi\Core\Record\RecordRepository.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity]
class ModuleDefinition extends ShapeDefinition
{
    public const string HISTORY_SUFFIX = '_history';

    /** @var Collection<int, CollectionDefinition> */
    #[ORM\OneToMany(targetEntity: CollectionDefinition::class, mappedBy: 'parent', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $collections;

    public function __construct(
        string $key,
        string $label,
        string $tableName,
        ?string $icon = null,
        ?string $variantField = null,
    ) {
        parent::__construct($key, $label, $tableName, $icon, $variantField);

        $this->collections = new ArrayCollection();
    }

    /**
     * Where this module's history lives (§5.2). One table per module, so
     * `record_id` means exactly one thing and can carry a real foreign key.
     *
     * Derived from the *table* name rather than the module key, so renaming a
     * module still never moves a table — which is why the table name is stored
     * in the first place.
     */
    public function getHistoryTableName(): string
    {
        return $this->getTableName() . self::HISTORY_SUFFIX;
    }

    /** @return Collection<int, CollectionDefinition> */
    public function getCollections(): Collection
    {
        return $this->collections;
    }

    public function addCollection(CollectionDefinition $collection): void
    {
        $this->collections->add($collection);
    }

    public function getCollection(string $key): ?CollectionDefinition
    {
        foreach ($this->collections as $collection) {
            if ($collection->getKey() === $key) {
                return $collection;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function getCollectionKeys(): array
    {
        return array_values(array_map(
            static fn (CollectionDefinition $c): string => $c->getKey(),
            $this->collections->toArray(),
        ));
    }
}

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

    /**
     * Whether this module's records can carry follow-ups (XIV-80).
     *
     * **Here rather than in a table of its own, and that is the seam rather than
     * a shortcut.** "What this customer has, and how it is set up" is already one
     * question with one answer — this row — and a second table keyed by module
     * key would be a second place to say a module exists, going stale the moment
     * anybody uninstalls one. The flag is a fact about one customer's copy of one
     * module, which is the sentence this entity is the definition of.
     *
     * **A flag rather than DDL, which is what makes it reversible.** A preset
     * decides which columns exist and can therefore never be taken back (§6.1);
     * follow-ups create no table per module — one shared pair lives in the tenant
     * database, created by a migration beside `User` — so switching them off is a
     * boolean and switching them back on loses nothing. The store asks at install
     * time as a courtesy, not because that is the last chance.
     *
     * **On by default**, because a module whose records nobody can leave a note
     * on is the surprising one, and because there is no production installation
     * to retro-fit: §7.2 does not have to be answered for this.
     *
     * Core naming the concept at all is deliberate and is the same boundary
     * ModuleAction already crosses — the engine knows there is such a verb as
     * `follow_up_create` without knowing what a user is. It knows here that a
     * module can be opted out of follow-ups, and nothing more: what a follow-up
     * *is* lives in the application, next to the users it names.
     *
     * Nullable in the database because single-table inheritance puts this column
     * on `shape_definition`, where a collection's row has no business carrying it
     * — see the `position` column, which does the same thing pointing the other
     * way.
     */
    #[ORM\Column(name: 'follow_ups_enabled', options: ['default' => true])]
    private bool $followUpsEnabled = true;

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

    /** Whether this customer's copy of this module takes follow-ups (XIV-80). */
    public function hasFollowUps(): bool
    {
        return $this->followUpsEnabled;
    }

    /**
     * Turns them on or off, at any time.
     *
     * No cascade in either direction, and the off case is the one to be clear
     * about: existing follow-ups are left exactly where they are. Switching the
     * feature off says "stop offering this here", not "throw away what people
     * wrote", and a toggle that deleted rows would be a toggle nobody dared use.
     * They become unreachable in the same way a soft-deleted record's do, and
     * switching it back on brings them back.
     */
    public function setFollowUps(bool $enabled): void
    {
        $this->followUpsEnabled = $enabled;
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

<?php

declare(strict_types=1);

namespace Xivi\Core\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A set of fields describing rows in one table, for one customer.
 *
 * There are two kinds, and they differ only in what they are reachable through:
 * a module is browsable in its own right, a collection only ever exists inside
 * the record that owns it (§5). Everything else — field definitions, storage,
 * validation, form building — is the same for both, which is why they share a
 * base rather than the engine growing a second code path for children.
 *
 * That sharing is the claim being tested. If a child collection had needed its
 * own repository, its own validator or its own form type, the engine would have
 * been describing modules rather than describing shapes.
 *
 * Single-table inheritance: the two kinds differ by a handful of columns, and
 * every query that reads definitions wants them together anyway.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shape_definition')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'shape_kind', length: 31)]
#[ORM\DiscriminatorMap(['module' => ModuleDefinition::class, 'collection' => CollectionDefinition::class])]
abstract class ShapeDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, FieldDefinition> */
    #[ORM\OneToMany(targetEntity: FieldDefinition::class, mappedBy: 'shape', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $fields;

    #[ORM\Column]
    private \DateTimeImmutable $installedAt;

    public function __construct(
        /**
         * Unique among modules, and among the collections of one module — see the
         * partial indexes in the migration. Two modules may each have a
         * collection called "addresses"; that is not a collision.
         */
        #[ORM\Column(name: 'shape_key', length: 63)]
        private string $key,
        #[ORM\Column(length: 255)]
        private string $label,
        /**
         * The table its rows live in. Stored rather than derived from the key, so
         * that renaming a shape never has to mean moving a table.
         */
        #[ORM\Column(length: 63)]
        private string $tableName,
    ) {
        $this->fields = new ArrayCollection();
        $this->installedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getInstalledAt(): \DateTimeImmutable
    {
        return $this->installedAt;
    }

    /** @return Collection<int, FieldDefinition> */
    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(FieldDefinition $field): void
    {
        $this->fields->add($field);
    }

    public function removeField(FieldDefinition $field): void
    {
        $this->fields->removeElement($field);
    }

    /** The next free slot at the end, so a new field lands where it was added. */
    public function nextPosition(): int
    {
        $positions = array_map(static fn (FieldDefinition $f): int => $f->getPosition(), $this->fields->toArray());

        return ($positions === [] ? 0 : max($positions)) + 10;
    }

    public function getField(string $key): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->getKey() === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function getFieldKeys(): array
    {
        return array_values(array_map(static fn (FieldDefinition $f): string => $f->getKey(), $this->fields->toArray()));
    }
}

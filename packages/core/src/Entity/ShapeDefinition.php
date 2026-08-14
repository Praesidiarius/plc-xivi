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
    /** For a module that never said. Neutral on purpose — it names nothing. */
    public const string FALLBACK_ICON = 'collection';

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
        /**
         * A Bootstrap Icons name, without the `bi-` prefix. Stored beside the
         * label for the same reason the label is: this is the customer's copy of
         * the module, so what it is called and what it looks like are both
         * theirs. Null falls back, so a module that never declared one still
         * renders.
         */
        #[ORM\Column(length: 63, nullable: true)]
        private ?string $icon = null,
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

    /** Bootstrap Icons name, without the prefix. */
    public function getIcon(): string
    {
        return $this->icon ?? self::FALLBACK_ICON;
    }

    public function setIcon(?string $icon): void
    {
        $this->icon = $icon;
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

    /**
     * The fields a record is called by, in the shape's own order.
     *
     * Here rather than in a controller because the question is asked from more
     * than one place — a record's heading today, and whatever names a record in
     * a link or a picker once §7.6 arrives. Two answers to "what is this record
     * called" would be one too many.
     *
     * The fallback is the old guess, kept for anyone who has not marked a field
     * yet: the required ones, first two, because a record cannot exist without
     * them so they are always there to print. It is wrong for a module whose
     * required fields are not its identifying ones, which is exactly why the
     * flag exists — but a wrong heading beats a blank one.
     *
     * @return list<FieldDefinition>
     */
    public function getTitleFields(): array
    {
        $chosen = [];
        $required = [];

        foreach ($this->fields as $field) {
            if ($field->isTitle()) {
                $chosen[] = $field;
            }

            if ($field->isRequired()) {
                $required[] = $field;
            }
        }

        if ($chosen !== []) {
            return $chosen;
        }

        if ($required !== []) {
            return \array_slice($required, 0, 2);
        }

        $first = $this->fields->first();

        return $first === false ? [] : [$first];
    }

    /** @return list<string> */
    public function getFieldKeys(): array
    {
        return array_values(array_map(static fn (FieldDefinition $f): string => $f->getKey(), $this->fields->toArray()));
    }
}

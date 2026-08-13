<?php

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
 * These entities have a fixed shape, so they are ordinary Doctrine entities. The
 * *records* they describe are not — see Xivi\Core\Record\RecordRepository.
 */
#[ORM\Entity]
#[ORM\Table(name: 'module_definition')]
#[ORM\UniqueConstraint(name: 'uniq_module_definition_key', columns: ['module_key'])]
class ModuleDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, FieldDefinition> */
    #[ORM\OneToMany(targetEntity: FieldDefinition::class, mappedBy: 'module', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $fields;

    #[ORM\Column]
    private \DateTimeImmutable $installedAt;

    public function __construct(
        /** Stable identifier, matching the module bundle that installed it. */
        #[ORM\Column(name: 'module_key', length: 63)]
        private string $key,
        #[ORM\Column(length: 255)]
        private string $label,
        /**
         * The table its records live in. Stored rather than derived from the key,
         * so that renaming a module never has to mean moving a table.
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
        return array_map(static fn (FieldDefinition $f): string => $f->getKey(), $this->fields->toArray());
    }
}

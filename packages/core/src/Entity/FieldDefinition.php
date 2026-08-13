<?php

declare(strict_types=1);

namespace Xivi\Core\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One field on one module, for one customer.
 *
 * This row is the single source of truth §5 asks for: it drives the form, the
 * validation, and where the value is stored. Nothing about a field is declared
 * twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'field_definition')]
#[ORM\UniqueConstraint(name: 'uniq_field_definition_module_key', columns: ['module_id', 'field_key'])]
class FieldDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Per-type settings: maximum length, bounds, and so on. */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ModuleDefinition::class, inversedBy: 'fields')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ModuleDefinition $module,
        /** Also the key inside the record's JSONB payload. */
        #[ORM\Column(name: 'field_key', length: 63)]
        private string $key,
        #[ORM\Column(length: 255)]
        private string $label,
        /** Matches a FieldType in the closed registry. */
        #[ORM\Column(name: 'field_type', length: 63)]
        private string $type,
        #[ORM\Column]
        private bool $required = false,
        /** Enforced by a validator that queries the module's table, not by an index — yet. */
        #[ORM\Column(name: 'is_unique')]
        private bool $unique = false,
        #[ORM\Column]
        private bool $filterable = false,
        #[ORM\Column]
        private int $position = 0,
        /**
         * True for fields the module itself installed. Customers may add fields;
         * removing the ones a module's own code relies on is a different question
         * (§7.2) and is refused for now.
         */
        #[ORM\Column(name: 'is_system')]
        private bool $system = false,
    ) {
        $module->addField($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModule(): ModuleDefinition
    {
        return $this->module;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @param array<string, mixed> $options */
    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }
}

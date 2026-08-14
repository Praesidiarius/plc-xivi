<?php

declare(strict_types=1);

namespace Xivi\Core\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One field on one shape, for one customer.
 *
 * This row is the single source of truth §5 asks for: it drives the form, the
 * validation, and where the value is stored. Nothing about a field is declared
 * twice.
 *
 * A field does not care whether the shape it belongs to is a module or one of
 * its collections — an address's street is described exactly like a contact's
 * surname, by the same row in the same table with the same field type behind it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'field_definition')]
#[ORM\UniqueConstraint(name: 'uniq_field_definition_shape_key', columns: ['shape_id', 'field_key'])]
class FieldDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Per-type settings: maximum length, bounds, and so on.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ShapeDefinition::class, inversedBy: 'fields')]
        #[ORM\JoinColumn(name: 'shape_id', nullable: false, onDelete: 'CASCADE')]
        private ShapeDefinition $shape,
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
        /**
         * Whether the list shows a column for it. A UI hint, not a rule about
         * the value: everything is still on the record and in the form. Without
         * it every field a customer adds widens the table until nothing is
         * readable, which is a strange punishment for using the engine.
         */
        #[ORM\Column(name: 'is_listed')]
        private bool $listed = true,
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
        $shape->addField($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShape(): ShapeDefinition
    {
        return $this->shape;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    /**
     * There is deliberately no setType().
     *
     * Changing a field's type is the hard half of §7.2 — stored values may not
     * survive the new one, and "convert what you can" is data loss on a click.
     * Refusing it structurally beats a setter that throws: the editor cannot
     * offer a control it has no way to honour.
     */
    public function getType(): string
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): void
    {
        $this->required = $required;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function setUnique(bool $unique): void
    {
        $this->unique = $unique;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    public function isListed(): bool
    {
        return $this->listed;
    }

    public function setListed(bool $listed): void
    {
        $this->listed = $listed;
    }

    public function setFilterable(bool $filterable): void
    {
        $this->filterable = $filterable;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
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

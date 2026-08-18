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
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Module\AdditionKind;

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
 *
 * @author Praesidiarius <praesidiarius@proton.me>
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

    /**
     * What this customer has said no to, when the blueprint offered it (XIV-70).
     *
     * **The design question of that ticket, answered here.** An upgrade offers
     * what the blueprint has and this shape has not, and the engine cannot tell
     * two absences apart: a field the customer deleted on purpose looks exactly
     * like one they never had, because §5.4's removal takes the definition and
     * leaves nothing else behind. Guessing either way is wrong in a way somebody
     * notices — guess "never had" and the offer nags them about a decision they
     * already made, every time, for ever; guess "deleted" and a field a preset
     * left out is invisible to the customer who now wants it.
     *
     * So nothing is guessed and nothing is inferred after the fact. **A decision
     * is written down at the moment it is made**, which is the only moment it is
     * unambiguous: dismissing an addition on the upgrade screen writes it here,
     * and so does removing a field (see {@see \Xivi\Core\Metadata\MetadataEditor::removeField()}),
     * because deleting something is as clear an answer to "do you want this" as
     * declining it. An addition nobody has answered about is offered; one that
     * is in here is not, until the customer asks to see what they dismissed and
     * takes it back.
     *
     * **On this row rather than in a table of its own**, for the reason
     * {@see ModuleDefinition::$followUpsEnabled} gives at length: "what this
     * customer has, and how it is set up" is already one question with one
     * answer, and a second table keyed by shape would be a second place to say a
     * shape exists. It also gets the lifetime right for free — uninstalling a
     * module takes its declines with it, and a fresh install is a fresh choice
     * rather than one haunted by what somebody said about the last one.
     *
     * Keyed by {@see AdditionKind} because the two namespaces are separate: a
     * module may declare both a field and a collection called `addresses`, and a
     * flat list would silently let one answer the other. Null is the ordinary
     * state and means nothing has been declined — the column is nullable rather
     * than defaulted so that the migration adding it only ever adds (§4.2).
     *
     * @var array<string, list<string>>|null
     */
    #[ORM\Column(name: 'declined_additions', type: 'json', nullable: true)]
    private ?array $declined = null;

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
        /**
         * The key of the choice field that decides which variant a record is
         * (§5.5) — `kind` on a contact, whose options are person and company.
         *
         * Null for a shape whose records are all the same thing, which is most
         * of them. Naming a field rather than holding a list of variants means
         * there is one place the variants are written down: that field's options.
         */
        #[ORM\Column(length: 63, nullable: true)]
        private ?string $variantField = null,
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

    /**
     * Renaming a shape is a presentation change and nothing more (XIV-8).
     *
     * The key stays put, because that is what the table and every stored value
     * are named by — the same split §5.4 makes for a field, for the same reason.
     * What this exists for is the customer whose module was installed with an
     * English label: their data, their word for it.
     */
    public function setLabel(string $label): void
    {
        $label = trim($label);

        if ($label !== '') {
            $this->label = $label;
        }
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

    public function getVariantField(): ?string
    {
        return $this->variantField;
    }

    public function setVariantField(?string $variantField): void
    {
        $this->variantField = $variantField;
    }

    public function hasVariants(): bool
    {
        return $this->variantField !== null && $this->getVariants() !== [];
    }

    /**
     * The variants this shape has, as value => label.
     *
     * Read off the choice field itself, so adding a variant is adding an option
     * to that field and nothing else has to agree with it.
     *
     * @return array<string, string>
     */
    public function getVariants(): array
    {
        if ($this->variantField === null) {
            return [];
        }

        $field = $this->getField($this->variantField);

        return $field === null ? [] : ChoiceFieldType::choicesOf($field);
    }

    /**
     * Which variant a record is, from its own values.
     *
     * @param array<string, mixed> $data
     */
    public function variantOf(array $data): ?string
    {
        if ($this->variantField === null) {
            return null;
        }

        $value = $data[$this->variantField] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The fields that apply to one variant, in the shape's own order.
     *
     * With no variant field this is every field, which is why everything that
     * renders or validates a record can call it without asking whether this
     * shape has variants at all.
     *
     * @return list<FieldDefinition>
     */
    public function getFieldsFor(?string $variant): array
    {
        $fields = [];

        foreach ($this->fields as $field) {
            if ($field->appliesTo($variant)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    public function getInstalledAt(): \DateTimeImmutable
    {
        return $this->installedAt;
    }

    /**
     * Whether this customer has already answered "no" to one addition (XIV-70).
     *
     * The whole of what the upgrade asks of this row, and it is deliberately a
     * question about a *key* rather than about a definition: the thing being
     * asked about does not exist here, which is why it is being offered at all.
     */
    public function hasDeclined(AdditionKind $kind, string $key): bool
    {
        return \in_array($key, $this->declined[$kind->value] ?? [], true);
    }

    /**
     * Remember that they said no.
     *
     * Idempotent, because the two callers can both reach the same key: somebody
     * can dismiss an addition on the upgrade screen, take it back, add a field
     * of that name themselves and then remove it again. Recording it twice would
     * be a list that grows for ever and reads the same either way.
     */
    public function decline(AdditionKind $kind, string $key): void
    {
        if ($this->hasDeclined($kind, $key)) {
            return;
        }

        $declined = $this->declined ?? [];
        $declined[$kind->value][] = $key;
        // Reassigned rather than mutated in place: Doctrine compares the array
        // it read with the array it finds at flush, and a nested value changed
        // through a reference is a change it can miss.
        $this->declined = $declined;
    }

    /**
     * And that they have changed their mind, which has to be possible or the
     * first answer is a trap.
     *
     * §5.4 makes removing a field reversible by leaving the values behind, and
     * this is the same promise one level up: dismissing an addition hides an
     * offer, it does not refuse it for ever. The screen keeps a list of what was
     * dismissed for exactly this reason — a decision nobody can see is not a
     * decision, it is a disappearance.
     */
    public function restore(AdditionKind $kind, string $key): void
    {
        $declined = $this->declined ?? [];
        $declined[$kind->value] = array_values(array_filter(
            $declined[$kind->value] ?? [],
            static fn (string $declined): bool => $declined !== $key,
        ));

        $this->declined = $declined;
    }

    /**
     * Everything declined on this shape, by kind.
     *
     * Read by the upgrade to build the "dismissed" half of its screen, and by
     * nothing else. Returns the empty map rather than null so that a caller
     * never has to know that null and "nothing" are the same thing here.
     *
     * @return array<string, list<string>>
     */
    public function getDeclined(): array
    {
        return $this->declined ?? [];
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

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
 *
 * @author Praesidiarius <praesidiarius@proton.me>
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

    /**
     * Which variants of the shape this field belongs to (§5.5).
     *
     * Empty means all of them, which is the common case and the default — a
     * contact's email is a contact's email whether it is a person or a company.
     * A non-empty list scopes the field: `['person']` on a first name means a
     * company neither shows it nor is required to fill it in.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $variants = [];

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
        /**
         * Whether this field is part of what a record is *called* — the heading
         * on its page, and whatever names it in a link or a picker later.
         *
         * Its own flag because nothing else answers the question. Guessing from
         * "required" gets a contact right and an invoice wrong, and it ties the
         * name to field order, so reordering fields would silently rename every
         * record.
         */
        #[ORM\Column(name: 'is_title')]
        private bool $title = false,
        #[ORM\Column]
        private int $position = 0,
        /**
         * True for fields the module itself installed. Customers may add fields;
         * removing the ones a module's own code relies on is a different question
         * (§7.2) and is refused for now.
         */
        #[ORM\Column(name: 'is_system')]
        private bool $system = false,
        /**
         * Computed rather than typed (XIV-20). Shown on the form and never
         * offered for editing, because a value somebody can type over is not
         * derived — it is a default with extra steps.
         */
        #[ORM\Column(name: 'is_derived')]
        private bool $derived = false,
        /**
         * How wide to draw it, in twelfths of a row (XIV-43).
         *
         * **Null is not the same as the type's number.** Null means "whatever
         * this kind of field wants", and keeps following it — so improving what
         * a `text` field defaults to reaches every field nobody has an opinion
         * about. A stored value means somebody chose, and is left alone.
         *
         * The same promise `User::locale` makes with null, for the same reason.
         */
        #[ORM\Column(type: 'smallint', nullable: true)]
        private ?int $width = null,
        /**
         * Which heading this field is drawn under, or null for none (XIV-119).
         *
         * **The membership is a property of the field, and it had to be.** A
         * field already carries its own order and its own width — everything
         * about where it lands on the page — so a container holding a list of
         * fields would be a second place deciding the same thing, free to
         * disagree with the first. Naming the section from here means an
         * ungrouped field is simply one that names none, which is every field in
         * every tenant on the day this arrived: null is both the default and the
         * whole of the migration.
         *
         * What is *not* here is the section itself. Its name and its order live
         * on {@see ModuleDefinition::$sections}, because a section has to be
         * able to exist while it is still empty and neither of those two facts
         * can be stored on a field that does not exist yet.
         *
         * **This is presentation and reaches nothing else.** The value is stored
         * under the same key, validated by the same rules, filtered by the same
         * query and named by the same document marker whatever this says. A
         * `field_definition` row is the single source of truth for all of that
         * (§5), and this column is the single source of truth for none of it.
         */
        #[ORM\Column(name: 'section_key', length: 63, nullable: true)]
        private ?string $section = null,
        /**
         * Whether this field's values are also drawn at the top of the record
         * page, beside the module label and the lifecycle state ([XIV-173]).
         *
         * Some values are what a record *is* rather than something it merely
         * has: the tags on a contact, the region an order belongs to. Reading
         * them should not mean finding the right row in a form of twenty-five,
         * so a field may say that its values belong up there as well.
         *
         * **On the field rather than on the list it points at, and that is the
         * decision this column exists to record.** It was asked for as an option
         * on the shared list, which is the wrong home for one reason that
         * settles it: a list is shared across modules on purpose (§5.26), so an
         * option on the list would decide for Contacts and Orders at once, and
         * the first customer wanting tags at the top of a contact but not of an
         * order would have to fork a shared list to say so. A list is about
         * values a business keeps: what they are called, what colour they are,
         * what they merge into. *Where they are drawn* is a property of the
         * field that uses them, which is where every other display decision here
         * already lives: {@see self::$listed}, {@see self::$filterable},
         * {@see self::$width}, {@see self::$position} and the section above.
         *
         * Rejected with it: the same option on the list as an overridable
         * default, which would be two places holding one answer and free to
         * disagree, which is the argument the section makes one docblock up.
         *
         * **Presentation, and nothing else**, on exactly the section's terms.
         * The value is stored under the same key, validated by the same rules,
         * filtered by the same query and named by the same document marker
         * whatever this says. It is also an *addition* rather than a move: a
         * promoted field is still drawn in the read view below and is still
         * edited on the form, because promoting it out of the section somebody
         * deliberately put it in (XIV-119) would be a rearrangement nobody
         * asked for.
         *
         * Not every type may be promoted. The header draws chips, and a chip is
         * honest only for a value out of a closed set the customer keeps, so
         * {@see \Xivi\Core\Metadata\MetadataEditor} refuses this on a type that
         * does not {@see \Xivi\Core\Field\Enumerates} and the editor does not
         * draw the box. Keyed on the capability rather than on a type's name, so
         * the next enumerating type inherits the answer.
         */
        #[ORM\Column(name: 'is_promoted')]
        private bool $promoted = false,
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

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * The hard half of §7.2, and for four generations of this entity it was not
     * here at all ([XIV-146]).
     *
     * What used to stand in this place was a docblock explaining that there is
     * deliberately no setter, because stored values may not survive a new type
     * and "convert what you can" is data loss on a click. The first half of that
     * is still true and is the reason this one is written the way it is; the
     * second half was the thing XIV-146 had to stop being the only answer.
     * Legality is the tenant's data's to decide rather than a table of type
     * pairs, so the engine now converts every row through the new type's own
     * reading, behind a dry run, and refuses the whole change when any row fails
     * it.
     *
     * **So the guarantee moved rather than went away.** It used to be that no
     * code anywhere could change this column; it is now that
     * {@see \Xivi\Core\Metadata\FieldTypeConversion} is the only thing that
     * calls this, and it does so inside the transaction that has already
     * rewritten every value the change reaches. A caller that reaches for this
     * setter on its own has changed what a column *means* and left every record
     * in it spelled the old way, which is the state the missing setter existed
     * to prevent and is still not a state anything may produce.
     */
    public function setType(string $type): void
    {
        $this->type = $type;
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

    /** Part of what the record is called; see the constructor. */
    /** @return list<string> */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /** @param list<string> $variants */
    public function setVariants(array $variants): void
    {
        $this->variants = array_values(array_unique($variants));
    }

    /**
     * Whether this field is part of the given variant.
     *
     * A field scoped to variants does not apply when the variant is unknown:
     * showing a company's fields on a record nobody has said is a company would
     * be guessing, and validating them would be worse.
     */
    public function appliesTo(?string $variant): bool
    {
        if ($this->variants === []) {
            return true;
        }

        return $variant !== null && \in_array($variant, $this->variants, true);
    }

    public function isTitle(): bool
    {
        return $this->title;
    }

    public function setTitle(bool $title): void
    {
        $this->title = $title;
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

    /**
     * The width somebody chose, or null to follow the field type's.
     *
     * Deliberately not resolved here: doing that would mean this entity holding
     * the type registry, and a definition row is data rather than something that
     * looks things up. Whoever draws the form asks the type (§5).
     */
    public function getWidth(): ?int
    {
        return $this->width;
    }

    /** @param int|null $width 1-12, or null to follow the field type's default */
    public function setWidth(?int $width): void
    {
        $this->width = $width === null ? null : max(1, min(12, $width));
    }

    /** The heading this field is drawn under, or null for none; see the constructor. */
    public function getSection(): ?string
    {
        return $this->section;
    }

    /**
     * Deliberately unvalidated here.
     *
     * Whether a section exists on this field's shape is a question about the
     * shape, and this entity is data rather than something that looks things up
     * — the same reason {@see self::getWidth()} does not resolve the type's
     * default. {@see \Xivi\Core\Metadata\MetadataEditor::updateField()} refuses a
     * key the shape has never heard of, on the write path, where the console and
     * an import meet the same rule.
     */
    public function setSection(?string $section): void
    {
        $section = $section === null ? null : trim($section);

        $this->section = $section === '' ? null : $section;
    }

    /** Whether the values are also drawn at the top of the record page; see the constructor. */
    public function isPromoted(): bool
    {
        return $this->promoted;
    }

    /**
     * Deliberately unvalidated here, on {@see self::setSection()}'s terms.
     *
     * Whether this field's *type* may be promoted is a question about the type
     * registry, and a definition row is data rather than something that looks
     * things up. {@see \Xivi\Core\Metadata\MetadataEditor} refuses it on the
     * write path, where the console and an import meet the same rule.
     */
    public function setPromoted(bool $promoted): void
    {
        $this->promoted = $promoted;
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

    public function isDerived(): bool
    {
        return $this->derived;
    }

    public function setDerived(bool $derived): void
    {
        $this->derived = $derived;
    }

    public function getOption(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }
}

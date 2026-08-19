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
use Xivi\Core\ValueList\ValueIcon;
use Xivi\Core\ValueList\ValueTone;

/**
 * One value in a shared list (XIV-127).
 *
 * A row rather than a string, and everything this ticket adds over a `choice`
 * field's own options follows from that: a colour, a picture, a parent, and a
 * count of the records holding it that can be asked for without reading a JSON
 * blob out of every field definition in the database.
 *
 * ## The value is derived from the label, once, and then frozen
 *
 * Exactly {@see \Xivi\Core\Field\Type\ChoiceFieldType::valueFor()}'s rule, and
 * deliberately the same code: what every record holds is the **value**, what
 * every page shows is the **label**, and renaming the label moves no record.
 * XIV-144 settled this for a field's own options and §5.4 says a shared list is
 * the same question with more records behind it, so it is the same answer rather
 * than a second one.
 *
 * ## The colour and the picture are enums
 *
 * See {@see ValueTone} and {@see ValueIcon} for why they are not free strings.
 * They are stored as the enum's own backed value rather than mapped as
 * `enumType`, because a row hand-edited to hold a word neither enum knows must
 * still *load* — a page that throws while rendering a badge is a worse outcome
 * than a badge that renders plain.
 *
 * ## The parent is one level deep
 *
 * {@see ValueList::possibleParents()} has the argument. A parent may not itself
 * have one, so a cycle is not something a guard has to catch.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity]
#[ORM\Table(name: 'value_list_entry')]
class ValueListEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The colour, as {@see ValueTone}'s backed value, or null for none.
     *
     * A plain string column and not `enumType`, for the reason in the class
     * docblock: Doctrine's enum mapping throws on a value the enum does not
     * know, and the value that would throw is one nothing in the application can
     * write — so the only way to reach it is a hand-edited row, and the honest
     * response to one of those is to draw the entry without a colour.
     */
    #[ORM\Column(length: 31, nullable: true)]
    private ?string $tone = null;

    /** The picture, as {@see ValueIcon}'s backed value, or null. Same reasoning as the tone. */
    #[ORM\Column(length: 63, nullable: true)]
    private ?string $icon = null;

    /**
     * The entry this one sits under, or null for a root.
     *
     * `SET NULL` rather than cascade: an entry that loses its parent is still a
     * perfectly good entry and every record holding it is still valid, whereas
     * deleting the children with the parent would delete values records hold
     * from underneath them — which is the one thing §5.4 refuses everywhere.
     * Removing a parent that has children is refused anyway
     * ({@see \Xivi\Core\ValueList\ValueListEditor}); this column says what the
     * database would do if something ever got past that, and the answer is the
     * survivable one.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ValueList::class, inversedBy: 'entries')]
        #[ORM\JoinColumn(name: 'list_id', nullable: false, onDelete: 'CASCADE')]
        private ValueList $list,
        /** What every record holds. Derived from the first label and never touched again. */
        #[ORM\Column(name: 'entry_value', length: 63)]
        private string $value,
        #[ORM\Column(length: 255)]
        private string $label,
        #[ORM\Column]
        private int $position = 0,
    ) {
        $list->addEntry($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getList(): ValueList
    {
        return $this->list;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getTone(): ?ValueTone
    {
        return ValueTone::tryOf($this->tone);
    }

    public function setTone(?ValueTone $tone): void
    {
        $this->tone = $tone?->value;
    }

    public function getIcon(): ?ValueIcon
    {
        return ValueIcon::tryOf($this->icon);
    }

    public function setIcon(?ValueIcon $icon): void
    {
        $this->icon = $icon?->value;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * The label as a picker should draw it: a child indented under its parent.
     *
     * An en dash and a space rather than markup, because the one place this has
     * to work is inside an `<option>`, which renders no markup and collapses
     * leading whitespace. It is the oldest trick for a tree in a select and it
     * is still the only one that needs no scripting.
     */
    public function pickerLabel(): string
    {
        return $this->parent === null ? $this->label : '– ' . $this->label;
    }
}

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
 * A list a customer maintains once and more than one field takes its values
 * from (XIV-127).
 *
 * "Our regions", "our payment terms", "our topics". Before this, a `choice`
 * field owned its own options (§5.4), which is right for a closed set that
 * belongs to one field — an order's status, a contact's kind — and wrong for a
 * vocabulary the business has: *Region* on a contact and *Region* on an order
 * were two unrelated lists of strings that drifted apart the moment somebody
 * edited one, and nothing anywhere could tell they were meant to be the same
 * list.
 *
 * ## Why this is a core concept and not a module
 *
 * A module is the unit a customer **installs** (§6.3): it has a store entry, a
 * price, permissions, records people browse and a table of its own. A list of
 * regions is none of those — nobody opens a region, files a follow-up against
 * one or exports them as a spreadsheet.
 *
 * The argument that actually decides it is §3's: **a module may not depend on
 * another module.** A list several modules' fields point at can therefore only
 * live where all of them can see it, which is core — the same reasoning §5.20
 * used to put the seven units in `Xivi\Core\Field\Units` rather than in the
 * article module, one level up. A "lists" module would be a module every other
 * module secretly required.
 *
 * ## The key is not the label
 *
 * The same split as everything else here (§5.4): the **key** is what a field
 * definition names and is derived from the label once, and the **label** is what
 * the page says and is freely renamable. Renaming "Regions" to "Verkaufsgebiete"
 * must not orphan the fields pointing at it.
 *
 * ## What is deliberately not here
 *
 * **No many-to-many.** The previous system's equivalent linked its values onto
 * any entity through a join table, so one record could hold several. That is
 * [XIV-113]'s question — a field holding more than one value — and it is
 * answered once there rather than twice in two shapes. A field takes *one* value
 * out of this list, exactly as a `choice` field always has; whatever [XIV-113]
 * builds points at the same list.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity]
#[ORM\Table(name: 'value_list')]
class ValueList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The entries, in the order the customer put them in.
     *
     * Ordered by position and then id, exactly as a shape orders its fields, so
     * that two entries a customer never reordered still come out in the order
     * they were typed rather than in whatever order Postgres feels like.
     *
     * @var Collection<int, ValueListEntry>
     */
    #[ORM\OneToMany(targetEntity: ValueListEntry::class, mappedBy: 'list', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $entries;

    public function __construct(
        /**
         * What a field definition names, and what nothing renames.
         *
         * Boring in the same way a field key is — lowercase ASCII, digits and
         * underscores — because it is stored inside a field's options blob and
         * turns up in an export column and a filter URL.
         */
        #[ORM\Column(name: 'list_key', length: 63)]
        private string $key,
        #[ORM\Column(length: 255)]
        private string $label,
    ) {
        $this->entries = new ArrayCollection();
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

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    /** @return Collection<int, ValueListEntry> */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function addEntry(ValueListEntry $entry): void
    {
        if (!$this->entries->contains($entry)) {
            $this->entries->add($entry);
        }
    }

    public function removeEntry(ValueListEntry $entry): void
    {
        $this->entries->removeElement($entry);
    }

    /** One entry by the value records hold, or null. */
    public function getEntry(string $value): ?ValueListEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->getValue() === $value) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Every entry, in the order a picker should offer them: each root followed
     * by its own children (§5.4's hierarchy decision).
     *
     * Built here rather than in the template because the flattening is the same
     * for the form, the list page and the merge plan, and a template that did it
     * would be three templates that did it slightly differently.
     *
     * @return list<ValueListEntry>
     */
    public function inTreeOrder(): array
    {
        $ordered = [];

        foreach ($this->entries as $entry) {
            if ($entry->getParent() !== null) {
                continue;
            }

            $ordered[] = $entry;

            foreach ($this->entries as $child) {
                if ($child->getParent() === $entry) {
                    $ordered[] = $child;
                }
            }
        }

        return $ordered;
    }

    /**
     * The entries a parent may be chosen from: the ones that are not themselves
     * a child.
     *
     * **One level of nesting, and that is a decision.** "Category and
     * sub-category" is what a customer means by a parent, and it is what the
     * previous system's lists were used for. Arbitrary depth buys a tree widget,
     * a cycle check and a recursive query, and every one of those is a cost paid
     * by the reader of this class rather than by the customer who wanted their
     * forty regions grouped under five countries. Cycles are impossible by
     * construction rather than by a guard, which is the part worth having.
     *
     * @return list<ValueListEntry>
     */
    public function possibleParents(): array
    {
        $roots = [];

        foreach ($this->entries as $entry) {
            if ($entry->getParent() === null) {
                $roots[] = $entry;
            }
        }

        return $roots;
    }

    /**
     * The values every entry holds, which is what a field's validator compares
     * against.
     *
     * @return list<string>
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->entries as $entry) {
            $values[] = $entry->getValue();
        }

        return $values;
    }

    /**
     * value => label, in tree order, as a picker should draw them.
     *
     * Deliberately the same shape a `choice` field's own options have, so that
     * everything downstream of {@see \Xivi\Core\Field\Type\ChoiceFieldType} —
     * the widget, the demo generator, the export — cannot tell whether the list
     * came from the field or from here.
     *
     * The labels are the *picker's* labels, so a child arrives indented. That is
     * the whole of what a hierarchy does to a form (§5.4): it is read, not
     * queried.
     *
     * @return array<string, string>
     */
    public function asChoices(): array
    {
        $choices = [];

        foreach ($this->inTreeOrder() as $entry) {
            $choices[$entry->getValue()] = $entry->pickerLabel();
        }

        return $choices;
    }

    /**
     * The same map with the labels as the customer typed them.
     *
     * What a *cell* shows, as against what a *picker* offers: an indent is a
     * useful hint in a dropdown of forty regions and noise in a table column, so
     * `display()` reads this one and the widget reads the one above.
     *
     * @return array<string, string> value => label
     */
    public function labels(): array
    {
        $labels = [];

        foreach ($this->inTreeOrder() as $entry) {
            $labels[$entry->getValue()] = $entry->getLabel();
        }

        return $labels;
    }
}

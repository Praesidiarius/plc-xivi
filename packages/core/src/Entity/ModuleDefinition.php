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
use Xivi\Core\Metadata\FieldGroup;
use Xivi\Core\Metadata\Section;

/**
 * What one module looks like *for this customer*.
 *
 * Metadata is data, not code: a module bundle ships the definitions it needs to
 * function, they are written into the tenant's database when the module is
 * installed, and from then on the customer's copy is theirs to extend
 * (docs/architecture/data-model.md §5, §6). Two customers with the same module can have
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

    /**
     * The headings this customer has put on their form, keyed by section key
     * (XIV-119).
     *
     * **A section is presentation, so it is stored as part of how this module is
     * drawn rather than as a thing records can be grouped by.** §5.1's
     * collections are already the second way of grouping records and are a very
     * different promise: a collection has a table, rows, its own field
     * definitions and a foreign key back here. A section has a word and a
     * number. Giving it a table would give somebody something to join to, and
     * the first join is the moment it stops being presentation — so it has no
     * table, no id and nothing that can point at it but a string on a field.
     *
     * **On this row rather than in a table of its own** is also
     * {@see self::$followUpsEnabled}'s argument, unchanged: "what this customer
     * has, and how it is set up" is one question with one answer, and a second
     * table keyed by shape would be a second place to say a shape exists. The
     * lifetime comes free with it — uninstalling a module takes its headings
     * with it.
     *
     * **A section is a value with an order of its own**, and that order cannot
     * be inferred from the fields in it: a section is empty for as long as it
     * takes somebody to create it and then move a field into it, and a heading
     * that vanished between those two clicks would be a control that appeared
     * not to work. So it carries a `position` exactly as a field does, in tens,
     * set on the same kind of numeric control.
     *
     * **Here rather than on {@see ShapeDefinition}**, which is the one place
     * this feature is narrower than the editor. A collection's fields are drawn
     * as a row inside a form and as a *table row* on the record page, and a
     * table row has nowhere to put a heading — so a section offered on a
     * collection would be a control that did nothing on half the pages it
     * appeared on, which is the defect XIV-144 is named after. Sections are
     * offered on the module's own shape and nowhere else.
     *
     * Null is the ordinary state and means nobody has made one; the column is
     * nullable rather than defaulted so that the migration adding it only ever
     * adds (§4.2), and so that every definition in every tenant is untouched by
     * its arrival.
     *
     * @var array<string, array{label: string, position: int}>|null
     */
    #[ORM\Column(name: 'sections', type: 'json', nullable: true)]
    private ?array $sections = null;

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

    /**
     * The headings on this module's form, in the order they are drawn
     * (XIV-119).
     *
     * Sorted by position and then by key, which is the same tie-break the fields
     * get from `position ASC, id ASC` — two sections a customer never
     * distinguished have to come out in the same order on every request, or a
     * form rearranges itself between two readings of the same page.
     *
     * @return list<Section>
     */
    public function getSections(): array
    {
        $sections = [];

        foreach ($this->sections ?? [] as $key => $stored) {
            $sections[] = Section::from((string) $key, $stored);
        }

        usort(
            $sections,
            static fn (Section $a, Section $b): int => [$a->position, $a->key] <=> [$b->position, $b->key],
        );

        return $sections;
    }

    public function getSection(string $key): ?Section
    {
        $stored = $this->sections[$key] ?? null;

        return $stored === null ? null : Section::from($key, $stored);
    }

    public function hasSection(string $key): bool
    {
        return \array_key_exists($key, $this->sections ?? []);
    }

    /**
     * Replaces the lot, because every writer here has the whole list in hand.
     *
     * Reassigned rather than mutated in place, for the reason
     * {@see ShapeDefinition::decline()} spells out: Doctrine compares the array
     * it read with the array it finds at flush, and a nested value changed
     * through a reference is a change it can miss.
     *
     * An empty list is stored as null rather than as `{}`, so a customer who
     * makes a section and then deletes it leaves a row indistinguishable from
     * one that never had one — the same state, said the same way.
     *
     * @param list<Section> $sections
     */
    public function setSections(array $sections): void
    {
        $stored = [];

        foreach ($sections as $section) {
            $stored[$section->key] = $section->stored();
        }

        $this->sections = $stored === [] ? null : $stored;
    }

    /** The next free slot at the end, in tens, exactly as a field's is. */
    public function nextSectionPosition(): int
    {
        $positions = array_map(static fn (Section $s): int => $s->position, $this->getSections());

        return ($positions === [] ? 0 : max($positions)) + 10;
    }

    /**
     * How the form and the record page draw one variant's fields (XIV-119).
     *
     * **The single grouping decision in the product.** Two templates read these
     * definitions and both call this, because the alternative — each of them
     * sorting for itself — is how a form in four sections ends up beside a
     * record page in one flat list, which the ticket rightly says would be worse
     * than not grouping at all.
     *
     * The order is: **the ungrouped fields first, then each section by its own
     * position.** Ungrouped first is the decision that costs an existing
     * customer nothing. Every field in every tenant is ungrouped today, so a
     * shape with no sections yields exactly one group holding every field in
     * `getFieldsFor()`'s order — the flat run that has always been drawn — and
     * the first section somebody creates appends a heading below what they
     * already had rather than pushing twenty-two fields down the page.
     *
     * **A field naming a section that does not exist is ungrouped, not
     * missing.** Nothing here can produce that state — removing a section clears
     * the fields that named it, in the same transaction — but an import or a
     * hand-written definition can, and a field that silently vanished from the
     * form would be a field whose value nobody could edit any more. Falling back
     * to the top of the page is visible and harmless.
     *
     * **A section with no fields draws nothing.** It still exists, and the
     * editor still lists it — that is what lets somebody make one before filling
     * it — but a heading with nothing under it on a record page is noise about
     * an editing session that has finished.
     *
     * @return list<FieldGroup>
     */
    public function getFieldGroupsFor(?string $variant): array
    {
        $ungrouped = [];
        /** @var array<string, list<FieldDefinition>> $grouped */
        $grouped = [];

        foreach ($this->getFieldsFor($variant) as $field) {
            $key = $field->getSection();

            if ($key === null || !$this->hasSection($key)) {
                $ungrouped[] = $field;

                continue;
            }

            $grouped[$key][] = $field;
        }

        $groups = $ungrouped === [] ? [] : [new FieldGroup(null, $ungrouped)];

        foreach ($this->getSections() as $section) {
            if (($grouped[$section->key] ?? []) !== []) {
                $groups[] = new FieldGroup($section, $grouped[$section->key]);
            }
        }

        return $groups;
    }
}

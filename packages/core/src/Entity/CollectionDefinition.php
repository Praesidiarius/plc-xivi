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
 * Many rows belonging to one record of a module — a contact's addresses.
 *
 * The honest description of what these are: they have no life of their own.
 * There is no URL for an address, nothing in the navigation, and no way to reach
 * one except through the contact that owns it. They are created, edited and
 * deleted with their parent.
 *
 * That is deliberately *not* the same thing as a link between two modules, where
 * both sides exist independently and either can be browsed. Conflating the two
 * is how a CRM ends up with orphaned addresses nobody can find. When
 * module-to-module links arrive they are a different mechanism, and §5's
 * "relations stay relational" covers both.
 *
 * Stored relationally, per §5: its own table, its own rows, a real foreign key
 * to the parent. Not a JSON array on the parent record — that is exactly the
 * shape both EAV and JSON are bad at, and it would make "contacts in Zürich"
 * unanswerable.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[ORM\Entity]
class CollectionDefinition extends ShapeDefinition
{
    /**
     * The column in *this* shape's table holding the parent's id. Always
     * `parent_id`: a collection has exactly one parent, and naming the column
     * after the parent module would be prettier while saying less.
     */
    public const string PARENT_COLUMN = 'parent_id';

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ModuleDefinition::class, inversedBy: 'collections')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ModuleDefinition $parent,
        string $key,
        string $label,
        string $tableName,
        /** Where it sits among its siblings in the parent's form. */
        #[ORM\Column]
        private int $position = 0,
    ) {
        parent::__construct($key, $label, $tableName);

        $parent->addCollection($this);
    }

    public function getParent(): ModuleDefinition
    {
        return $this->parent;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}

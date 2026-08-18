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

namespace Xivi\Core\Module;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;

/**
 * One thing a customer's installed module could take from its blueprint and has
 * not (XIV-70, §7.2.1).
 *
 * It is deliberately both halves at once — what to *say* about the addition and
 * what to *do* about it. The blueprint it came from is carried rather than
 * looked up again when somebody accepts, because the alternative is resolving
 * the same key against the same registry in two places and having to keep the
 * two resolutions agreeing; the offer somebody reads and the thing that gets
 * installed are then the same object, which is the property that matters on a
 * screen whose whole job is to promise what will happen.
 *
 * The label is translated when the offer is built, not stored: this is a page,
 * not a definition. What ends up in the customer's own definitions is translated
 * separately at the moment it is written, by the installer, exactly as it would
 * have been on the day the module was installed (§6.1).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleAddition
{
    private function __construct(
        public AdditionKind $kind,
        /** The installed shape it would land on — the module itself, or one of its collections. */
        public ShapeDefinition $shape,
        public string $key,
        /** As this customer would read it, in the language they are reading the offer in. */
        public string $label,
        public FieldBlueprint|CollectionBlueprint $blueprint,
    ) {
    }

    public static function field(ShapeDefinition $shape, FieldBlueprint $field, string $label): self
    {
        return new self(AdditionKind::Field, $shape, $field->key, $label, $field);
    }

    /**
     * A collection lands on the module, always: a collection of a collection is
     * not a thing the engine has (§5.1), so the parent is never in question.
     */
    public static function collection(ModuleDefinition $module, CollectionBlueprint $collection, string $label): self
    {
        return new self(AdditionKind::Collection, $module, $collection->key, $label, $collection);
    }

    /**
     * How a form names this one addition, and how it is found again on the way
     * back.
     *
     * The shape's **id** rather than its key, because keys are only unique
     * within their own kind: a module and one of its collections may both be
     * called `contact`, and a token that could not tell them apart would let a
     * posted form aim an addition at the wrong shape. Ids are what every other
     * form in the metadata editor already round-trips for exactly this reason.
     *
     * Nothing is trusted on the way back in — {@see ModuleUpgrade} matches a
     * token against the offers it has just computed for *this* module, so a
     * hand-edited one names nothing and is ignored rather than obeyed.
     */
    public function token(): string
    {
        return sprintf('%s:%d:%s', $this->kind->value, (int) $this->shape->getId(), $this->key);
    }

    /** The field type key, or null for a collection, which has no single one. */
    public function type(): ?string
    {
        return $this->blueprint instanceof FieldBlueprint ? $this->blueprint->type : null;
    }

    /**
     * Whether the engine fills this one in rather than a person (§5.9).
     *
     * Worth saying on the offer screen, because a derived field arriving on
     * records that already exist arrives **empty** and stays that way until each
     * record is next saved. Nothing here guesses a plausible value: a total, a
     * due date or a document number that this code invented would look right and
     * be wrong, and derived values belong to whatever derives them (XIV-73).
     */
    public function isDerived(): bool
    {
        return $this->blueprint instanceof FieldBlueprint && $this->blueprint->derived;
    }

    /** Whether the blueprint asks for it to be required — which existing records may refuse. */
    public function isRequired(): bool
    {
        return $this->blueprint instanceof FieldBlueprint && $this->blueprint->required;
    }

    /** Whether the blueprint asks for it to be unique, same caveat. */
    public function isUnique(): bool
    {
        return $this->blueprint instanceof FieldBlueprint && $this->blueprint->unique;
    }

    /** How many fields a collection brings with it; zero for a field, which is one of itself. */
    public function fields(): int
    {
        return $this->blueprint instanceof CollectionBlueprint ? \count($this->blueprint->fields) : 0;
    }
}

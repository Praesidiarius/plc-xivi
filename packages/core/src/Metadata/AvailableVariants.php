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

namespace Xivi\Core\Metadata;

use Xivi\Core\Entity\CollectionDefinition;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\Type\ReferenceFieldType;
use Xivi\Core\Module\ModuleRegistry;

/**
 * Which kinds a customer can actually create (XIV-23).
 *
 * A shape's variants are what its definitions say; these are the ones that can
 * be filled in here. An order's article line needs an article to point at, and a
 * customer who sells only services has no articles module — so the kind is not
 * offered rather than offered and then refused by validation with an empty
 * picker, which is the same information delivered worse.
 *
 * The rule is narrow on purpose: **a variant is unavailable when one of its
 * *required* fields is a reference into a module this customer has not
 * installed.** Not optional fields, because a link nobody has to fill in is a
 * link that can stay empty; and not other kinds of missing, because a reference
 * whose module exists and whose record was deleted is §7.6's stale link and
 * already reads as one.
 *
 * ### And a kind the engine writes for itself (XIV-104)
 *
 * There is a second reason a kind is not offered, and it is a different kind of
 * reason: a discount line is **generated**. It is worked out on every save from
 * the voucher the document names ({@see \Xivi\Core\Money\DerivesTotals}), so a
 * row of that kind added by hand is a row the next save takes straight back out
 * again — and a button that adds one is a button that promises something the
 * engine then quietly undoes.
 *
 * It is answered here rather than in the form for the reason this class exists at
 * all: "which kinds can be created" is one question, the record form and the
 * kind chooser both ask it, and a second list drawn somewhere else would be a
 * second answer. The kind itself stays a perfectly ordinary option on the
 * customer's own variant field — it has to, because rows of it exist and have to
 * render, and because §5.5 is explicit that the variants *are* the field's
 * options and there is no second list anywhere that could disagree with it.
 * What is filtered is only what somebody is offered.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class AvailableVariants
{
    public function __construct(
        private MetadataRepository $metadata,
        // Which kind of row a module says the engine writes for itself. The
        // registry rather than the customer's definitions, and deliberately so:
        // a `LineTotals` declaration is part of what the *module* is, whereas the
        // definitions are what this customer has made of it (§6.1). A customer
        // renaming the "Discount" label does not stop the engine owning the rows.
        private ModuleRegistry $modules,
    ) {
    }

    /**
     * The shape's variants, minus the ones nothing could point at.
     *
     * @return array<string, string> variant key => label, as the shape lists them
     */
    public function of(ShapeDefinition $shape): array
    {
        $available = [];

        $generated = $this->generatedKindOf($shape);

        foreach ($shape->getVariants() as $variant => $label) {
            if ($variant !== $generated && $this->canBeFilledIn($shape, $variant)) {
                $available[$variant] = $label;
            }
        }

        return $available;
    }

    private function canBeFilledIn(ShapeDefinition $shape, string $variant): bool
    {
        foreach ($shape->getFieldsFor($variant) as $field) {
            if (!$this->isReachable($field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The kind of row this shape's own module says the engine generates, if it
     * says so at all (XIV-104).
     *
     * Only ever a collection's, because only a collection has lines: a module's
     * variants are what a record *is* — a person or a company — and nothing
     * generates one of those.
     */
    private function generatedKindOf(ShapeDefinition $shape): ?string
    {
        if (!$shape instanceof CollectionDefinition) {
            return null;
        }

        $key = $shape->getParent()->getKey();
        $totals = $this->modules->has($key) ? $this->modules->get($key)->lineTotals : null;

        return $totals !== null && $totals->collection === $shape->getKey() ? $totals->discountKind : null;
    }

    private function isReachable(FieldDefinition $field): bool
    {
        if ($field->getType() !== 'reference' || !$field->isRequired()) {
            return true;
        }

        return $this->metadata->find(ReferenceFieldType::targetModule($field)) !== null;
    }
}

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

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\Type\ReferenceFieldType;

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
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class AvailableVariants
{
    public function __construct(private MetadataRepository $metadata)
    {
    }

    /**
     * The shape's variants, minus the ones nothing could point at.
     *
     * @return array<string, string> variant key => label, as the shape lists them
     */
    public function of(ShapeDefinition $shape): array
    {
        $available = [];

        foreach ($shape->getVariants() as $variant => $label) {
            if ($this->canBeFilledIn($shape, $variant)) {
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

    private function isReachable(FieldDefinition $field): bool
    {
        if ($field->getType() !== 'reference' || !$field->isRequired()) {
            return true;
        }

        return $this->metadata->find(ReferenceFieldType::targetModule($field)) !== null;
    }
}

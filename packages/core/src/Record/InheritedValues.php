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

namespace Xivi\Core\Record;

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\Type\ReferenceFieldType;

/**
 * Filling in what a row takes from the record it points at, and noticing when it
 * has since drifted from it (XIV-18).
 *
 * Two halves of one declaration ({@see InheritedValue}), and they belong
 * together because they must agree about which fields are involved: a copy
 * nobody can tell has gone stale is worse than no copy, and a staleness warning
 * about a field that was never copied is noise.
 *
 * **Copied once, on the way in.** Not read through on every render: an order
 * confirmed at 19.90 says 19.90 next year, and it says it even after the article
 * is deleted. That is a property of the document rather than an optimisation.
 *
 * **And never over something typed.** An empty field is one nobody has answered;
 * a filled one is somebody's decision, including a decision that happens to
 * differ from the article's. Which is exactly what {@see self::driftedIn()} is
 * for — telling the two apart is the reader's problem, and the page should not
 * make them guess.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class InheritedValues
{
    /**
     * The target records come from the memo three readers share (XIV-54), not
     * from the repository directly.
     *
     * This class asks the same question the reference field asks — what is at
     * the other end of this link — about the same rows, on the same page: the
     * record page draws a line's article *and* checks whether the price copied
     * off it has drifted. Read separately that was two lookups per row of the
     * same article, and the drift half was the one with no memo at all, so an
     * order with 500 lines asked about each of its articles twice and got a
     * bounded page for neither. One memo makes the second question free and
     * makes both of them free once the page has primed.
     */
    public function __construct(private ReferenceTargets $targets)
    {
    }

    /**
     * The values, with anything inheritable and empty filled in from the record
     * it points at.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function fillIn(ShapeDefinition $shape, array $values): array
    {
        foreach ($shape->getFields() as $field) {
            $inherit = InheritedValue::of($field);

            if ($inherit === null || !self::isEmpty($values[$field->getKey()] ?? null)) {
                continue;
            }

            $source = $this->sourceOf($shape, $inherit, $values);

            if ($source !== null && !self::isEmpty($source->get($inherit->field))) {
                $values[$field->getKey()] = $source->get($inherit->field);
            }
        }

        return $values;
    }

    /**
     * The fields whose value no longer matches the record they came from.
     *
     * A negotiated price and a copy taken before the article went up look
     * identical on the page, and the difference between them is a conversation
     * with a customer. Saying which fields differ is the least this can do about
     * that; deciding what it *means* is the reader's.
     *
     * @param array<string, mixed> $values
     *
     * @return list<FieldDefinition>
     */
    public function driftedIn(ShapeDefinition $shape, array $values): array
    {
        $drifted = [];

        foreach ($shape->getFields() as $field) {
            $inherit = InheritedValue::of($field);

            if ($inherit === null) {
                continue;
            }

            $source = $this->sourceOf($shape, $inherit, $values);

            if ($source === null) {
                // Nothing to compare against: the article is gone, or was never
                // named. A stale link is its own thing and the reference field
                // says so itself (§7.6).
                continue;
            }

            $theirs = $source->get($inherit->field);
            $ours = $values[$field->getKey()] ?? null;

            // Loose, and deliberately: a price is a decimal string on one side
            // and may be a float on the other, and "19.90 differs from 19.9"
            // would be a warning about nothing.
            if (!self::isEmpty($theirs) && (string) $ours !== (string) $theirs) {
                $drifted[] = $field;
            }
        }

        return $drifted;
    }

    /**
     * The record a row's reference field points at, if it points anywhere this
     * customer has.
     *
     * @param array<string, mixed> $values
     */
    private function sourceOf(ShapeDefinition $shape, InheritedValue $inherit, array $values): ?Record
    {
        $reference = $shape->getField($inherit->reference);
        $id = $values[$inherit->reference] ?? null;

        if ($reference === null || !is_numeric($id)) {
            return null;
        }

        // Null covers a link into a module the customer does not have (§3) as
        // well as one at a record that is gone. Nothing to inherit and nothing
        // to compare in either case, and neither is an error.
        return $this->targets->of(ReferenceFieldType::targetModule($reference), (int) $id);
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}

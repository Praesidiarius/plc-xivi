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

/**
 * "Take this from the record I point at, once" (XIV-18).
 *
 * An order line names an article and shows its description and its price — but
 * only as they were when the line was written. A price change next month must
 * not rewrite an order confirmed this month, and the article being deleted must
 * not empty it. So the values are **copied**, and the reference is kept beside
 * them so reporting still knows what was sold.
 *
 * **Declared, not coded.** The alternative was the order module carrying a hook
 * that fills in its own lines, and a module with code in it is the thing this
 * engine exists to avoid (§1). Written as an option on the field, it costs the
 * module one line and works for any field of any shape that points anywhere.
 *
 * The copy happens once, when the value is empty. After that the field belongs
 * to the line: a negotiated price is an edit, not a defect, which is why nothing
 * ever copies over a value somebody has typed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class InheritedValue
{
    public const string OPTION = 'inherit';

    private function __construct(
        /** The sibling field holding the reference to take from. */
        public string $reference,
        /** The field of the referenced record to take. */
        public string $field,
    ) {
    }

    /**
     * As a field's options, for a blueprint to spread into its own.
     *
     * @return array{inherit: array{reference: string, field: string}}
     */
    public static function from(string $reference, string $field): array
    {
        return [self::OPTION => ['reference' => $reference, 'field' => $field]];
    }

    /** What this field inherits, or null when it inherits nothing. */
    public static function of(FieldDefinition $field): ?self
    {
        $option = $field->getOption(self::OPTION);

        if (!\is_array($option) || !\is_string($option['reference'] ?? null) || !\is_string($option['field'] ?? null)) {
            return null;
        }

        return new self($option['reference'], $option['field']);
    }
}

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

namespace Xivi\Core\Query;

/**
 * The comparisons a filter may ask for (§7.3).
 *
 * A closed set, like the field types are. The compiler only ever emits SQL for
 * shapes it owns, which is what keeps "filtering" from becoming a string a
 * caller hands over to be concatenated into a WHERE clause.
 *
 * Which of these a given field accepts is the field type's business: asking
 * whether a date contains "ar" is not a question worth answering.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum Operator: string
{
    case Equals = 'eq';
    case NotEquals = 'neq';
    case Contains = 'contains';
    case StartsWith = 'starts';
    case GreaterThan = 'gt';
    case AtLeast = 'gte';
    case LessThan = 'lt';
    case AtMost = 'lte';
    case IsEmpty = 'empty';
    case IsNotEmpty = 'filled';

    /** The two that are about presence rather than about a value. */
    public function needsValue(): bool
    {
        return !\in_array($this, [self::IsEmpty, self::IsNotEmpty], true);
    }

    /**
     * A key in the `xivi` domain, not a word.
     *
     * The engine's own vocabulary is translated by the engine's own catalogue
     * (XIV-8), so it reads in the customer's language without the application
     * having to know what an operator is. Reads as a comparison in a filter row,
     * not as a sentence.
     */
    public function labelKey(): string
    {
        return 'operator.' . $this->value;
    }
}

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
 * One piece of text, looked for across several of a shape's fields (XIV-36).
 *
 * **This is the one disjunction the query layer has, and it is not the OR §5.3
 * refused.** That refusal was about `OR` *between conditions* — a tree, and a UI
 * to build one, for a feature nobody had asked for. This is a closed shape with
 * no tree in it: one string, a fixed set of fields chosen by the engine rather
 * than by a request, ANDed with everything else in the query like any other
 * condition. Nothing about it composes, which is exactly why it can exist
 * without deciding the harder question.
 *
 * It exists because somebody typing into a reference picker is looking for a
 * record by *name*, and a name is built from a shape's title fields (§5.4) —
 * plural, and that is the whole difficulty. A contact is a first name and a last
 * name, and a search that could only look in one of them would find Ada by
 * "Ada" and not by "Lovelace", which is not a search anybody would describe as
 * working.
 *
 * The fields are keys of the shape being queried and are resolved against the
 * customer's definitions like any other, so nothing here reaches SQL except as a
 * bound parameter (§5.3). A field whose type cannot answer "contains" is skipped
 * rather than refused: a module named partly by a date should still be findable
 * by the half of its name that is text.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Search
{
    /**
     * @param string       $text   what somebody typed, matched case-insensitively
     *                             anywhere in a value
     * @param list<string> $fields the field keys to look in, in no particular
     *                             order — a disjunction has none
     */
    public function __construct(
        public string $text,
        public array $fields,
    ) {
    }

    /**
     * Whether this can narrow anything.
     *
     * An empty string is not a search for the empty string, it is the absence of
     * one — which is what a picker's first load sends before anybody has typed,
     * and it should come back with the ordinary first page rather than with
     * everything matching `%%`. Naming the case here keeps the compiler from
     * having to know it.
     */
    public function isEmpty(): bool
    {
        return trim($this->text) === '' || $this->fields === [];
    }
}

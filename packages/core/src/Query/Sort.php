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
 * One ordering: a field, and which way (§7.3).
 *
 * Deliberately has no room for a collection. "Sort contacts by address city" has
 * no answer when a contact has two addresses, and a type that cannot express the
 * question is better than a compiler that quietly picks one of them. The
 * compiler refuses a path that names a collection rather than guessing.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Sort
{
    public function __construct(
        public string $field,
        public Direction $direction = Direction::Ascending,
    ) {
    }

    public function reversed(): self
    {
        return new self($this->field, $this->direction->opposite());
    }
}

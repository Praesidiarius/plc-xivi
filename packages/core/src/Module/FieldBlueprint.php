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

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class FieldBlueprint
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public bool $required = false,
        public bool $unique = false,
        public bool $filterable = false,
        /** Shown as a column on the list. A module's own fields are, by default. */
        public bool $listed = true,
        /** Part of what a record is called — the heading on its page. */
        public bool $title = false,
        /**
         * Which variants of the shape this field belongs to (§5.5). Empty — the
         * default — means all of them.
         *
         * @var list<string>
         */
        public array $variants = [],
        public int $position = 0,
        public array $options = [],
    ) {
    }
}

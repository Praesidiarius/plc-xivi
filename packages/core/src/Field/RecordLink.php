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

namespace Xivi\Core\Field;

/**
 * A record another value points at, and enough to reach its page (XIV-42).
 *
 * A module key and an id, which is all a link needs and deliberately all it
 * carries: building the URL is the application's business, since routes are not
 * something the engine knows about.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordLink
{
    public function __construct(
        public string $module,
        public int $id,
    ) {
    }
}

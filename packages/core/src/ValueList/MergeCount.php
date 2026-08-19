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

namespace Xivi\Core\ValueList;

/**
 * One line of a merge plan: a field, and how many of its records a merge would
 * rewrite (XIV-127).
 *
 * Fields with **nothing** to rewrite are kept rather than filtered out, which is
 * deliberate and is the difference between a plan and a summary. Somebody about
 * to merge "Zurich" into "Zürich" is being asked to agree to a change across
 * their whole installation, and "Orders → Region: none" is information: it says
 * this reaches orders too, and that today there is nothing there. Dropping it
 * would let the same page be read as "this only touches contacts", which is true
 * this afternoon and not a property of the change.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class MergeCount
{
    public function __construct(
        public ValueListUse $use,
        public int $records,
    ) {
    }
}

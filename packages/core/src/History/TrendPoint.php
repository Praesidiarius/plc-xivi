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

namespace Xivi\Core\History;

/**
 * One value a field held, and the moment it started holding it (XIV-121).
 *
 * **The moment a value *started*, not the moment it was observed.** That is the
 * whole of the model and it is what makes a line drawn from these read as a
 * price rather than as a scatter of edits: a price is not a measurement taken at
 * intervals, it is a step function that holds until somebody changes it. So a
 * point says "from here on, this", and the segment leading *away* from it is
 * flat. A renderer that joins these with straight diagonals is drawing a price
 * that drifted continuously between two edits, which never happened.
 *
 * **A float, and the loss is deliberate and bounded.** Money is stored as an
 * exact decimal string precisely so that it never goes near a float (§5.9), and
 * everything that adds money up keeps it that way. This is not that: it is an
 * ordinate on a chart, which is a rendering and not an arithmetic. Nothing is
 * summed here, nothing is compared for equality with a stored total, and the
 * result is thrown away with the page. What is gained is one type that a
 * currency, a decimal and an integer all arrive as, so that plotting a quantity
 * is the same code as plotting a price — which is the point of the ticket.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TrendPoint
{
    public function __construct(
        public \DateTimeImmutable $at,
        public float $value,
    ) {
    }
}

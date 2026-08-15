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

/**
 * A {@see ValueDeriver} that may also be run over values nobody is saving
 * (XIV-32).
 *
 * The live form works its figures out by running the derivers over what is
 * currently typed — which is the point, because it is how the preview and the
 * save cannot disagree about arithmetic. What that assumes is that running a
 * deriver *costs nothing*, and one of them costs something real: `AssignsNumbers`
 * takes the next document number out of a sequence, and a sequence does not give
 * one back. Previewing without this distinction allocated a number **per
 * keystroke** — the order somebody was typing ended up as ORD-2026-0247, and the
 * suite caught it as an off-by-one it very nearly was not.
 *
 * So the seam is opt-in, and deliberately in that direction: a deriver is left
 * out of previews unless it says otherwise. A new one that quietly writes
 * something is then wrong in the safe direction — a figure that does not update
 * until save, rather than a side effect fired at typing speed.
 *
 * **What qualifies:** the deriver is a pure function of the values it is handed.
 * It may read definitions, it may do arithmetic, and it may not allocate,
 * write, send, or otherwise leave a mark that outlives the request.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface SafeToPreview
{
}

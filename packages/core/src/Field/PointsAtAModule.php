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
 * A field type whose values are ids in another module, and whose fields say
 * which one (XIV-144).
 *
 * The **fifth** capability, and the second that is not optional — a `reference`
 * with no target module has nowhere to look a record up, so it renders `#41`
 * where a name should be and offers an empty picker. That is an empty
 * {@see Enumerates} list wearing different clothes.
 *
 * **The answer is harder to change than it is to give**, which is the one thing
 * this capability has that the others do not. An id is only meaningful in the
 * module it was chosen from: repointing a populated field at another module
 * leaves every stored id addressing a row that is either somebody else's record
 * or nothing at all, and no amount of care afterwards can tell which. Nothing
 * would report it — the ids are valid integers and the pages would simply name
 * the wrong records — so the editor refuses the change while records hold a
 * value rather than warning about it (§5.4).
 *
 * The **variant** beside it is not part of this. It narrows the candidates
 * within the target module and a field that says nothing about it offers all of
 * them, which makes it exactly the kind of optional setting the first three
 * capabilities describe; it has no control today, and moving the target clears
 * it, because a variant is a value of the *old* module's variant field and means
 * nothing in the new one.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface PointsAtAModule extends NeedsAnAnswer
{
}

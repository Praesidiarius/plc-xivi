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

use Xivi\Core\Query\Filter;

/**
 * Where a module's own list is, narrowed to a filter (XIV-178).
 *
 * The third of these and the same seam every time: core states what it needs and
 * the application answers with a route, because a route name is the
 * application's to choose. {@see RecordSearchUrl} asks where a picker searches,
 * {@see RecordPageUrl} asks where one record's page is, and this asks where the
 * list of many of them is.
 *
 * **The caller that made it necessary is a module's index body** (XIV-178). A
 * body that shows the first few of something has to be able to point past its
 * own ceiling, and the honest place to point is §5.3's list with the same
 * narrowing applied. No new route, no second way of asking the question, and the
 * filter bar above it reads the parameters straight back into the filter the
 * body ran. But that address is spelled `module_index`, and a template or a
 * service inside `packages/knowledge` may see `Xivi\Core\` and nothing else
 * (§3). Spelling it there would be the boundary leaking out through the one file
 * deptrac cannot read, which is the exact class of coupling `RecordPageUrl` was
 * created for.
 *
 * **A path rather than an absolute URL**, on `RecordSearchUrl`'s argument: the
 * page doing the linking was served under this tenant's own hostname (§4), so a
 * path reaches the right customer's data by construction and nothing has to
 * decide on a host.
 *
 * **It says nothing about whether the reader may open what it names.** Whoever
 * calls this got its records out of a query carrying the reader's own
 * {@see \Xivi\Core\Permission\RecordAccess}, and the list at the far end
 * compiles that predicate again; a reader who may see nothing under the filter
 * lands on an empty list rather than on somebody else's records.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface RecordListUrl
{
    /**
     * The address of this module's list, narrowed and optionally asked for as
     * rows.
     *
     * **`$asRows` is a property of the page, not of the caller's layout**, which
     * is why it is a boolean here and a URL parameter over there rather than
     * something a module spells. §5.3's index draws whatever body the module
     * offers ({@see IndexBodyProvider}); saying `true` here is saying *give me
     * the plain list instead*, and the page decides how that is written down.
     *
     * A body that has more than it is showing needs it, and the reason is
     * narrower than it looks: narrowing a page that has a body gives back the
     * same body with the same ceiling on it, so a "and 37 more" link would point
     * at itself. Asking for rows lands on the paged, sorted table the index has
     * always been, with its column headers and its pager.
     *
     * @param string       $moduleKey the module whose list this is
     * @param list<Filter> $filters   what to narrow it to, in the shape the
     *                                filter bar itself submits, so the list at
     *                                the far end reads them back rather than
     *                                treating them as a second dialect
     * @param bool         $asRows    whether to ask for the plain list rather
     *                                than whatever the module draws
     */
    public function forModule(string $moduleKey, array $filters, bool $asRows): string;
}

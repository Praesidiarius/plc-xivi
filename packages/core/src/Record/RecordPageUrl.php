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
 * Where one record's own page is (XIV-66).
 *
 * The same seam as {@see RecordSearchUrl} beside it, one question over: core asks
 * where a record lives and the application answers with a route, because a route
 * name is the application's to choose and a class in a package that spelled
 * `module_show` itself would only work under an application that happened to
 * spell it the same way.
 *
 * **Why this was needed at all, since templates already do it.** The application's
 * own templates build that path inline — `_record_link.html.twig` is the oldest —
 * and that is fine, because they are the application. A dashboard widget shipped
 * by `packages/invoice` is not: it is a module, it may see `Xivi\Core\` and
 * nothing else (§3), and its whole value is that "12 unpaid invoices" becomes
 * twelve links somebody can act on. Twelve links need twelve URLs, so the module
 * either learns a route name — which is the boundary leaking through Twig, where
 * deptrac cannot see it — or asks for one. It asks.
 *
 * **A path rather than an absolute URL**, for the reason `RecordSearchUrl` gives:
 * the page doing the linking was served under this tenant's own hostname (§4), so
 * a path reaches the right customer's data by construction, and picking a host
 * would mean either reading it back off the request or configuring it — a second
 * place for the hostname to be wrong.
 *
 * **It says nothing about whether the reader may open what it names**, and that
 * is deliberate rather than an omission. Whether a link should be offered at all
 * is §7.6's question and XIV-42 answered it: a record somebody may not view
 * answers 404, so an anchor there sends them to a page saying the thing does not
 * exist. The caller decides; this only builds the address. Every caller here got
 * its records out of a query that already carried the reader's own
 * {@see \Xivi\Core\Permission\RecordAccess}, so the records it is naming are ones
 * it was allowed to be handed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface RecordPageUrl
{
    /**
     * @param string $moduleKey the module the record belongs to
     * @param int    $id        the record's id within that module
     */
    public function forRecord(string $moduleKey, int $id): string;
}

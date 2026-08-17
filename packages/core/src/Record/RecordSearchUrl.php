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
 * Where a widget goes to search a module's records (XIV-36).
 *
 * A URL is the one thing an autocompleting picker needs that core cannot know.
 * Routes belong to the application (§3): core is handed a connection and a set
 * of definitions and has never learned what an HTTP route is, and a form type
 * building a path out of a hard-coded string would be the boundary quietly gone
 * — the engine would then only work under an application that happened to name
 * its route the same way.
 *
 * So core declares the question and the application answers it, exactly as
 * `InstanceCurrency` does for the currency an installation works in and
 * `RecordAccessProvider` does for what somebody may see. Same shape, same
 * binding in `config/services.yaml`, same consequence: the engine states what it
 * needs and never reaches for it.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface RecordSearchUrl
{
    /**
     * The address that answers with candidates of this module, optionally
     * narrowed to one variant.
     *
     * The typed text is *not* a parameter here. The widget appends its own
     * `query=` and pages the result by following what the response hands back,
     * so what this returns is the endpoint rather than one search of it.
     */
    public function forModule(string $moduleKey, ?string $variant): string;
}

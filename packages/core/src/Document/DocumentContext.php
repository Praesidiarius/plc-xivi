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

namespace Xivi\Core\Document;

/**
 * What a document can say that has nothing to do with the record (XIV-4).
 *
 * Who this installation is, and who is generating the document — facts about the
 * moment rather than about the contact being written to. Core cannot know either:
 * it is handed a connection and never learns what a tenant or a user is, the same
 * boundary `InstanceCurrency` and `PermissionSet` keep.
 *
 * So the application answers, and it answers with whole markers rather than with
 * values against a fixed list of keys — which is what lets a new general marker
 * be added without core being touched at all.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface DocumentContext
{
    /**
     * The general markers, named and filled in.
     *
     * Called while a document is being generated *and* while the reference list
     * is being drawn, so it must answer outside a request too: nobody signed in
     * is an empty value, not a failure.
     *
     * @return list<DocumentMarker>
     */
    public function markers(): array;
}

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

    /**
     * The bytes behind the image markers, for the ones that have any right now
     * (XIV-89).
     *
     * A second method rather than bytes hanging off a `DocumentMarker`, and the
     * reason is who calls what. {@see self::markers()} is called every time the
     * reference panel is drawn — twice per templates page — and is about the
     * *vocabulary*; this is called once, while a document is being generated,
     * and is about one installation's data. Half a megabyte of PNG travelling
     * through the first of those to be discarded by every one of its callers is
     * not a shape worth having for the sake of one method fewer.
     *
     * **Keyed by marker key, and a key that is missing is the ordinary case.**
     * An installation that has uploaded no logo answers with an empty array,
     * which is what makes the "no logo draws nothing" rule fall out rather than
     * be implemented: the marker is still in the vocabulary, so
     * {@see DocumentMarkers::dataFor()} still blanks it, and blank beats
     * brackets exactly as §5.7 already decided for every unfilled marker.
     *
     * **Raw bytes and nothing else.** No path, no URL and no content type: the
     * type is decided by decoding the header on the other side, because a
     * pipeline that believed a label it was handed would embed whatever it was
     * given under whatever name it was asked to — the same call `LogoFormat`
     * makes about an upload. And documents are generated without a browser, so
     * an address would be the wrong answer twice over.
     *
     * @return array<string, string> marker key => the image, as it is stored
     */
    public function images(): array;
}

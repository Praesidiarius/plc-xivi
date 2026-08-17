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
 * A field type that offers {@see Autocomplete} to the customer (XIV-36).
 *
 * Two types implement it and they get very different things out of it. A
 * `choice` holds a closed list in the field's own options — realistically under
 * a dozen entries, all of them already in the page — so autocomplete there is
 * client-side filtering of a list that is present anyway: no endpoint, no
 * permission question, no ceiling. A `reference` points at records and renders
 * candidates capped at two hundred, which is the one that is actually broken at
 * scale and the only one needing a server round trip.
 *
 * **A marker, and deliberately so.** The shape this obviously wants is the one
 * §5.4 describes: a *type* saying which of its options are the customer's to
 * set, so the editor can draw the right controls per type instead of a fixed
 * few. When this was written that shape had one example, and inventing it from
 * one example would have been guessing at an interface with one
 * implementation's worth of evidence — the speculative generalisation §1 warns
 * about. So this declared exactly what was known: these types offer this option.
 *
 * **XIV-27 arrived with the second example** ({@see Numbers}) and the general
 * form was written from the pair rather than from one of them. It is not what
 * this file predicted — the `instanceof` became a lookup in a declared list, but
 * the interface stayed, because the interface *is* what the list looks things up
 * by. A capability that documents itself in the type system costs one file and
 * buys a compile-time answer; a list of option names alone would have been a
 * convention.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface Autocompletes extends FieldType
{
}

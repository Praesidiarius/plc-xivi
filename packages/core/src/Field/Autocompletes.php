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
 * §5.4 already describes and has not built: a *type* saying which of its options
 * are the customer's to set, so the editor can draw the right controls per type
 * instead of a fixed few. That is XIV-27, and it has the wider view — inventing
 * the general shape here, from the single option this ticket adds, would be
 * guessing at an interface with one implementation's worth of evidence, which is
 * the speculative generalisation §1 warns about.
 *
 * So this declares exactly what is known: these types offer this option. The
 * editor asks with `instanceof` and draws one control. When XIV-27 arrives, that
 * `instanceof` becomes a lookup in a declared list and this interface goes with
 * it, having cost one file.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface Autocompletes extends FieldType
{
}

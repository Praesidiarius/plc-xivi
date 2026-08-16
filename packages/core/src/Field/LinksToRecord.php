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

use Xivi\Core\Entity\FieldDefinition;

/**
 * A field type whose value names another record, and can say which (XIV-42).
 *
 * **Why this is not `display()`.** That method is plain text on purpose, and
 * three things depend on its being so: `DocumentMarkers` fills .docx templates
 * with it, so markup there is markup printed on a letter; the export writes it
 * into spreadsheet cells; and `recordTitle()` builds a record's *name* out of
 * the display of its title fields, which is what the reference picker puts in an
 * `<option>` and what a page heading says. An `<a>` returned from `display()`
 * ends up in all three.
 *
 * So a link is a second question, asked only by the places that can draw one.
 * A field type that has no answer does not implement this, and the templates
 * that ask get null — which is also what a *stale* reference gives (§7.6): a
 * link into a module the customer no longer has, or at a record that is gone,
 * reads as `#id` and is not an anchor, because an anchor to a page that will
 * 404 is worse than the text it replaced.
 *
 * **Only the reader's own links.** What comes back is what *this* reader may
 * open (§8.4). Somebody who may not view the target still sees its name — that
 * is unchanged, and hiding it would leave a record nobody can read — but is not
 * offered a door that would refuse them.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface LinksToRecord
{
    /** The record this value names, or null when there is nothing to link to. */
    public function linkOf(mixed $value, FieldDefinition $field): ?RecordLink;
}

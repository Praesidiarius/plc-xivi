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

namespace Xivi\Core\Period;

use Xivi\Core\Entity\FieldDefinition;

/**
 * What a period is exclusive *within* — the field naming the thing that can only
 * be in one period at a time (XIV-136).
 *
 * Two bookings for one room on one night is the rule; two bookings for two rooms
 * is not. So "no overlaps" is never a statement about a module, it is a statement
 * about a **resource**, and the constraint cannot be built until somebody has
 * said which field names it: the room, the machine, the carer, the vehicle.
 *
 * That answer is a per-field option for the same reason
 * {@see \Xivi\Core\Phone\PhoneRegion} is: it belongs to one field on one
 * customer's module, and a module that ships a booking shape and a module a
 * customer built out of the metadata editor have to be able to say it the same
 * way. A global rule would have been the alternative and it is not expressible —
 * there is nothing in "this engine refuses overlaps" that says overlaps of what.
 *
 * ### One field, and not a list
 *
 * A composite scope — "one room *and* one bed" — is a real shape and is
 * deliberately not built. `EXCLUDE` takes as many `WITH =` columns as anybody
 * wants, so the cost is not in Postgres; it is in the editor, where a control
 * that picks several fields in an order that matters is a different control from
 * a select, and in the refusals, where "which of these four fields is the one
 * your records already conflict on" is a sentence nobody can write. A customer
 * who needs it today can carry the pair in one field. If a second real case turns
 * up, this option becomes a list and the constraint builder loops; nothing else
 * moves.
 *
 * ### And not "nowhere", either
 *
 * There is no way to say "no two periods in this whole module may overlap". It
 * would be a module holding exactly one resource — one meeting room, one van —
 * and the honest expression of that is a scope field with one option in it, which
 * costs a `choice` field and reads correctly on the day a second van arrives.
 * Leaving the option blank therefore means the field simply has no constraint,
 * which is what almost every period field wants: a project's duration and an
 * employment's dates overlap each other constantly and always should.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ExclusiveWithin
{
    /**
     * Where the answer lives in a field definition's options.
     *
     * Spelled as a sentence fragment because that is how it reads at the call
     * site — `exclusive_within: room` — and because `scope` on its own would be
     * a word this codebase already uses for permissions.
     */
    public const string OPTION = 'exclusive_within';

    /**
     * The key of the field this period is exclusive within, or null for no
     * constraint.
     *
     * **Not checked against the shape here.** Whether that field exists is the
     * metadata editor's question, asked on the write path where the console and
     * an importer meet it too ({@see \Xivi\Core\Metadata\MetadataEditor}); this
     * is the reader, and it runs on every save of every record. A key that no
     * longer names a field would mean a constraint that no longer exists, and
     * the honest reading of that is "this field is not exclusive within
     * anything" rather than an exception on a page that was only being drawn.
     */
    public static function of(FieldDefinition $field): ?string
    {
        $set = $field->getOption(self::OPTION);

        return \is_string($set) && trim($set) !== '' ? trim($set) : null;
    }
}

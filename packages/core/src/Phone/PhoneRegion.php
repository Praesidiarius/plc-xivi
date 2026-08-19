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

namespace Xivi\Core\Phone;

use Xivi\Core\Entity\FieldDefinition;

/**
 * Which country *this* phone field assumes, when it is not the installation's
 * (XIV-114).
 *
 * **An option with a default, not a setting.** §8.6 already says which country
 * the installation is in and [XIV-50] built the chain that reads it, so the
 * common case — every number in the tenant is a local one — needs nobody to open
 * the metadata editor at all. What this exists for is the case that chain cannot
 * express: a Swiss company whose `supplier_phone` field only ever holds German
 * numbers, where the tenant's region is right for every other field and wrong for
 * that one.
 *
 * **An option rather than a field type**, for the reason
 * {@see \Xivi\Core\Field\Autocomplete} gives at length: a field type owns what a
 * value *means*, and `phone_de` would copy `phone`'s storage, its constraints,
 * its operators and its display and differ in one string — and from the day it
 * existed, changing a field's assumed country would be a type change, which §5.4
 * refuses because stored values cannot survive one. As an option it is a select
 * somebody changes their mind about, and the stored numbers are unaffected
 * either way: they are already E.164 and already carry their own country.
 *
 * **Changing it does not rewrite anything.** It decides how the *next* value
 * typed into that field is read. That is the honest behaviour and it is worth
 * saying out loud, because the tempting reading is that switching a field to
 * Germany reinterprets the Swiss numbers already in it — which would silently
 * turn `+41 79 …` into `+49 79 …` for every record, and there is no undo for
 * that.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PhoneRegion
{
    /**
     * Where the answer lives in a field definition's options.
     *
     * Deliberately short: what a `region` means on a field that has one is not
     * ambiguous, and every type that does not declare
     * {@see \Xivi\Core\Field\AssumesACountry} is never asked about it — the
     * metadata editor names an option only for the types that offer it (§5.4),
     * so a `text` field's save says nothing about this and could not clear one
     * even if something had put it there.
     */
    public const string OPTION = 'region';

    /**
     * What a field asks for, or null for "whatever the installation says".
     *
     * Anything that is not two letters is null rather than an error. The control
     * is a select of the countries there are, so a value outside that set is a
     * hand-edited form or a code retired since it was stored, and falling
     * through to the installation's answer keeps the field working — the same
     * call {@see \Xivi\Core\Field\Autocomplete::of()} makes about a spelling it
     * does not recognise.
     */
    public static function of(FieldDefinition $field): ?string
    {
        $set = $field->getOption(self::OPTION);

        if (!\is_string($set) || !preg_match('/^[A-Za-z]{2}$/', $set)) {
            return null;
        }

        return strtoupper($set);
    }
}

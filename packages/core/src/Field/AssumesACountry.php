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
 * A field type that reads its values against a country, and whose fields may
 * name a different one (XIV-114).
 *
 * The **third** capability of this kind, after {@see Autocompletes} (XIV-36) and
 * {@see Numbers} (XIV-27), and the first one to be added since the pair became a
 * declared list rather than an `instanceof` — which is the thing that list was
 * for. What this cost, end to end, is this file, one line in
 * {@see \App\Controller\FieldController}'s `PER_TYPE` and one control in the
 * field table's template. No branch was added to the controller and nothing in
 * core learned that a country exists outside {@see \Xivi\Core\Phone}.
 *
 * **What declaring it means.** The type reads and writes values whose meaning
 * depends on where they were written, and it takes its default answer from the
 * installation's region (§8.6, [XIV-50]) — so the editor may offer this field a
 * country of its own, and a field that says nothing goes on following the
 * profile. See {@see \Xivi\Core\Phone\PhoneRegion} for why that is an option
 * with a default rather than a setting somebody has to fill in.
 *
 * Nothing here is phone-specific by construction, and one implementation is not
 * enough to claim it generalises. A postal address would be the second, and the
 * moment to find out whether "reads its values against a country" is one idea or
 * two.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface AssumesACountry extends FieldType
{
}

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

namespace Xivi\Core\Money;

/**
 * The currency this installation prices in, asked for rather than known (XIV-11).
 *
 * The answer lives in the tenant profile (§8.6), which is the application's and
 * not the engine's: core is handed a connection and never learns whose it is, the
 * same boundary PermissionSet keeps for permissions and RecordWriter for users.
 * So core declares the question and the application answers it.
 *
 * One currency per installation, deliberately. A price field could have carried
 * its own, and then a list would be adding francs to euros in the same column —
 * per-record currencies are a feature with exchange rates behind it, not a field
 * option.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
interface InstanceCurrency
{
    /**
     * The ISO 4217 code, or null when nobody has chosen one.
     *
     * Null is a real answer and every caller has to have one: a price with no
     * currency is a number, which is what it will be shown as until somebody
     * says otherwise on the profile page.
     */
    public function code(): ?string;
}

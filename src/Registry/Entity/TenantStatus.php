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

namespace App\Registry\Entity;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum TenantStatus: string
{
    /** Being created; the database may not exist or may not be migrated yet. */
    case Provisioning = 'provisioning';

    case Trial = 'trial';

    case Active = 'active';

    /** Kept intact, but refuses to serve requests (unpaid, disputed, on hold). */
    case Suspended = 'suspended';

    public function servesRequests(): bool
    {
        return match ($this) {
            self::Active, self::Trial => true,
            self::Provisioning, self::Suspended => false,
        };
    }

    /**
     * How near the top of a list of tenants this status belongs, lowest first
     * (XIV-58).
     *
     * **Not the order the cases are declared in, and not alphabetical**, which is
     * the whole reason this exists as a method rather than as an `ORDER BY status`
     * somewhere. Postgres would sort the stored strings — `active`,
     * `provisioning`, `suspended`, `trial` — and that ordering is an accident of
     * spelling that puts the healthy majority first and buries the one row
     * somebody needed to see. Declaration order is no better: it is the order the
     * lifecycle runs in, which is a different question from the order a reader
     * wants.
     *
     * The ranking is by *how much a row in this state wants explaining*:
     *
     *   0. **Provisioning.** Nobody chooses to leave a tenant here. Provisioning
     *      is measured in seconds, so a tenant that is still in this state when a
     *      human loads a page is not mid-flight — it is wreckage from a run that
     *      died, and §4.1 is about the command that clears it.
     *   1. **Suspended.** Also not serving requests, but somebody *decided* that.
     *      It wants seeing — an unpaid customer who was reinstated last week and
     *      is still suspended is a real failure — and it does not want the same
     *      alarm as the row above.
     *   2. **Trial.** Serving, and on a clock somebody eventually has to act on.
     *   3. **Active.** The state almost every row is in, and the one that needs
     *      nothing from anybody.
     *
     * Deliberately *not* derived from {@see servesRequests()}, which collapses the
     * first two into one bucket. That predicate answers "may this hostname be
     * served", which is a runtime decision; this answers "who should read this
     * row first", which is a reading order. They agree today about which two
     * statuses are the unhappy ones and they are not the same question, so a
     * status added later can move in one without moving in the other.
     */
    public function attentionRank(): int
    {
        return match ($this) {
            self::Provisioning => 0,
            self::Suspended => 1,
            self::Trial => 2,
            self::Active => 3,
        };
    }
}

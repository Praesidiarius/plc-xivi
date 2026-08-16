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

namespace App\Tenant\Entity;

/**
 * How loudly a follow-up asks to be dealt with (XIV-80).
 *
 * Three values, closed, and stored as the string rather than as a number. A
 * number sorts for free and is unreadable in every other context — a row in psql,
 * an export, a bug report — and the sort order is a rendering concern that the
 * one place needing it can express in a CASE. `important` beats `warning` beats
 * `info` whichever way it is written down; only the enum has to know.
 *
 * **Deliberately not the customer's to configure.** Everything a customer names
 * in this system is metadata (§5), and the temptation to make this a choice field
 * is real — but a priority nobody outside one tenant can reason about is a
 * priority a future dashboard, a future digest mail and a future sort cannot rank.
 * Three words, chosen once, is the trade this makes.
 *
 * **The Bootstrap class each of these renders as is not here.** That mapping
 * belongs to the record page (XIV-82): this enum is what the database holds and
 * what the write path validates, and a value object that knew about `text-bg-*`
 * would be the model reaching into the template's vocabulary. The same split
 * {@see \Xivi\Core\Permission\ModuleAction} makes when it hands out a label *key*
 * instead of a label.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
enum FollowUpPriority: string
{
    /** A note to self. Something to look at, nothing that goes wrong if it waits. */
    case Info = 'info';

    /** Wants attention around its date, and is worth being reminded about. */
    case Warning = 'warning';

    /** Somebody is waiting on this. The loudest thing a follow-up can say. */
    case Important = 'important';

    /**
     * The one a follow-up gets when nobody chose, which is the quietest.
     *
     * Guessing upward would be the mistake: a system where everything arrives
     * marked important is one where nothing is, and the person filling the form
     * in has not said anything yet.
     */
    public static function default(): self
    {
        return self::Info;
    }

    /**
     * How they rank against each other, low to high.
     *
     * A method rather than an `int` case value, so the stored word stays a word.
     * Used for ordering a list and for nothing else — the number is never
     * persisted, so renumbering these costs no migration.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Important => 2,
        };
    }

    /** A key in the `messages` domain: this is the application's word, not a module's. */
    public function labelKey(): string
    {
        return 'follow_up.priority.' . $this->value;
    }
}

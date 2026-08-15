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

namespace Xivi\Core\Record;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Xivi\Core\Entity\ModuleDefinition;

/**
 * A module working out values while a record is being saved (XIV-16).
 *
 * **This is the answer to the non-veto half of §7.1.** A module may take part in
 * a save, and what it may do there is *derive*: fill in values that follow from
 * what was typed. It may not cancel the save, and there is deliberately nothing
 * here to cancel it with — no return value the writer inspects, no stoppable
 * event, no flag. Whether a subscriber may *refuse* a save stays open; a
 * lifecycle already refuses one, and it does so on a rule the module declared
 * rather than at runtime (§5.8).
 *
 * That asymmetry is the whole design. Derivation makes a save produce more; a
 * veto makes it produce nothing, and a save that fails for a reason the page
 * cannot name is the failure mode §7.1 was written to avoid. An exception thrown
 * from here is a bug, not a decision — it will take the transaction down, which
 * is what should happen to a bug and is no way to say no.
 *
 * **Called before anything is written**, once per save, inside its transaction.
 * Order between derivers is unspecified: two of them wanting the same field is
 * an argument between modules, and the engine is not the place it gets settled.
 *
 * A deriver belongs to the module whose values it works out and lives in that
 * module's package — the order package computes order totals. It reads its own
 * fields and nothing else's, so this crosses no boundary §3 draws.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AutoconfigureTag(self::TAG)]
interface ValueDeriver
{
    public const string TAG = 'xivi.value_deriver';

    /**
     * Whether this deriver has anything to say about that module.
     *
     * Asked per save rather than wired per module, because the module a customer
     * has is a row in their database and the deriver is a class in the code:
     * there is no compile-time place to put the pairing.
     */
    public function supports(ModuleDefinition $module): bool;

    /** Fill in what follows from what was typed. Mutates the derivation; returns nothing. */
    public function derive(ModuleDefinition $module, Derivation $derivation): void;
}

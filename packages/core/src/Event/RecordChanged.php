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

namespace Xivi\Core\Event;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordChanges;

/**
 * One record changed, once per action (§5.2).
 *
 * This is §6's middle extensibility layer finally doing something: behaviour
 * added by subscribing rather than by editing the engine. History is the first
 * subscriber and deliberately a passive one — it observes and never cancels, so
 * it needs nothing from §7.1, which is still undecided.
 *
 * Dispatched *inside* the transaction that made the change, so a subscriber that
 * fails takes the change down with it. A record that changed with no history
 * entry is worse than a save that failed, because the gap is invisible.
 *
 * It carries no user. Core does not know what a user is — the application
 * resolves that and adds it, the same way it resolves owner ids to names.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class RecordChanged
{
    public function __construct(
        public ModuleDefinition $module,
        public Record $record,
        public RecordAction $action,
        public RecordChanges $changes,
        /** One timestamp for the whole action, not one per statement. */
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}

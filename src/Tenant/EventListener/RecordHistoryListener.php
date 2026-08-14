<?php

declare(strict_types=1);

namespace App\Tenant\EventListener;

use App\Tenant\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Xivi\Core\Event\RecordChanged;
use Xivi\Core\History\HistoryRepository;

/**
 * Writes a record's history entry, and supplies the one thing core cannot: who
 * did it (§5.2).
 *
 * This is the seam §6 describes — behaviour added by subscribing rather than by
 * the engine growing a dependency. Core dispatches what changed; the application
 * knows what a user is and adds the name, exactly as it resolves owner ids to
 * names for the list view.
 *
 * The name is written into the row rather than joined to later, so renaming
 * somebody, or deleting them, cannot rewrite what the timeline says happened.
 *
 * A null user is not a bug: a console command, an import or a future message
 * consumer changes records with nobody signed in, and "nobody" is the honest
 * answer rather than a fabricated one.
 *
 * It runs inside the transaction the writer opened, so if this throws, the change
 * it was describing is rolled back with it.
 */
#[AsEventListener(event: RecordChanged::class)]
final readonly class RecordHistoryListener
{
    public function __construct(
        private HistoryRepository $history,
        private Security $security,
    ) {
    }

    public function __invoke(RecordChanged $event): void
    {
        $user = $this->security->getUser();
        $user = $user instanceof User ? $user : null;

        $this->history->append(
            module: $event->module,
            recordId: (int) $event->record->id,
            action: $event->action,
            occurredAt: $event->occurredAt,
            userId: $user?->getId(),
            userLabel: $user?->getName(),
            changes: $event->changes,
        );
    }
}

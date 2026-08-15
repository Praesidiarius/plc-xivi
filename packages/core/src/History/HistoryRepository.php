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

namespace Xivi\Core\History;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\RecordAction;
use Xivi\Core\Record\RecordChanges;

/**
 * Appends to and reads one module's history table (§5.2).
 *
 * Append-only by discipline: there is no update and no delete here, because an
 * audit trail that can be edited is not one. Rows leave only with the record
 * they belong to, by the foreign key's cascade.
 *
 * Who the user was is passed in rather than looked up. Core does not know what a
 * user is — the application resolves that, exactly as it resolves owner ids to
 * names for the list view.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class HistoryRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(
        ModuleDefinition $module,
        int $recordId,
        RecordAction $action,
        \DateTimeImmutable $occurredAt,
        ?int $userId,
        ?string $userLabel,
        RecordChanges $changes,
    ): void {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (record_id, occurred_at, user_id, user_label, action, changes)
                 VALUES (:record, :occurred, :user, :label, :action, :changes)',
                $this->table($module),
            ),
            [
                'record' => $recordId,
                'occurred' => $occurredAt->format(\DateTimeInterface::ATOM),
                'user' => $userId,
                'label' => $userLabel,
                'action' => $action->value,
                'changes' => json_encode($changes->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            ],
            ['record' => ParameterType::INTEGER, 'user' => ParameterType::INTEGER],
        );
    }

    /**
     * A record's timeline, newest first — the only question this table is asked,
     * and the reason its one index is on (record_id, id).
     *
     * Always a window, never the whole thing: a record edited daily for a year
     * has a timeline nobody reads to the end, and the page that tried would be
     * fetching, hydrating and rendering every row of it (XIV-3).
     *
     * **Ordered by when it happened, with the id breaking ties.** It used to be
     * by id alone, which is the same answer as long as rows are only ever
     * appended as things happen — and a different one the moment something
     * writes an entry with an older timestamp, which an import backfilling a
     * history reasonably would. Once the page groups entries by age (XIV-3),
     * "the same answer nearly always" stops being good enough: one row out of
     * date order puts a section boundary in the middle of a day. The id is still
     * the tiebreaker, because two entries can share a second and paging over an
     * unstable order repeats rows.
     *
     * @return list<HistoryEntry>
     */
    public function findFor(ModuleDefinition $module, int $recordId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT id, occurred_at, user_id, user_label, action, changes
                 FROM %s WHERE record_id = :record
                 ORDER BY occurred_at DESC, id DESC LIMIT :limit OFFSET :offset',
                $this->table($module),
            ),
            ['record' => $recordId, 'limit' => max(1, $limit), 'offset' => max(0, $offset)],
            [
                'record' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * How long the timeline is.
     *
     * Its own query rather than a window function beside the rows: the page
     * needs the number even when it is showing five of five hundred, and a count
     * over one record's slice of an index is cheap enough not to be worth
     * fusing with a query that returns different columns.
     */
    public function countFor(ModuleDefinition $module, int $recordId): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE record_id = :record', $this->table($module)),
            ['record' => $recordId],
            ['record' => ParameterType::INTEGER],
        );
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): HistoryEntry
    {
        $changes = \is_string($row['changes'] ?? null)
            ? json_decode($row['changes'], true, flags: \JSON_THROW_ON_ERROR)
            : [];
        \assert(\is_array($changes));

        return new HistoryEntry(
            id: (int) $row['id'],
            occurredAt: new \DateTimeImmutable((string) $row['occurred_at']),
            // An action this build does not know is not a reason to fail reading
            // a timeline; it is a row written by a newer version of the engine.
            action: RecordAction::tryFrom((string) $row['action']) ?? RecordAction::Updated,
            userId: isset($row['user_id']) ? (int) $row['user_id'] : null,
            userLabel: isset($row['user_label']) ? (string) $row['user_label'] : null,
            changes: $changes,
        );
    }

    private function table(ModuleDefinition $module): string
    {
        return $this->connection->getDatabasePlatform()->quoteSingleIdentifier($module->getHistoryTableName());
    }
}

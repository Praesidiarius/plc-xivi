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

use Xivi\Core\Record\RecordAction;

/**
 * One line of a record's timeline, as read back (§5.2).
 *
 * The user is a name and an id, not a User object: core does not know what a
 * user is, and the name is what was true when this happened — resolving it
 * afresh would let renaming somebody rewrite history.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class HistoryEntry
{
    /** @param array<string, mixed> $changes */
    public function __construct(
        public int $id,
        public \DateTimeImmutable $occurredAt,
        public RecordAction $action,
        public ?int $userId,
        public ?string $userLabel,
        public array $changes,
    ) {
    }

    /**
     * Field changes, or an empty list for an entry that only touched
     * collections.
     *
     * @return array<string, array{label: string, from: mixed, to: mixed}>
     */
    public function fieldChanges(): array
    {
        $fields = $this->changes['fields'] ?? [];
        \assert(\is_array($fields));

        return $fields;
    }

    /**
     * What happened to each collection, keyed by collection.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function collectionChanges(): array
    {
        $collections = $this->changes['collections'] ?? [];
        \assert(\is_array($collections));

        return $collections;
    }

    /**
     * How many things this entry touched, fields and collection rows together.
     *
     * What a compact timeline says instead of the detail (XIV-3): "3 changes" is
     * the line somebody scans, and the changes themselves are what they open it
     * for. Rows count as one each rather than by the fields inside them —
     * an address being added is one thing that happened, not five.
     */
    public function changeCount(): int
    {
        $count = \count($this->fieldChanges());

        foreach ($this->collectionChanges() as $rows) {
            $count += \count($rows);
        }

        return $count;
    }

    /** Whether there is anything to open: a created record lists no changes. */
    public function hasDetail(): bool
    {
        return $this->changeCount() > 0;
    }
}

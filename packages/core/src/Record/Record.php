<?php

declare(strict_types=1);

namespace Xivi\Core\Record;

/**
 * One row of a module, as the application sees it.
 *
 * Deliberately not a Doctrine entity. The shape of a record is decided per
 * tenant at runtime, and mapping that through the ORM means fighting it; the
 * fixed-shape things — users, metadata definitions — are entities, and these are
 * not (docs/architecture.md §5).
 *
 * `data` holds the custom fields. Where each one physically lives is
 * RecordRepository's business, which is what leaves room for column promotion
 * later without anything above this line noticing.
 */
final class Record
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data = [],
        public ?int $id = null,
        public ?int $ownerId = null,
        /**
         * Set on rows of a collection, and null on everything else: a contact's
         * address names the contact it belongs to, a contact names nobody. The
         * two are mutually exclusive by design — a child's owner is whoever owns
         * its parent, which is the only answer that cannot drift.
         */
        public ?int $parentId = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        public ?\DateTimeImmutable $deletedAt = null,
    ) {
    }

    public function isNew(): bool
    {
        return $this->id === null;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function get(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    public function set(string $field, mixed $value): void
    {
        $this->data[$field] = $value;
    }
}

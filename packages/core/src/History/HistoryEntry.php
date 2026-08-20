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
     * What was generated, for an entry that made a document rather than a change
     * (XIV-4).
     *
     * @return array{template: string, format: string}|null
     */
    public function document(): ?array
    {
        $document = $this->changes['document'] ?? null;

        if (!\is_array($document) || !isset($document['template'], $document['format'])) {
            return null;
        }

        return ['template' => (string) $document['template'], 'format' => (string) $document['format']];
    }

    /**
     * What was sent, for an entry that put a mail outside the building (XIV-39).
     *
     * Present on a failure exactly as it is on a success — the entry's verb is
     * what says which it was, so that a timeline answers "did that invoice go
     * out" by being read rather than by being opened.
     *
     * **`attachment` is absent unless one went with it** (XIV-40), rather than
     * present and empty, so that the key's existence is the answer to "did the
     * invoice itself go with that mail" — and on a failed send its presence says
     * that the document was made and the *transport* is what refused, which is
     * the distinction §5.15 exists to keep readable.
     *
     * **And what was on the attachment beyond the template** (XIV-164): one key
     * per decoration that was offered for it, with a boolean saying whether it
     * went. The question and its answer rather than a list of what was applied,
     * because "no" is the reading that matters here: an invoice deliberately
     * sent without its payment part and a letter that was never the kind of
     * document to carry one are different facts, and a list of applied things
     * renders them identically. Absent where nothing was ever on offer.
     *
     * @return array{template: string, recipient: string, subject: string, attachment?: array{template: string, format: string, decorations?: array<string, bool>}}|null
     */
    public function email(): ?array
    {
        $email = $this->changes['email'] ?? null;

        if (!\is_array($email) || !isset($email['template'], $email['recipient'], $email['subject'])) {
            return null;
        }

        $sent = [
            'template' => (string) $email['template'],
            'recipient' => (string) $email['recipient'],
            'subject' => (string) $email['subject'],
        ];

        $attachment = $email['attachment'] ?? null;

        if (!\is_array($attachment) || !isset($attachment['template'], $attachment['format'])) {
            return $sent;
        }

        $attached = [
            'template' => (string) $attachment['template'],
            'format' => (string) $attachment['format'],
        ];

        $decorations = self::decorationsIn($attachment);

        return [...$sent, 'attachment' => $decorations === [] ? $attached : [...$attached, 'decorations' => $decorations]];
    }

    /**
     * The decoration answers on a stored attachment, read defensively.
     *
     * Everything in a history row came out of a JSON column and may have been
     * written by a version of this code that is no longer here, so the shape is
     * checked rather than trusted, the same way the two keys above are cast
     * rather than passed through.
     *
     * @param array<mixed> $attachment
     *
     * @return array<string, bool>
     */
    private static function decorationsIn(array $attachment): array
    {
        $decorations = $attachment['decorations'] ?? null;

        if (!\is_array($decorations)) {
            return [];
        }

        $answers = [];

        foreach ($decorations as $key => $applied) {
            if (\is_string($key) && \is_bool($applied)) {
                $answers[$key] = $applied;
            }
        }

        return $answers;
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

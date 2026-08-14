<?php

declare(strict_types=1);

namespace Xivi\Core\Record;

/**
 * What one action changed, in the shape history stores it (§5.2).
 *
 * Two branches, mirroring the form and the validator: the record's own values,
 * and what happened to the rows of its collections. Keeping one structure across
 * the three means nobody has to translate between them.
 *
 * Labels are carried alongside the values rather than looked up when the entry is
 * read. History is a record of what happened, so renaming a field later must not
 * rewrite the past, and a field deleted since must still render.
 *
 * Values are in storage form, which is also the form they are compared in — the
 * alternative is a date that "changed" because one side is a string and the other
 * an object.
 */
final readonly class RecordChanges
{
    /**
     * @param array<string, array{label: string, from: mixed, to: mixed}> $fields
     * @param array<string, list<array<string, mixed>>>                   $collections keyed by collection,
     *                                                                                 one entry per row touched
     */
    public function __construct(
        public array $fields = [],
        public array $collections = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->fields === [] && $this->collections === [];
    }

    /**
     * Absent rather than empty, for the same reason the record payload omits
     * nulls: a history row full of empty branches is noise in every query that
     * reads it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->fields !== []) {
            $out['fields'] = $this->fields;
        }

        if ($this->collections !== []) {
            $out['collections'] = $this->collections;
        }

        return $out;
    }
}

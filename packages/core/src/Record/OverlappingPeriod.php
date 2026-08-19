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

use Symfony\Component\Translation\TranslatableMessage;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ModuleDefinition;

/**
 * The exclusion constraint refusing a save, on its way to becoming a sentence
 * (XIV-136).
 *
 * {@see DuplicateValue}'s sibling, and it exists for the same reason: with the
 * rule enforced by the database, the losing side of a race no longer gets a
 * validation message — it gets a driver exception out of the middle of a write,
 * which left alone is a 500 where somebody was expecting to be told the room is
 * taken.
 *
 * ### There is no validator half here, and that is a difference worth naming
 *
 * Uniqueness has two halves: a validator that catches almost everything on the
 * field it belongs to, and an index that catches the sliver between the read and
 * the write. Overlap has only the second, on purpose. A validator would have to
 * ask "is anything else in this room in these days" — a *query about other
 * records*, which is exactly the read-then-write this ticket exists to stop
 * relying on — and having one would tempt the next reader into believing it were
 * the rule. It is not; the constraint is. What is lost is that the message
 * arrives after the save rather than while the form is on screen, which is the
 * honest position: until the moment of the write, nobody can truthfully say the
 * room is free.
 *
 * ### It names the field, and how it knows which one
 *
 * By matching the constraint name Postgres reports against
 * {@see OverlapExclusion::nameFor()} for each of the module's period fields —
 * the same trick, and the same reason it is a trick rather than a regular
 * expression over the message: that sentence is translated by the server's
 * `lc_messages`, which is a setting of somebody else's database. An identifier is
 * an identifier in every language.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class OverlappingPeriod extends \RuntimeException
{
    private function __construct(
        public readonly string $moduleKey,
        /** The field the constraint belongs to, or null when it could not be named. */
        public readonly ?string $fieldKey,
        public readonly ?string $fieldLabel,
        string $message,
        ?\Throwable $previous,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function of(ModuleDefinition $module, ?FieldDefinition $field, ?\Throwable $previous = null): self
    {
        return new self(
            $module->getKey(),
            $field?->getKey(),
            $field?->getLabel(),
            sprintf(
                'An exclusion constraint on "%s" refused this save: %s overlaps a period another record '
                . 'already holds for the same thing.',
                $module->getTableName(),
                $field === null ? 'a period field of that table' : sprintf('"%s"', $field->getKey()),
            ),
            $previous,
        );
    }

    /**
     * What to show the person who caused it, in their language (XIV-8).
     *
     * It says what happened rather than only that something failed, because what
     * happened is not obvious from the form: every box on it is filled in
     * correctly, and what is wrong is a *different record* — one the person
     * saving may not even be able to see.
     */
    public function translatable(): TranslatableMessage
    {
        if ($this->fieldLabel === null) {
            return new TranslatableMessage('record.overlapping_period_unknown', [], 'xivi');
        }

        return new TranslatableMessage('record.overlapping_period', ['%field%' => $this->fieldLabel], 'xivi');
    }
}

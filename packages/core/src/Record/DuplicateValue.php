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
 * The unique index refusing a save, on its way to becoming a sentence
 * (XIV-109).
 *
 * ### Why this exists rather than letting the DBAL exception out
 *
 * With uniqueness enforced by an index ({@see UniqueIndex}), the losing side of
 * a race no longer gets a validation message — it gets a
 * `UniqueConstraintViolationException` out of the middle of a write. Left alone
 * that is a 500: a stack trace where somebody was expecting to be told which box
 * to change, on a form they filled in correctly and submitted a fraction of a
 * second too late.
 *
 * So the layering is the usual one, and it is worth saying which half does what.
 * **The validator produces the readable message; the index is the thing that is
 * actually true.** Almost every duplicate is caught by the validator, in the
 * ordinary way, on the field it belongs to, before anything is written — the
 * index only ever fires on the sliver the validator cannot see, which is the
 * moment between its read and the write. Because that sliver exists, something
 * has to catch what comes out of it and put it back where a validation message
 * would have been. This is that thing.
 *
 * ### It names the field, and how it knows which one
 *
 * A record has as many unique fields as its customer decided, so "another record
 * already uses this value" without saying which value is a message somebody has
 * to guess at. {@see RecordWriter} matches the constraint name Postgres reports
 * against {@see UniqueIndex::nameFor()} for each of the module's unique fields,
 * which works because that name is *derived* rather than stored — the same pure
 * function that created the index reads the failure.
 *
 * The field can still come back null, and the message for that case is written
 * rather than left to a fallback: a tenant may carry an index this engine did
 * not create, and a page that says "something in this form is already taken" is
 * a worse answer than naming the field but a far better one than a stack trace.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DuplicateValue extends \RuntimeException
{
    private function __construct(
        public readonly string $moduleKey,
        /** The field the index belongs to, or null when it could not be named. */
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
                'A unique index on "%s" refused this save: %s already holds the value another record was '
                . 'given between this one being validated and being written.',
                $module->getTableName(),
                $field === null ? 'a field of that table' : sprintf('"%s"', $field->getKey()),
            ),
            $previous,
        );
    }

    /**
     * What to show the person who caused it, in their language (XIV-8).
     *
     * The same split every refusal in this engine makes: the exception's own
     * message is English and goes to the log, where the reader is a developer
     * holding the source; this is the half a customer reads.
     *
     * It says what happened rather than only that something failed, because what
     * happened is genuinely surprising — the form was checked, it was fine, and
     * it stopped being fine while it was being saved. Somebody who is only told
     * "already in use" will look at the value, see nothing wrong with it, and
     * press Save again.
     */
    public function translatable(): TranslatableMessage
    {
        if ($this->fieldLabel === null) {
            return new TranslatableMessage('record.duplicate_value_unknown', [], 'xivi');
        }

        return new TranslatableMessage('record.duplicate_value', ['%field%' => $this->fieldLabel], 'xivi');
    }
}

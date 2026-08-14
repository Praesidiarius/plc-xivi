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

namespace Xivi\Core\Field;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Validator\Constraint;
use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Query\Operator;

/**
 * One kind of field, and everything that follows from it.
 *
 * A closed registry, deliberately (§5): adding a field type is a code change,
 * not something a customer configures. That is what lets each type own its
 * validation and its storage mapping in one place instead of scattering
 * per-type conditionals through the engine.
 *
 * Implementations are tagged automatically, so a new type is one class and no
 * configuration.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AutoconfigureTag(self::TAG)]
interface FieldType
{
    public const string TAG = 'xivi.field_type';

    /** Stored in field_definition.field_type. */
    public function key(): string;

    public function label(): string;

    /**
     * Constraints for a value of this type, given how the customer configured
     * this particular field. Required-ness is added by the caller, since that is
     * a property of the field rather than of the type.
     *
     * @return list<Constraint>
     */
    public function constraints(FieldDefinition $field): array;

    /**
     * PHP value to something JSON can hold. Returning null means "no value",
     * which is what gets stored for an empty optional field.
     */
    public function toStorage(mixed $value, FieldDefinition $field): mixed;

    /** The inverse: whatever JSONB gave back, as the PHP value the application expects. */
    public function fromStorage(mixed $value, FieldDefinition $field): mixed;

    /**
     * The Symfony form type to edit a value of this kind.
     *
     * @return class-string<\Symfony\Component\Form\FormTypeInterface<mixed>>
     */
    public function formType(): string;

    /**
     * Options for that form type, given how this particular field is configured —
     * a maximum length, a range, a widget choice.
     *
     * @return array<string, mixed>
     */
    public function formOptions(FieldDefinition $field): array;

    /**
     * How a stored value reads in a list or a detail view. Here because the type
     * is the only thing that knows a date is not a string, and a template asking
     * that question would have to know it too.
     */
    public function display(mixed $value, FieldDefinition $field): string;

    /**
     * The comparisons this type accepts (§7.3). Asking whether a date contains
     * "ar" is not a question worth answering, so a date does not offer it.
     *
     * @return list<Operator>
     */
    public function operators(): array;

    /**
     * A plausible value of this kind, for generating demo data.
     *
     * Here rather than in the generator for the same reason validation and
     * storage are here: the generator would otherwise have to know that a
     * `choice` may only hold one of its own options and that a `reference` holds
     * the id of a record that exists — a switch on type, in the one place the
     * design says there must not be one. A new field type gets demo data by
     * implementing this, and a customer's own fields get filled without anybody
     * touching the generator.
     *
     * Returning null is allowed and means "leave this one empty", which is what
     * makes generated data look like real data rather than a filled-in grid. A
     * required field should not do it.
     *
     * @param int $sequence which record is being generated, counting from one.
     *                      A type whose field is unique has to use it: fifty
     *                      thousand records drawn from a list of thirty names
     *                      collide long before they finish.
     */
    public function sample(FieldDefinition $field, int $sequence): mixed;

    /**
     * The stored value, in a form Postgres can compare correctly.
     *
     * `$accessor` extracts it as text — `data->>'age'` today, a real column once
     * promotion arrives (§5) — and the type wraps whatever that needs: a cast for
     * numbers, nothing for text, and nothing for dates either, because ISO-8601
     * compares and sorts as text, which is why they are stored that way.
     *
     * This is the one place a type reaches into SQL, and it never sees a table or
     * a field name — so the compiler cannot grow a switch on type, and promotion
     * changes the accessor without touching any of this.
     */
    public function comparableSql(string $accessor): string;
}

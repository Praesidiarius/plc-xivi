<?php

declare(strict_types=1);

namespace Xivi\Core\Field;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Validator\Constraint;
use Xivi\Core\Entity\FieldDefinition;

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
     * @return class-string<\Symfony\Component\Form\FormTypeInterface>
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
}

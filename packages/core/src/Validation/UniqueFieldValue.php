<?php

declare(strict_types=1);

namespace Xivi\Core\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The per-tenant unique field of §5.
 *
 * It cannot be a unique index today: the value lives inside a JSONB payload, and
 * whether a field is unique is a customer's decision that can change after rows
 * exist. A promoted column (§5) can carry a real index later, at which point this
 * becomes the second line of defence rather than the only one.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class UniqueFieldValue extends Constraint
{
    public string $message = 'Another record already uses this value.';

    /**
     * @param string   $moduleKey the module whose table to look in
     * @param string   $fieldKey  the field that must be unique within it
     * @param int|null $exceptId  the record being edited, which must not collide with itself
     */
    public function __construct(
        public string $moduleKey = '',
        public string $fieldKey = '',
        public ?int $exceptId = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);
    }
}

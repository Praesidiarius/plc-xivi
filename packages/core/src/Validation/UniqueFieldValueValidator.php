<?php

declare(strict_types=1);

namespace Xivi\Core\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Record\RecordRepository;

final class UniqueFieldValueValidator extends ConstraintValidator
{
    public function __construct(
        private readonly MetadataRepository $metadata,
        private readonly RecordRepository $records,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueFieldValue) {
            throw new UnexpectedTypeException($constraint, UniqueFieldValue::class);
        }

        // Empty is not a duplicate: several records with nothing in a field are
        // not colliding with each other.
        if ($value === null || $value === '') {
            return;
        }

        $module = $this->metadata->get($constraint->moduleKey);
        $field = $module->getField($constraint->fieldKey);

        if ($field === null) {
            return;
        }

        if ($this->records->existsWithValue($module, $field, $value, $constraint->exceptId)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}

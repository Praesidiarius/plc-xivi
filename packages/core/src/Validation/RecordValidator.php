<?php

declare(strict_types=1);

namespace Xivi\Core\Validation;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\FieldTypeRegistry;

/**
 * Builds a record's validation rules out of its field definitions, as §5 asks:
 * one source of truth, so a field that is required is required everywhere,
 * without anybody restating it in a form class.
 *
 * It validates any shape, so a contact's address is checked by the same rules
 * from the same kind of row as the contact itself — no separate validator for
 * children, and no way for the two to disagree about what "required" means.
 */
final readonly class RecordValidator
{
    public function __construct(
        private ValidatorInterface $validator,
        private FieldTypeRegistry $fieldTypes,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param int|null             $recordId the record being edited, so that a unique
     *                                       field does not collide with itself
     */
    public function validate(ShapeDefinition $shape, array $data, ?int $recordId = null): ConstraintViolationListInterface
    {
        return $this->validator->validate(
            $this->normalize($shape, $data),
            $this->constraintFor($shape, $recordId),
        );
    }

    /**
     * Values are validated in the shape they will be stored in. Otherwise every
     * type would need its constraints to accept both what a form submits and
     * what the database holds, and "  " would pass a NotBlank that the stored
     * null then fails.
     *
     * Unknown keys are kept rather than dropped, so the Collection constraint can
     * report them instead of the engine silently discarding data.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalize(ShapeDefinition $shape, array $data): array
    {
        $normalized = $data;

        foreach ($shape->getFields() as $field) {
            $normalized[$field->getKey()] = $this->fieldTypes
                ->get($field->getType())
                ->toStorage($data[$field->getKey()] ?? null, $field);
        }

        return $normalized;
    }

    private function constraintFor(ShapeDefinition $shape, ?int $recordId): Assert\Collection
    {
        $fields = [];

        foreach ($shape->getFields() as $field) {
            $constraints = $this->fieldTypes->get($field->getType())->constraints($field);

            // Only on a module. On a collection, "unique" would have to mean
            // either across the whole table or within one parent, and the
            // installer refuses the ambiguity rather than picking one silently.
            if ($field->isUnique() && $shape instanceof ModuleDefinition) {
                $constraints[] = new UniqueFieldValue(
                    moduleKey: $shape->getKey(),
                    fieldKey: $field->getKey(),
                    exceptId: $recordId,
                );
            }

            if ($field->isRequired()) {
                array_unshift($constraints, new Assert\NotNull());
            }

            $fields[$field->getKey()] = new Assert\Optional($constraints);
        }

        return new Assert\Collection(
            fields: $fields,
            // Normalisation guarantees every known key is present, so a missing
            // one cannot happen; an unexpected one means a stale form or a typo
            // and is worth hearing about.
            allowExtraFields: false,
            allowMissingFields: true,
        );
    }
}

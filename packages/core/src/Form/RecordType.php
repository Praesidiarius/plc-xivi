<?php

declare(strict_types=1);

namespace Xivi\Core\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\FieldTypeRegistry;

/**
 * A form for one module's records, assembled from its field definitions.
 *
 * There is no ContactType and there never will be: the same class builds the
 * form for every module, from rows that differ per customer. That is the §5
 * claim about one source of truth doing real work — a field added to a
 * customer's definitions appears here with no code touched anywhere.
 *
 * It edits a plain array, since a record is not an entity. Validation is not
 * done by the form: RecordValidator owns that, and the controller maps its
 * violations onto these fields, so there is exactly one place that decides
 * whether a record is acceptable.
 */
final class RecordType extends AbstractType
{
    public function __construct(private readonly FieldTypeRegistry $fieldTypes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $module = $options['module'];
        \assert($module instanceof ModuleDefinition);

        foreach ($module->getFields() as $field) {
            $type = $this->fieldTypes->get($field->getType());

            $builder->add($field->getKey(), $type->formType(), [
                ...$type->formOptions($field),
                'label' => $field->getLabel(),
                // Only a hint to the browser. The definition is what actually
                // decides, and it is enforced server-side by RecordValidator.
                'required' => $field->isRequired(),
                'constraints' => [],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('module')
            ->setAllowedTypes('module', ModuleDefinition::class)
            ->setDefaults([
                // A record is an array, not an object.
                'data_class' => null,
                // The form does not validate; see the class docblock.
                'validation_groups' => false,
            ]);
    }
}

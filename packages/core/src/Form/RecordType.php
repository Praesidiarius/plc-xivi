<?php

declare(strict_types=1);

namespace Xivi\Core\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Field\FieldTypeRegistry;

/**
 * The fields of one shape, assembled from its field definitions.
 *
 * There is no ContactType and there never will be: the same class builds the
 * form for every module, from rows that differ per customer. That is the §5
 * claim about one source of truth doing real work — a field added to a
 * customer's definitions appears here with no code touched anywhere.
 *
 * It builds a collection's fields the same way it builds a module's, which is
 * why editing a contact's addresses inline needed no form code of its own — see
 * ModuleRecordType for how they are composed.
 *
 * It edits a plain array, since a record is not an entity. Validation is not
 * done by the form: RecordValidator owns that, and the controller maps its
 * violations onto these fields, so there is exactly one place that decides
 * whether a record is acceptable.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class RecordType extends AbstractType
{
    public function __construct(private readonly FieldTypeRegistry $fieldTypes)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $shape = $options['shape'];
        \assert($shape instanceof ShapeDefinition);

        // Only this variant's fields (§5.5). A company has no first name, so it
        // is not asked for one — and RecordValidator scopes itself the same way,
        // so it is not required to have one either.
        foreach ($shape->getFieldsFor($options['variant']) as $field) {
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
            ->setRequired('shape')
            ->setAllowedTypes('shape', ShapeDefinition::class)
            ->setDefault('variant', null)
            ->setAllowedTypes('variant', ['null', 'string'])
            ->setDefaults([
                // A record is an array, not an object.
                'data_class' => null,
                // The form does not validate; see the class docblock.
                'validation_groups' => false,
            ]);
    }
}

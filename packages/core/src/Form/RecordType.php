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

namespace Xivi\Core\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
 *
 * @author Praesidiarius <praesidiarius@proton.me>
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
            // **A locked variant travels hidden** (XIV-20). A collection row's
            // kind is decided when the row is added and fixed thereafter, so a
            // select offering to change it would be offering to make the row
            // disagree with the fields it is showing. It still has to be
            // submitted, which rules out `disabled`.
            if ($options['lock_variant'] && $field->getKey() === $shape->getVariantField()) {
                $builder->add($field->getKey(), HiddenType::class);

                continue;
            }

            $type = $this->fieldTypes->get($field->getType());

            $builder->add($field->getKey(), $type->formType(), [
                ...$type->formOptions($field),
                'label' => $field->getLabel(),
                // Only a hint to the browser. The definition is what actually
                // decides, and it is enforced server-side by RecordValidator.
                'required' => $field->isRequired() && !$field->isDerived(),
                'constraints' => [],
                // A derived value is shown and never taken (XIV-20). `disabled`
                // is the whole enforcement: Symfony ignores whatever arrives for
                // a disabled field, so a hand-edited request cannot type over a
                // total any more than the form can.
                'disabled' => $field->isDerived(),
                // How wide, as a class on the field's own wrapper (XIV-43). The
                // theme appends its spacing to whatever is here, so the template
                // still renders the whole set in one call and never asks what
                // kind of field it is holding.
                'row_attr' => ['class' => self::columns($field->getWidth() ?? $type->defaultWidth())],
            ]);
        }
    }

    /**
     * Twelfths, as the grid spells them.
     *
     * **Full width below `md`, the chosen width above it.** A column of
     * half-width fields on a phone is unusable, and somebody setting 6 is saying
     * "half a row on a screen with room for two" rather than "half a phone".
     *
     * The class name is built here and never stored (§8.3): what the grid is
     * called is this layer's business, and a proportion outlives it.
     */
    private static function columns(int $width): string
    {
        $width = max(1, min(12, $width));

        return $width === 12 ? 'col-12' : sprintf('col-12 col-md-%d', $width);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('shape')
            ->setAllowedTypes('shape', ShapeDefinition::class)
            ->setDefault('variant', null)
            ->setAllowedTypes('variant', ['null', 'string'])
            ->setDefault('lock_variant', false)
            ->setAllowedTypes('lock_variant', 'bool')
            ->setDefaults([
                // A record is an array, not an object.
                'data_class' => null,
                // The form does not validate; see the class docblock.
                'validation_groups' => false,
            ]);
    }
}

<?php

declare(strict_types=1);

namespace Xivi\Core\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Xivi\Core\Entity\ModuleDefinition;

/**
 * The whole editing form for one record: its own fields, and the collections
 * hanging off it.
 *
 * Two branches, deliberately, rather than one flat list of controls:
 *
 *     fields                     the module's own values
 *     collections[addresses][0]  one address, and so on
 *
 * A customer may name a field anything, so the module's fields are kept in their
 * own branch where they cannot collide with the name of a collection. It also
 * means the validator can be handed each part on its own — a contact validated
 * against the contact definitions, an address against the address ones — with no
 * code that knows which module it is looking at.
 */
final class ModuleRecordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $module = $options['module'];
        \assert($module instanceof ModuleDefinition);

        $builder->add('fields', RecordType::class, ['shape' => $module, 'label' => false]);

        if ($module->getCollections()->isEmpty()) {
            return;
        }

        $collections = $builder->create('collections', FormType::class, [
            'label' => false,
            // A plain container: it holds one child per collection and has no
            // value of its own.
            'inherit_data' => false,
        ]);

        foreach ($module->getCollections() as $collection) {
            $collections->add($collection->getKey(), CollectionType::class, [
                'entry_type' => CollectionRowType::class,
                'entry_options' => ['shape' => $collection, 'label' => false],
                'label' => $collection->getLabel(),
                'allow_add' => true,
                'allow_delete' => true,
                // The rows are arrays, so there is nothing to mutate in place.
                'by_reference' => false,
                'required' => false,
                'prototype' => true,
            ]);
        }

        $builder->add($collections);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('module')
            ->setAllowedTypes('module', ModuleDefinition::class)
            ->setDefaults([
                // A record is an array, not an object.
                'data_class' => null,
                // The form does not validate; RecordValidator does, per shape.
                'validation_groups' => false,
            ]);
    }
}

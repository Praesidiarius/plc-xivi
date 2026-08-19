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
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Module\ModuleRegistry;

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
 *
 * @extends AbstractType<array<string, mixed>>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ModuleRecordType extends AbstractType
{
    /**
     * The registry, for the one thing a *definition* cannot answer: which kind of
     * row the module's own code generates (XIV-104). That is a fact about the
     * module rather than about what this customer has made of it (§6.1), so it is
     * read from the blueprint — and read here because this is the only form type
     * that holds the module at all.
     */
    public function __construct(private readonly ModuleRegistry $modules)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $module = $options['module'];
        \assert($module instanceof ModuleDefinition);

        $builder->add('fields', RecordType::class, [
            'shape' => $module,
            // Only this record's variant is asked for (§5.5).
            'variant' => $options['variant'],
            'label' => false,
        ]);

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
            // Nothing to type into, so nothing to draw (XIV-16): a tax breakdown
            // is worked out from the lines, and offering an empty row of it
            // would invite somebody to fill in a figure the next save overwrites.
            if ($collection->isDerived()) {
                continue;
            }

            $totals = $this->modules->has($module->getKey())
                ? $this->modules->get($module->getKey())->lineTotals
                : null;

            $collections->add($collection->getKey(), CollectionType::class, [
                'entry_type' => CollectionRowType::class,
                'entry_options' => [
                    'shape' => $collection,
                    'label' => false,
                    'generated_kind' => $totals?->collection === $collection->getKey()
                        ? $totals->discountKind
                        : null,
                ],
                'label' => $collection->getLabel(),
                'allow_add' => true,
                'allow_delete' => true,
                // The rows are arrays, so there is nothing to mutate in place.
                'by_reference' => false,
                'required' => false,
                'prototype' => true,
            ]);
        }

        // A module whose only collections are derived gets no container at all,
        // rather than an empty one with a heading over nothing.
        if ($collections->all() !== []) {
            $builder->add($collections);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('module')
            ->setAllowedTypes('module', ModuleDefinition::class)
            ->setDefault('variant', null)
            ->setAllowedTypes('variant', ['null', 'string'])
            ->setDefaults([
                // A record is an array, not an object.
                'data_class' => null,
                // The form does not validate; RecordValidator does, per shape.
                'validation_groups' => false,
            ]);
    }
}

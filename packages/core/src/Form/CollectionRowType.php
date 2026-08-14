<?php

declare(strict_types=1);

namespace Xivi\Core\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Xivi\Core\Entity\CollectionDefinition;

/**
 * One row of a collection in the parent's form: which row it is, and its fields.
 *
 * The id travels with the row so that editing a contact updates its addresses
 * instead of deleting and re-creating them. Without it, every save would mint
 * new rows with new ids and new timestamps, and the soft-delete tombstones of
 * the old ones would pile up behind a record nobody had actually changed.
 *
 * The id is kept in its own child rather than mixed into the field values,
 * because a field key called "id" is a customer's to choose and this must not
 * be able to collide with one.
 *
 * Named "row" rather than "entry" on purpose: Symfony renders a collection's
 * prototype under the block prefix `collection_entry`, so a type of that name
 * collides with it and the form refuses to render.
 */
final class CollectionRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $collection = $options['shape'];
        \assert($collection instanceof CollectionDefinition);

        // Empty for a row the browser just added; whatever the server sent for
        // one that already exists. It is checked against the parent on save —
        // see RecordRepository::replaceChildren().
        $builder->add('id', HiddenType::class, ['required' => false]);
        $builder->add('fields', RecordType::class, ['shape' => $collection, 'label' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('shape')
            ->setAllowedTypes('shape', CollectionDefinition::class)
            ->setDefaults([
                'data_class' => null,
                'validation_groups' => false,
            ]);
    }
}

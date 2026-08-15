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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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
 * **A row may have a kind** (§5.5, XIV-20), and which fields it carries follows
 * from it — an order line that is a comment has no price. The kind lives in the
 * row's own data, so the fields cannot be known until the data is there; the
 * form listens for it rather than guessing.
 *
 * Named "row" rather than "entry" on purpose: Symfony renders a collection's
 * prototype under the block prefix `collection_entry`, so a type of that name
 * collides with it and the form refuses to render.
 *
 * @extends AbstractType<array<string, mixed>>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
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

        if (!$collection->hasVariants()) {
            $builder->add('fields', RecordType::class, ['shape' => $collection, 'label' => false]);

            return;
        }

        // **A row's kind decides its fields, and the kind is in the row's own
        // data** (XIV-20) — which the form only learns when the data arrives.
        // PRE_SET_DATA is the framework's answer to exactly that, and using it
        // is what lets a collection hold an article line and a comment line in
        // one list without the two knowing about each other.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($collection): void {
            $data = $event->getData();
            $values = \is_array($data) && \is_array($data['fields'] ?? null) ? $data['fields'] : [];

            $event->getForm()->add('fields', RecordType::class, [
                'shape' => $collection,
                'variant' => $collection->variantOf($values),
                'lock_variant' => true,
                'label' => false,
            ]);
        });
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

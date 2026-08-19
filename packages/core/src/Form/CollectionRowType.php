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
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
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
        // Where the row sits, as a number somebody can type over (XIV-21). A
        // plain input rather than move-up and move-down buttons, because those
        // are a form submission each and this is one save — and because typing
        // 15 between 10 and 20 is a thing people already know how to do.
        $builder->add('position', IntegerType::class, [
            'required' => false,
            'label' => false,
            'attr' => ['class' => 'row-position', 'aria-label' => 'Position'],
        ]);

        if (!$collection->hasVariants()) {
            $builder->add('fields', RecordType::class, ['shape' => $collection, 'label' => false]);

            return;
        }

        $generated = $options['generated_kind'];

        // **A row's kind decides its fields, and the kind is in the row's own
        // data** (XIV-20) — which the form only learns when the data arrives.
        // Events are the framework's answer to exactly that, and using them is
        // what lets a collection hold an article line and a comment line in one
        // list without the two knowing about each other.
        $fields = static function (FormInterface $form, mixed $data) use ($collection, $generated): void {
            $values = \is_array($data) && \is_array($data['fields'] ?? null) ? $data['fields'] : [];
            $variant = $collection->variantOf($values);

            $form->add('fields', RecordType::class, [
                'shape' => $collection,
                'variant' => $variant,
                'lock_variant' => true,
                // **A row of the generated kind is read-only, whole** (XIV-104).
                // It is the same argument the line above makes about the kind, one
                // step further: a discount line is worked out from the voucher the
                // document names on every save, so every control on it would be a
                // control that reverts. The enforcement is `disabled` and not the
                // template, because a template is a request away from being
                // bypassed and a disabled field ignores what is submitted.
                'lock_fields' => $variant !== null && $variant === $generated,
                'label' => false,
            ]);
        };

        // A row the server put there: its kind is in the data it was given.
        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            static fn (FormEvent $event) => $fields($event->getForm(), $event->getData()),
        );

        // **And a row that arrives from the browser** (XIV-29). `allow_add`
        // builds a submitted row from nothing, so PRE_SET_DATA sees null, asks
        // for the fields of no variant — and a shape's variant-scoped fields
        // belong to *no* variant when there is none, so the row comes out with
        // only the fields every kind shares. Everything else is then dropped on
        // the way in and the save fails on values somebody did type.
        //
        // PRE_SUBMIT is where the kind is legible, so the fields are built again
        // from what was sent. Only when it says something: a row being emptied
        // must keep the fields it had, or clearing one would stop deleting it.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($collection, $fields): void {
            $data = $event->getData();
            $values = \is_array($data) && \is_array($data['fields'] ?? null) ? $data['fields'] : [];

            if ($collection->variantOf($values) !== null) {
                $fields($event->getForm(), $data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('shape')
            ->setAllowedTypes('shape', CollectionDefinition::class)
            // Which kind of row this collection's own module generates, if any
            // (XIV-104) — resolved once by {@see ModuleRecordType}, which is the
            // only thing here holding the module rather than the collection.
            ->setDefault('generated_kind', null)
            ->setAllowedTypes('generated_kind', ['null', 'string'])
            ->setDefaults([
                'data_class' => null,
                'validation_groups' => false,
            ]);
    }
}

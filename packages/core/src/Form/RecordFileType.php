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
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Xivi\Core\Field\AttachmentLimit;
use Xivi\Core\Field\StoredFile;

/**
 * A file on a record, as three controls that add up to one value (XIV-115).
 *
 * ### The shape, and the thing that decided it
 *
 * The record form is a Live Component (§8.3), so **nothing on this page is ever
 * posted by the browser**: the submit is an action, the values travel as JSON,
 * and the component is rebuilt from those values on every keystroke elsewhere on
 * the form. A file cannot travel that way and does not: the library sends any
 * file input in the same `FormData` beside the JSON, where it arrives as an
 * ordinary upload on the request, and {@see \App\Record\RecordUploads} takes it
 * before the form is submitted.
 *
 * That is what the three controls are for, and why the interesting one is the
 * hidden field rather than the file input:
 *
 *  * **`stored`**, hidden, carries the value. It holds what the record holds, a
 *    {@see StoredFile} in its stored spelling, and it is what survives the
 *    round trips: a re-render because a total changed, a refused save that draws
 *    the form again, an upload that happened three actions ago. Without it a
 *    file would silently detach itself from a record the first time somebody
 *    typed in the field next to it.
 *  * **`upload`**, the file input, is **unmapped and always empty on the way
 *    out**. It exists so the browser has something to put a file in and the
 *    library has something to send. Nothing reads its submitted value here: by
 *    the time this form is submitted, the intake has already written the bytes
 *    and put the result into `stored`. A second reader would be a second answer
 *    to "what is on this record", and one of them would be wrong.
 *  * **`remove`**, a tick, is how a file comes off a record. Not a separate
 *    button and not a separate request, so that removing a file is part of
 *    saving the record and is undone by not saving it, like every other change
 *    on the form.
 *
 * ### The one rule, and it is settled before the form is submitted
 *
 * **A new upload beats a tick.** Somebody who ticks "remove" and then chooses a
 * file has changed their mind in the direction of the file, and the tick is left
 * over from before; the opposite reading throws away bytes that were uploaded on
 * purpose. The intake clears the tick when it writes a value, so the mapper
 * below has one rule rather than two and cannot develop an opinion of its own
 * about which of them happened first.
 *
 * @extends AbstractType<string|null>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class RecordFileType extends AbstractType implements DataMapperInterface
{
    /** The hidden control the value actually lives in. */
    public const string STORED = 'stored';

    /** The file input. Its name is what an upload arrives under; see the class docblock. */
    public const string UPLOAD = 'upload';

    /** The tick that takes a file off a record. */
    public const string REMOVE = 'remove';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Never required in the HTML sense, whatever the field says. A
            // required file field is required of the *record*, which
            // RecordValidator decides against the customer's definitions; a
            // browser refusing to submit an empty hidden input would make such a
            // field impossible to fill in for the first time.
            ->add(self::STORED, HiddenType::class, ['required' => false])
            ->add(self::UPLOAD, FileType::class, [
                'label' => 'file.upload',
                'required' => false,
                // Not part of the value, and the docblock says why. `mapped`
                // false is what stops Symfony asking the mapper about it.
                'mapped' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add(self::REMOVE, CheckboxType::class, [
                'label' => 'file.remove',
                'required' => false,
                'mapped' => false,
            ])
            ->setDataMapper($this);
    }

    /**
     * The stored value, into the hidden control.
     *
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $controls = iterator_to_array($forms);

        $controls[self::STORED]->setData(\is_string($viewData) ? $viewData : null);
    }

    /**
     * The controls, into the one value.
     *
     * `remove` is unmapped, so it is not in `$forms` at all and is read off the
     * parent instead, which is where a data mapper is allowed to look at it and
     * the only place it exists. `upload` is never read here; the class docblock
     * says why.
     *
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        $controls = iterator_to_array($forms);
        $stored = $controls[self::STORED]->getData();
        $stored = \is_string($stored) ? trim($stored) : '';

        $parent = $controls[self::STORED]->getParent();
        $removed = $parent?->has(self::REMOVE) === true && $parent->get(self::REMOVE)->getData() === true;

        $viewData = $stored === '' || $removed ? null : $stored;
    }

    /**
     * What the block needs to draw a file that is already there.
     *
     * Parsed here rather than in the template, on the rule the whole form theme
     * follows: a template asking what a value means is a template that has to
     * change when a type is added.
     *
     * @param FormView<FormView>   $view
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $options
     */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['stored_file'] = StoredFile::parse($form->getData());
        // The ceiling the block prints, taken from the constant the intake
        // enforces rather than restated: one number, one place (§5.30).
        $view->vars['max_bytes'] = AttachmentLimit::MAX_BYTES;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // One value, mapped by hand above, and not an object the form should
            // try to instantiate.
            'data_class' => null,
            'empty_data' => null,
            // Every control inside is drawn by the block, and a compound type
            // that let its children be labelled would draw the field's own label
            // three times.
            'label' => false,
            'error_bubbling' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'xivi_file';
    }
}

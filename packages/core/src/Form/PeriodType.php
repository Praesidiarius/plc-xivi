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
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Xivi\Core\Period\Period;
use Xivi\Core\Period\PeriodPrecision;

/**
 * One period, as two boxes and the sentence that makes an empty one deliberate
 * (XIV-136).
 *
 * ### Three controls, because two of them cannot answer the question
 *
 * A start, an end, and a checkbox that says there is **no** end. The checkbox is
 * the whole reason this is a form type rather than two fields side by side: a
 * tenancy with no agreed end is an ordinary thing, and the only other way to
 * express it is an empty box — which is also what a half-filled form looks like,
 * and what a distracted afternoon looks like. §8.3.1's argument, applied to a
 * blank: a control that means two opposite things depending on what somebody
 * intended is a control that reports nothing.
 *
 * So the two states are told apart, and neither is guessed at:
 *
 *  * an end date, ticked or not → the period ends there. **A typed value always
 *    wins over the checkbox**, because dropping something somebody typed is the
 *    one outcome no reading of the form justifies.
 *  * no end date and ticked → `[from, ∞)`, deliberately.
 *  * no end date and not ticked → **refused**, by
 *    {@see \Xivi\Core\Validation\ValidPeriodValidator}, saying to fill the box in
 *    or tick the tick. Not accepted-as-open, which would make the deliberate case
 *    and the forgotten case the same case again.
 *
 * ### What the labels have to say, and why they say it here
 *
 * The end bound is exclusive ({@see Period}), which is arithmetically the right
 * answer and is genuinely surprising: a tenancy whose last day is the 5th ends on
 * the **6th**. The place to explain that is next to the box being typed into,
 * once, in the field's own help text — not in a manual, and not in a release note
 * nobody reads on the afternoon they are entering a booking.
 *
 * ### A data mapper rather than property paths
 *
 * The three controls are not three values: what comes out is one. Symfony's own
 * seam for that is {@see DataMapperInterface}, so this maps in and out by hand and
 * the record ends up holding one key — which is what "the engine knows it is one
 * value" means when you go looking for where it is true.
 *
 * The mapper hands out an **array** rather than a {@see Period}, and that is
 * deliberate: a `Period` cannot express "no end, and nobody said so", which is
 * precisely the state that has to survive as far as the validator. The one place
 * that reads it — {@see \Xivi\Core\Field\Type\PeriodFieldType::toStorage()} — is
 * the seam every value goes through anyway.
 *
 * @extends AbstractType<array<string, mixed>>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class PeriodType extends AbstractType implements DataMapperInterface
{
    public const string FROM = 'from';
    public const string UNTIL = 'until';

    /** The tick that turns a blank end into a statement. */
    public const string OPEN_ENDED = 'open_ended';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $precision = $options['precision'];
        \assert($precision instanceof PeriodPrecision);

        $builder
            ->add(self::FROM, $precision->formType(), [
                ...self::widget($precision, $options),
                'label' => 'period.from',
                'required' => $options['required'],
            ])
            ->add(self::UNTIL, $precision->formType(), [
                ...self::widget($precision, $options),
                'label' => 'period.until',
                // Never required, whatever the field is: a period with no end is
                // a value this holds on purpose, and the checkbox below is what
                // says so.
                'required' => false,
                'help' => 'period.until_help',
            ])
            ->add(self::OPEN_ENDED, CheckboxType::class, [
                'label' => 'period.open_ended_label',
                'required' => false,
            ])
            ->setDataMapper($this);
    }

    /**
     * What one end of the period is drawn with.
     *
     * `single_text`, so it is the browser's own date or datetime picker rather
     * than six dropdowns — the same call {@see \Xivi\Core\Field\Type\DateFieldType}
     * makes, one control further.
     *
     * **`view_timezone` is the whole of [XIV-83] on the way in.** The model is
     * UTC because everything stored here is (§8.4.4), and what somebody types is
     * their own wall clock; Symfony converts between the two, so a booking typed
     * as `00:30` in Zurich is stored as `23:30Z` the day before without anybody
     * doing arithmetic. A date has no zone and is given none — see
     * {@see PeriodPrecision::write()} for why converting one would move it.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function widget(PeriodPrecision $precision, array $options): array
    {
        $widget = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'html5' => true];

        if ($precision === PeriodPrecision::Date) {
            return $widget;
        }

        $zone = $options['view_timezone'];
        \assert($zone instanceof \DateTimeZone);

        return [...$widget, 'model_timezone' => 'UTC', 'view_timezone' => $zone->getName()];
    }

    /**
     * The stored pair, into the three controls.
     *
     * Accepts a {@see Period} — what a record holds once
     * {@see \Xivi\Core\Field\Type\PeriodFieldType::fromStorage()} has read it —
     * and an array, which is what it gets back when a submitted form is
     * re-rendered because something else on the record failed validation.
     *
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapDataToForms(mixed $viewData, \Traversable $forms): void
    {
        $controls = iterator_to_array($forms);

        [$from, $until, $open] = match (true) {
            $viewData instanceof Period => [$viewData->from, $viewData->until, $viewData->isOpenEnded()],
            \is_array($viewData) => [
                $viewData[self::FROM] ?? null,
                $viewData[self::UNTIL] ?? null,
                (bool) ($viewData[self::OPEN_ENDED] ?? false),
            ],
            default => [null, null, false],
        };

        $controls[self::FROM]->setData($from);
        $controls[self::UNTIL]->setData($until);
        $controls[self::OPEN_ENDED]->setData($open);
    }

    /**
     * The three controls, into the one value.
     *
     * @param \Traversable<string, FormInterface<mixed>> $forms
     */
    public function mapFormsToData(\Traversable $forms, mixed &$viewData): void
    {
        $controls = iterator_to_array($forms);

        $viewData = [
            self::FROM => $controls[self::FROM]->getData(),
            self::UNTIL => $controls[self::UNTIL]->getData(),
            self::OPEN_ENDED => (bool) $controls[self::OPEN_ENDED]->getData(),
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('precision')
            ->setAllowedTypes('precision', PeriodPrecision::class)
            // The reader's zone, handed in by the field type rather than resolved
            // here: a form type that asked who was reading would be a second
            // answer to [XIV-83]'s question living in a layer that has no business
            // holding one.
            ->setDefault('view_timezone', new \DateTimeZone('UTC'))
            ->setAllowedTypes('view_timezone', \DateTimeZone::class)
            ->setDefaults([
                // A period is not an entity and not an array of two records: it
                // is one value, mapped by hand above.
                'data_class' => null,
                'error_bubbling' => false,
                // The record's own validator owns whether a value is acceptable
                // (§5), exactly as it does for every other field.
                'validation_groups' => false,
                'compound' => true,
            ]);
    }
}

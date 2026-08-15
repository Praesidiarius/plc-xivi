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

namespace Xivi\Core\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Xivi\Core\Money\InstanceCurrency;

/**
 * An amount, with the installation's currency in front of it (XIV-11).
 *
 * Deliberately not Symfony's MoneyType, which puts the currency wherever the
 * reader's locale puts it — after the number in German, before it in English.
 * A price field that moves its own label depending on who is looking is a worse
 * answer than one that is always read the same way, so the currency sits in
 * front and the widget says so.
 *
 * **The currency is a view variable, not a value.** It comes from the profile
 * (§8.6) at render time and is never submitted: an installation has one
 * currency, so accepting one per record would be a field the customer could
 * disagree with themselves in.
 *
 * `input: 'string'` all the way through, because a price is a decimal and PHP
 * floats are not. What is stored is what was typed, to the cent.
 *
 * @extends AbstractType<string>
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class MoneyAmountType extends AbstractType
{
    public const int SCALE = 2;

    public function __construct(private readonly InstanceCurrency $currency)
    {
    }

    public function getParent(): string
    {
        return NumberType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'scale' => self::SCALE,
            'input' => 'string',
            // Not an html5 number input: it insists on a dot, and somebody
            // entering a price in German types a comma. The localized
            // transformer understands both ways round.
            'html5' => false,
            'grouping' => false,
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // Null when nobody has chosen one, and the widget then renders a plain
        // input rather than an empty box in front of the number.
        $view->vars['currency'] = $this->currency->code();
    }

    public function getBlockPrefix(): string
    {
        return 'xivi_money_amount';
    }
}

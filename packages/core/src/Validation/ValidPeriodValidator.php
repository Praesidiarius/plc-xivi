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

namespace Xivi\Core\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Xivi\Core\Period\PeriodPrecision;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ValidPeriodValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidPeriod) {
            throw new UnexpectedTypeException($constraint, ValidPeriod::class);
        }

        // An empty optional field, and a non-string that Assert\Type has already
        // refused — neither is this constraint's to talk about.
        if ($value === null || $value === '' || !\is_string($value)) {
            return;
        }

        $separator = PeriodPrecision::SEPARATOR;
        $open = PeriodPrecision::OPEN;

        // The two halves that toStorage() deliberately hands over unstorable, so
        // that a missing end and a missing start are told apart from gibberish.
        // Checked before parsing, because parsing cannot tell them apart: both
        // simply fail.
        if (str_starts_with($value, $open . $separator)) {
            $this->context->buildViolation($constraint->needsAStart)->addViolation();

            return;
        }

        if (str_ends_with($value, $separator)) {
            $this->context->buildViolation($constraint->needsAnEnd)->addViolation();

            return;
        }

        $period = $constraint->precision->read($value);

        if ($period === null) {
            $this->context->buildViolation($constraint->notAPeriod)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->addViolation();

            return;
        }

        // `>=` rather than `>`: under the half-open bound a period that ends the
        // moment it starts holds nothing, and Postgres stores it as `empty` — a
        // value that overlaps nothing and is invisible to the constraint the
        // field probably exists for.
        if ($period->until !== null && $period->from !== null && $period->until <= $period->from) {
            $this->context->buildViolation($constraint->endsBeforeItStarts)->addViolation();
        }
    }
}

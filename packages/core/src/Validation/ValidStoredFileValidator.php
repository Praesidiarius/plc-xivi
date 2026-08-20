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
use Xivi\Core\Field\StoredFile;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ValidStoredFileValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidStoredFile) {
            throw new UnexpectedTypeException($constraint, ValidStoredFile::class);
        }

        // An empty optional field, and a non-string that Assert\Type has already
        // refused. Neither is this constraint's to talk about.
        if ($value === null || $value === '' || !\is_string($value)) {
            return;
        }

        if (StoredFile::parse($value) !== null) {
            return;
        }

        $this->context->buildViolation($constraint->notAStoredFile)
            // Truncated, because the value that reaches here is somebody's whole
            // spreadsheet cell and a paragraph of it in a form error is a form
            // nobody can read.
            ->setParameter('{{ value }}', $this->formatValue(mb_substr($value, 0, 40)))
            ->addViolation();
    }
}

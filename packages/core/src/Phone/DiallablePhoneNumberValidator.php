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

namespace Xivi\Core\Phone;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class DiallablePhoneNumberValidator extends ConstraintValidator
{
    public function __construct(private readonly PhoneNumbers $numbers)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DiallablePhoneNumber) {
            throw new UnexpectedTypeException($constraint, DiallablePhoneNumber::class);
        }

        // An empty field is not a bad number. Whether it may be empty at all is
        // the field's `required` flag, added by the caller (§5.4), which is a
        // different question and already has an answer.
        if ($value === null || $value === '') {
            return;
        }

        // The same reading `toStorage()` did, run again rather than passed
        // along. Two mechanisms could have carried the reason across — a
        // per-request cache keyed by value, or a normaliser that recorded its
        // failures — and both are state living between two calls that are
        // otherwise independent (§7.4). A second parse of one string is
        // microseconds; a cache that is subtly wrong about which field it
        // belonged to is a refusal naming the wrong country.
        $reading = $this->numbers->read($value, $constraint->region);

        if ($reading->isNumber()) {
            return;
        }

        $message = match ($reading->problem) {
            PhoneProblem::NoCountry => $constraint->noCountry,
            PhoneProblem::NotDiallable => $constraint->notDiallable,
            PhoneProblem::CarriesAnExtension => $constraint->carriesAnExtension,
            default => $constraint->notANumber,
        };

        $this->context->buildViolation($message)
            ->setParameter('{{ value }}', $this->formatValue($value))
            // The country as somebody would say it rather than as ISO writes it:
            // "cannot be dialled in Switzerland" is a sentence, "cannot be
            // dialled in CH" is a diagnostic. In the language being read, from
            // ext-intl, which is where CurrencyFieldType and DateFieldType
            // already get their locale-shaped answers.
            ->setParameter('{{ country }}', self::countryName($reading->region ?? $constraint->region))
            ->addViolation();
    }

    /**
     * Falls back to the code itself, and then to a word, because this parameter
     * appears in a sentence that has to read as one.
     *
     * `notDiallable` is the only message that uses it and it is reached only
     * when a region was known, so the null branch is unreachable through the
     * field type — it is here because a constraint can be attached by hand, and
     * a sentence with a hole in it is a worse outcome than a vague one.
     */
    private static function countryName(?string $region): string
    {
        if ($region === null || $region === '') {
            return 'this country';
        }

        $name = \Locale::getDisplayRegion('und-' . $region, \Locale::getDefault());

        return $name === '' ? strtoupper($region) : $name;
    }
}

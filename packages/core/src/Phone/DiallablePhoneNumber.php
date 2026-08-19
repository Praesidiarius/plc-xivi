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

/**
 * A value in a `phone` field is a number somebody could ring (XIV-114).
 *
 * **It validates the stored form, which is the only reason it can say anything
 * useful.** `RecordValidator` normalises before it validates, in its own words:
 * "values are validated in the shape they will be stored in". So by the time
 * this runs, {@see \Xivi\Core\Field\Type\PhoneFieldType::toStorage()} has either
 * turned what was typed into E.164 — in which case there is nothing left to
 * refuse — or handed the original string straight back because it could not.
 * This constraint is therefore about exactly one thing: the values `toStorage()`
 * gave up on.
 *
 * ### Four messages, because there are four different things to do next
 *
 * A single "not a valid phone number" would be true of all four and actionable in
 * none. Each of these names the value, and the two that turn on the country name
 * the country as well, because `079 123 45 67` being refused is bewildering
 * until somebody is told it was read as a German number.
 *
 * They are sentences rather than keys because that is how Symfony translates a
 * constraint message — through the `validators` domain, keyed by the English text
 * — which is what `translations/validators.*.yaml` is for and where the German
 * lives.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class DiallablePhoneNumber extends Constraint
{
    /** Letters, a handful of digits, punctuation: nothing a number can be read out of. */
    public string $notANumber = '{{ value }} is not a phone number.';

    /**
     * The refusal that is about the installation rather than about what was
     * typed, and the only one that names something to go and change.
     */
    public string $noCountry = '{{ value }} has no country code, and this installation has not said which '
        . 'country it is in — so there is no way to tell which number it is. Type it in full, like '
        . '+41 79 123 45 67, or set the country on the instance profile.';

    /** Read as a number, and not one that country has. */
    public string $notDiallable = '{{ value }} is not a phone number that can be dialled in {{ country }}. '
        . 'Check the digits, or type it in full with its country code.';

    /**
     * The one refusal of a value that is not wrong, only unstorable — see
     * {@see PhoneProblem::CarriesAnExtension} for why E.164 leaves no room for
     * it.
     */
    public string $carriesAnExtension = '{{ value }} carries an extension. A number is stored without one, '
        . 'so the extension would be silently dropped — put it in a field of its own.';

    /**
     * @param string|null $region the country this field reads values against,
     *                            resolved when the constraints were built
     *                            ({@see PhoneNumbers::regionFor()}) rather than
     *                            looked up here: a constraint is a value object
     *                            and the field it belongs to is not in scope by
     *                            the time it runs
     */
    public function __construct(
        public ?string $region = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);
    }
}

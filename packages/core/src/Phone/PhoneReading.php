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

/**
 * What came of trying to read one string as a phone number (XIV-114).
 *
 * Two callers need slightly different halves of the same work and neither may do
 * it twice: {@see \Xivi\Core\Field\Type\PhoneFieldType::toStorage()} wants the
 * canonical form or nothing, and {@see DiallablePhoneNumberValidator} wants the
 * reason it was nothing. An exception would have been the other shape and is
 * wrong here, because `toStorage()` is called by the query compiler on a filter
 * box somebody is still typing into — an unfinished number is an ordinary
 * condition on that path, not an exceptional one.
 *
 * Exactly one of the two properties is set. A value object rather than a tuple
 * so that the impossible third state — a number *and* a problem — cannot be
 * constructed.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PhoneReading
{
    private function __construct(
        /** E.164, `+41791234567`, or null when it could not be read as one. */
        public ?string $e164,
        /** Why not, or null when it could. */
        public ?PhoneProblem $problem,
        /**
         * The country it was actually read against, which is not always the one
         * that was asked for.
         *
         * A value written `+41 …` names its own country, and it names it whether
         * or not the installation has ever filled its region in. So a refusal
         * about such a value can say "cannot be dialled in Switzerland" rather
         * than "in this country" — which is the difference between a sentence
         * somebody can check and one they can only shrug at.
         */
        public ?string $region = null,
    ) {
    }

    public static function number(string $e164): self
    {
        return new self($e164, null);
    }

    public static function refused(PhoneProblem $problem, ?string $region = null): self
    {
        return new self(null, $problem, $region);
    }

    public function isNumber(): bool
    {
        return $this->e164 !== null;
    }
}

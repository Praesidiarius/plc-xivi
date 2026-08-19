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
use Xivi\Core\Period\PeriodPrecision;

/**
 * A value in a period field is a period somebody could live through (XIV-136).
 *
 * **It validates the stored form**, like {@see \Xivi\Core\Phone\DiallablePhoneNumber}
 * and for the same reason: `RecordValidator` normalises before it validates, so
 * by the time this runs {@see \Xivi\Core\Field\Type\PeriodFieldType::toStorage()}
 * has turned what was submitted into the one canonical spelling, or has produced
 * something deliberately unstorable for this to name.
 *
 * ### Four messages, because there are four different things to do next
 *
 * The interesting one is `needsAnEnd`. A period with a start and no end is
 * **not** refused because open-ended periods are illegal — they are the reason
 * this whole shape exists — it is refused because nobody said which of the two
 * they meant, and the form has a tick for saying it
 * ({@see \Xivi\Core\Form\PeriodType}). Accepting a blank as "runs for ever" would
 * turn every half-finished form into a tenancy with no end, and nothing would
 * ever have said so.
 *
 * `endsBeforeItStarts` is where the bound gets said out loud, and it is
 * deliberately not "the end must be on or after the start": under `[from, until)`
 * a period ending the moment it begins contains nothing at all — a stay of no
 * nights, a meeting of no minutes — and Postgres would store it as `empty`, a
 * value that overlaps nothing and is therefore invisible to the very constraint
 * somebody added the field for.
 *
 * Sentences rather than keys, because that is how Symfony translates a constraint
 * message: through the `validators` domain, keyed by the English text.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ValidPeriod extends Constraint
{
    /** Nothing a pair of dates can be read out of. */
    public string $notAPeriod = '{{ value }} is not a period.';

    /** The half that makes it one is missing. */
    public string $needsAStart = 'A period has to start somewhere. Fill in the first date.';

    /** The blank that has to be a decision — see the class docblock. */
    public string $needsAnEnd = 'Say when this period ends, or tick that it has no end date.';

    /**
     * The bound, stated where somebody meets it: `until` is the moment the period
     * *stops*, so it has to be after the moment it starts.
     */
    public string $endsBeforeItStarts = 'This period ends before it starts. The second date is the moment '
        . 'the period stops rather than its last day, so it has to come after the first one.';

    /**
     * @param PeriodPrecision $precision days or moments, resolved when the constraints
     *                                   were built: a constraint is a value object and
     *                                   the field it belongs to is not in scope by the
     *                                   time it runs
     */
    public function __construct(
        public PeriodPrecision $precision = PeriodPrecision::Date,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);
    }
}

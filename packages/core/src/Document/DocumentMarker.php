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

namespace Xivi\Core\Document;

/**
 * One thing a template can write into itself (XIV-4).
 *
 * The user story asks for a list of these per module and per shape, which is the
 * only way somebody writing a template in Word knows what to type. So the list is
 * derived from the customer's own definitions rather than documented anywhere: a
 * field they added this morning is a marker this afternoon, and one they removed
 * stops being offered.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DocumentMarker
{
    public function __construct(
        /** The key inside the brackets, e.g. `first_name`. */
        public string $key,
        /** What it is called on the reference list — the field's own label. */
        public string $label,
        /** What it fills in for the record being looked at, when one is at hand. */
        public ?string $example = null,
    ) {
    }

    /** What somebody types into the Word document, brackets and all. */
    public function token(): string
    {
        return sprintf('[%s]', $this->key);
    }
}

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

namespace App\Store;

/**
 * One preset, with what it actually contains spelled out (XIV-6).
 *
 * The wizard's whole job on this point is that the choice is **informed**, and a
 * dropdown of two words is not that. §6.1 says installing does not retro-fit and
 * XIV-70 has not been built, so a customer who picks `basic` and later wants
 * `extended` has no path at all — which makes "what is in each" the single most
 * important thing on the screen and not a detail behind a tooltip.
 *
 * So the field *labels* are resolved here, from the module's own catalogue in the
 * language being read, and handed to the template as a list. Keys would have been
 * cheaper and would have shown somebody `vat_rate` where they needed to read
 * "VAT rate" — which is the same reason the installer seeds translated labels
 * rather than storing keys (§5, XIV-8).
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class PresetOffer
{
    /** @param list<string> $fields the labels, in the blueprint's own order */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public array $fields,
        /**
         * Whether this is the one an install with no choice would use. Shown
         * rather than pre-selected only: the wizard makes somebody choose, and a
         * default nobody looked at is the guess this ticket exists to prevent.
         */
        public bool $isDefault,
    ) {
    }

    public function fieldCount(): int
    {
        return \count($this->fields);
    }
}

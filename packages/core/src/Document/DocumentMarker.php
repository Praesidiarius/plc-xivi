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
        /**
         * Whether this one writes words or draws a picture (XIV-89).
         *
         * Text unless somebody says otherwise, because everything was text until
         * `[tenant.logo]` and almost everything still is. An image marker carries
         * no {@see self::$example}: what it is worth is bytes rather than a
         * string, and they are fetched separately by whatever is actually
         * drawing — see {@see DocumentContext::images()}.
         */
        public DocumentMarkerKind $kind = DocumentMarkerKind::Text,
    ) {
    }

    /** What somebody types into the Word document, brackets and all. */
    public function token(): string
    {
        return sprintf('[%s]', $this->key);
    }

    /**
     * Whether this marker draws rather than writes (XIV-89).
     *
     * A method rather than letting callers compare the enum themselves, because
     * the two callers that care are Twig templates and Twig cannot name a PHP
     * enum case without help.
     */
    public function isImage(): bool
    {
        return $this->kind === DocumentMarkerKind::Image;
    }
}

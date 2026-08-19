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

namespace Xivi\Core\Field;

use Xivi\Core\ValueList\ValueIcon;
use Xivi\Core\ValueList\ValueTone;

/**
 * One value drawn as a chip: its label, and the colour and picture it carries
 * (XIV-127).
 *
 * **Not markup**, which is the decision worth writing down. The obvious shape is
 * a method returning `<span class="badge …">`, and it loses for
 * {@see \Xivi\Core\Twig\FieldDisplayExtension::formatted()}'s reason read
 * backwards: markup built in PHP is markup a template cannot escape, and the
 * only part of this that is a customer's own typing — the label — has to be
 * escaped by Twig like every other value on the page. So what comes out is the
 * three pieces, and the template composes them.
 *
 * It is also what keeps one badge from becoming four. A record page wants a
 * chip, a list cell wants a smaller one, a document wants no colour at all and a
 * picker wants an indent; a finished string would need a method per caller, and
 * §5.4 already refused to invent a widget-description language to save two
 * `{% if %}`s.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ValueBadge
{
    public function __construct(
        /** What the customer typed. Escaped by the template, like any other value. */
        public string $label,
        /**
         * The Bootstrap context word, or null.
         *
         * Composed into `bg-{tone}-subtle`, `text-{tone}-emphasis` and
         * `border-{tone}-subtle` by whoever draws it — all three of which
         * Bootstrap 5.3 redefines under `[data-bs-theme=dark]`, which is what
         * makes this survive a dark page. {@see ValueTone} has the argument for
         * why it is one of eight rather than a hex.
         */
        public ?ValueTone $tone = null,
        /** The Bootstrap Icons name, or null. Takes `currentColor`, so it follows the tone. */
        public ?ValueIcon $icon = null,
    ) {
    }
}

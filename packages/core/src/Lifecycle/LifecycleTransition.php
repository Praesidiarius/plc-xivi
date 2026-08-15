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

namespace Xivi\Core\Lifecycle;

/**
 * One legal move between states (XIV-14).
 *
 * Named after what somebody does — "send", "cancel" — rather than after where it
 * lands, because the name is what a button says and a state is what a record is.
 * More than one `from` is ordinary: an invoice can be cancelled from draft and
 * from sent alike.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class LifecycleTransition
{
    /** @param list<string> $from */
    public function __construct(
        public string $name,
        public array $from,
        public string $to,
        /**
         * A key in the module's own translation catalogue, like a field's label
         * (XIV-8). Null uses `lifecycle.<name>` in that catalogue.
         */
        public ?string $label = null,
    ) {
    }

    public function labelKey(): string
    {
        return $this->label ?? 'lifecycle.' . $this->name;
    }
}

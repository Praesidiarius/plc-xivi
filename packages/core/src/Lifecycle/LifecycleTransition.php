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
 * **There is deliberately no condition on it, and the reason is written down
 * rather than merely absent** (XIV-88). "Confirming an order needs at least one
 * line" has no home anywhere in the engine today — field validation is per field
 * and unconditional, so it would demand the line of a draft too, and
 * {@see \Xivi\Core\Record\RecordWriter} validates nothing at all. That hole is
 * real and a guard is worth having. What was rejected is *how*: Symfony's
 * ExpressionLanguage was proposed for it, and a guard turns out to be the one
 * candidate in the whole system that passes both of the rules this project has
 * learned — it is a boolean over one record already in hand, and nothing has to
 * read it statically — and to fail on a third thing entirely. A lifecycle is
 * declared by a module, in code (§6.1), and against code an expression string is
 * strictly worse than a typed predicate: PHPStan cannot see into it, neither can
 * an IDE, and renaming a field key breaks it in silence. An evaluator earns its
 * keep only where the author cannot ship PHP, which means a customer, and a
 * customer cannot author a lifecycle at all — there is nowhere in the tenant's
 * metadata for a per-transition option to live. So when a condition arrives it
 * belongs here as a predicate, and the argument in full is in §5.8.
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

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
 * A move the record's state allows, and the reason its guard will not let it
 * happen — or nothing, when it will (XIV-110).
 *
 * **One value rather than two lists, because a page makes one decision out of
 * both halves.** The record page draws a button or it draws a sentence, exactly
 * as the send card beside it already does with a recipient it cannot resolve
 * (XIV-39): "no button" and "a reason instead of one" are two outcomes of the
 * same question, and asking it twice would mean evaluating the same predicate
 * twice against the same record.
 *
 * **A refused move is shown rather than silently dropped, and that is the whole
 * argument for this class.** Hiding the button is the courtesy §5.8 asks for — a
 * transition offered and then refused is worse than one not offered — but a
 * refusal nobody ever reads is a message written for a POST that only somebody
 * retyping a URL will ever make. The sentence the module went to the trouble of
 * writing belongs where the person who has to act on it is looking, which is the
 * page they are already on, next to where the button would have been.
 *
 * The distinction this does *not* carry is the one between "not from here" and
 * "not yet". A move the record's state forbids is not offered at all and is not
 * in this list: an order that has been delivered is not waiting for anything, and
 * printing "an order needs at least one line" underneath it would be answering a
 * question nobody asked.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class TransitionOffer
{
    public function __construct(
        public LifecycleTransition $transition,
        /**
         * A translation key in the module's own catalogue, from the guard that
         * refused; null when there is nothing in the way.
         */
        public ?string $refusal = null,
    ) {
    }

    public function isPossible(): bool
    {
        return $this->refusal === null;
    }
}

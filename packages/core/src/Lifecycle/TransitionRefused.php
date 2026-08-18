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

use Symfony\Component\Translation\TranslatableMessage;

/**
 * That move is not allowed (XIV-14).
 *
 * Carries what *was* possible, because "cannot" on its own is the least useful
 * refusal a system can give: somebody looking at a stale page needs to know the
 * record moved on without them, not merely that they were wrong.
 *
 * **Three refusals, and the third is a different kind from the other two**
 * (XIV-110). "Not a step this record has" and "not from where it is" are facts
 * about the lifecycle, and the engine can phrase both from what it knows.
 * {@see self::notReady()} is a fact about the record, phrased by the module —
 * the engine has no idea why an order is not confirmable, and the whole value of
 * the mechanism is in the sentence it does not write itself.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class TransitionRefused extends \RuntimeException
{
    private TranslatableMessage $translatable;

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    /** @param list<LifecycleTransition> $possible */
    public static function notFromHere(string $transition, string $state, array $possible): self
    {
        $names = implode(', ', array_map(
            static fn (LifecycleTransition $t): string => $t->name,
            $possible,
        ));

        $refusal = new self(sprintf(
            'Cannot "%s" from "%s". Possible from here: %s.',
            $transition,
            $state,
            $names === '' ? 'nothing' : $names,
        ));

        $refusal->translatable = new TranslatableMessage(
            $possible === [] ? 'lifecycle.refused_final' : 'lifecycle.refused',
            ['%state%' => $state, '%possible%' => $names],
            'xivi',
        );

        return $refusal;
    }

    /**
     * The move is legal from here and the record is not ready for it (XIV-110).
     *
     * **The message is the module's own and nothing here rephrases it.** "Cannot
     * confirm" is a refusal somebody can do nothing with; "an order needs at
     * least one line before it can be confirmed" tells them what to fix, and only
     * the module that declared the lifecycle knows how to say that about its own
     * records. So the guard hands back a key, this puts it together with the
     * module's own catalogue — the same domain the transition's *label* is read
     * from, so the button and the reason it is missing are written in the same
     * file, by the same person, in the same voice.
     *
     * No parameters go with it. A guard that wanted to name a number in its
     * sentence would need them, and that day this gains an argument; inventing
     * the plumbing before there is a sentence that needs it would be guessing at
     * which values a message wants.
     *
     * @param string $reason a translation key in the module's own catalogue
     * @param string $domain the module's key, which is that catalogue's name
     */
    public static function notReady(string $transition, string $reason, string $domain): self
    {
        $refusal = new self(sprintf(
            'The record is not ready to "%s": %s.',
            $transition,
            $reason,
        ));

        $refusal->translatable = new TranslatableMessage($reason, [], $domain);

        return $refusal;
    }

    public static function unknown(string $transition, Lifecycle $lifecycle): self
    {
        $refusal = new self(sprintf(
            'No transition "%s" in this lifecycle. Known: %s.',
            $transition,
            implode(', ', array_map(
                static fn (LifecycleTransition $t): string => $t->name,
                $lifecycle->transitions,
            )),
        ));

        // Hand-edited: the form only ever offers what is enabled, so this is
        // somebody typing rather than somebody mistaken about the state.
        $refusal->translatable = new TranslatableMessage('lifecycle.refused_unknown', [], 'xivi');

        return $refusal;
    }
}

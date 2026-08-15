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
 * That move is not allowed from here (XIV-14).
 *
 * Carries what *was* possible, because "cannot" on its own is the least useful
 * refusal a system can give: somebody looking at a stale page needs to know the
 * record moved on without them, not merely that they were wrong.
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

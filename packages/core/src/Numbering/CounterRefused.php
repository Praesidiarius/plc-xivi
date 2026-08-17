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

namespace Xivi\Core\Numbering;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * A counter the engine will not move (XIV-27).
 *
 * There is exactly one of these, and it guards the only thing in this feature
 * that can hand two documents the same number: winding a counter *back*.
 * Everything else about numbering is safe by construction — the pattern decides
 * what a number looks like and never which numbers exist, and allocation is one
 * atomic statement — so this is the whole of what a customer can get wrong, and
 * it is worth its own exception rather than being folded into
 * {@see \Xivi\Core\Metadata\MetadataChangeRefused}. A counter is not metadata:
 * it is a fact about what has already been given out, and the person who set it
 * is not necessarily editing a definition at the time.
 *
 * It carries a translatable, shaped exactly like the metadata refusals do, so a
 * controller can flash either without asking which it caught. Two audiences: the
 * exception message is English and goes to the log, and the translatable is the
 * sentence a customer reads.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class CounterRefused extends \RuntimeException
{
    private TranslatableMessage $translatable;

    public function translatable(): TranslatableMessage
    {
        return $this->translatable;
    }

    /**
     * A counter cannot go back, because the numbers below it are on documents.
     *
     * The message names where the counter stands, because that is what somebody
     * who has just been refused needs in order to type a number that works —
     * "too low" on its own sends them guessing upward one attempt at a time.
     *
     * The period is named too, and it is the part that surprises people: a
     * pattern with `{year}` in it has one counter per year, so being refused at
     * 1043 on the 2026 counter says nothing at all about 2025's.
     */
    public static function cannotGoBack(string $period, int $at, int $wanted): self
    {
        $refusal = new self(sprintf(
            'The counter for %s stands at %d, so %d is at or below a number already given out. A counter only '
            . 'moves forward: numbers below it are on documents somebody is holding.',
            $period === '' ? 'this field' : $period,
            $at,
            $wanted,
        ));

        $refusal->translatable = new TranslatableMessage(
            // Two keys rather than one with a conditional in the sentence: the
            // period is a year or it is nothing at all, and "the counter for "
            // followed by an empty string is the kind of sentence that ships.
            $period === '' ? 'numbering.counter_back' : 'numbering.counter_back_in',
            ['%period%' => $period, '%at%' => $at, '%wanted%' => $wanted],
            'xivi',
        );

        return $refusal;
    }
}

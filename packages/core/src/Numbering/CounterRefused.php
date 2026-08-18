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
 * There were exactly two ways to hand two documents the same number, and both
 * are here. Winding a counter *back* was the first (XIV-27); setting it onto a
 * number a record already carries, which no counter ever gave out and no counter
 * can see, was the second (XIV-91). Everything else about numbering is safe by
 * construction — the pattern decides what a number looks like and never which
 * numbers exist, and allocation is one atomic statement — so these two are the
 * whole of what a customer can get wrong, and they are worth their own exception
 * rather than being folded into
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

    /**
     * A counter cannot be set onto a number a record is already carrying
     * (XIV-91).
     *
     * The second refusal, and the one {@see NumberAllocator::restartAt()}
     * structurally cannot make. That guard compares against the counter and is
     * complete about every number the counter gave out; this compares against
     * the **column**, and catches the numbers nobody's counter ever gave out —
     * the ones a person typed into a text field before it was numbered. A
     * customer arriving from another system types 1043 and means it; if
     * `RE-2026-1043` is sitting on a record from before the migration, they have
     * asked for a duplicate without knowing it.
     *
     * It is raised *before* `restartAt()` rather than instead of it, and the
     * difference matters: a scan can be raced and a statement cannot, so this
     * one narrows what gets as far as the guard and the guard is still what
     * makes the promise. Two checks, two failure modes, and neither is the
     * other's fallback.
     *
     * The message names the highest number found in the records rather than the
     * one that was typed, because that is the fact somebody can act on: it tells
     * them where their old numbering got to, which is usually the thing they
     * were trying to type in the first place.
     *
     * @param string $highest the largest number already in the column, rendered
     *                        the way it appears on a record rather than as a bare
     *                        integer — somebody looking for it will be looking for
     *                        `RE-2026-1043`, not for 1043
     * @param int    $free    the lowest value the counter may be set to
     */
    public static function alreadyOnARecord(string $period, string $highest, int $free, int $wanted): self
    {
        $refusal = new self(sprintf(
            'A record already carries %s, so setting the counter to %d would eventually give that number out a '
            . 'second time. Numbers typed in before this field was numbered are not in the counter, which is '
            . 'why they are looked for in the records instead. The lowest this counter can be set to is %d.',
            $highest,
            $wanted,
            $free,
        ));

        $refusal->translatable = new TranslatableMessage(
            $period === '' ? 'numbering.counter_in_use' : 'numbering.counter_in_use_in',
            ['%period%' => $period, '%number%' => $highest, '%free%' => $free, '%wanted%' => $wanted],
            'xivi',
        );

        return $refusal;
    }
}

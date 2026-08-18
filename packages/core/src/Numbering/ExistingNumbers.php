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

use Xivi\Core\Entity\FieldDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Record\RecordRepository;

/**
 * Reading the column, which is the half of numbering the counter cannot see
 * (XIV-91).
 *
 * ### Why this has to exist at all
 *
 * XIV-27 made a counter refuse to move backwards, in one statement, because two
 * documents carrying the same number is a legal problem an apology does not fix.
 * That guard is complete about the numbers **the counter** gave out and blind to
 * every other kind: a `text` field somebody has been typing `RE-2026-0007` into
 * by hand is full of numbers no counter has ever heard of, and a counter
 * starting at 1 will walk straight through them and render that string onto a
 * second record. The guard reads the counter; the collision is in the column.
 *
 * So the column is read. This is that read, and it is deliberately a **separate
 * mechanism sitting beside the in-statement guard rather than a replacement for
 * it**. A scan is a read and can be raced — a save landing between the scan and
 * the write consumes a number this thought was free — so it is never allowed to
 * be the only thing standing between a customer and a duplicate. What it
 * produces is a *floor*, applied through {@see NumberAllocator::atLeast()},
 * which is monotone and cannot move a counter down however stale the number
 * handed to it turns out to be. Stale here means "too low", the counter refuses
 * to go down, and the worst outcome of a raced scan is a floor that does
 * nothing.
 *
 * ### What it decides is *ours*
 *
 * Not "does this look like a reference" but "could this pattern have rendered
 * this exact text, today". {@see NumberFormat::counterIn()} answers that by
 * running {@see NumberFormat::render()} backwards, so recognition and production
 * are the same rule read in two directions and cannot drift apart. Everything
 * else in the column — `Referenz 12`, a note somebody left, last year's numbers
 * under a `{year}` pattern — is by construction not something this counter will
 * ever produce, and is therefore left exactly where it is.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ExistingNumbers
{
    public function __construct(private RecordRepository $records)
    {
    }

    /**
     * What the column holds, measured against one pattern on one day.
     *
     * Three statements rather than one, and it is worth saying why: the two
     * counts are about *emptiness* and do not depend on the pattern at all,
     * while the scan does. Keeping them apart is what lets a page show the scale
     * of a backfill while somebody is still typing — the counts are true of any
     * pattern — and defer the scan to the moment the pattern is settled, which
     * is the one request that can afford it.
     */
    public function survey(
        ShapeDefinition $shape,
        FieldDefinition $field,
        NumberFormat $format,
        \DateTimeImmutable $on,
    ): NumbersFound {
        $recognised = 0;
        $highest = null;

        foreach ($this->records->valueCountsStartingWith($shape, $field, $format->literalPrefix($on)) as $value => $held) {
            $counter = $format->counterIn((string) $value, $on);

            if ($counter === null) {
                continue;
            }

            // Records rather than values: two contacts sharing a hand-typed
            // reference are two records the counter has to stay clear of, and
            // the sentence on the page is about records.
            $recognised += $held;
            $highest = $highest === null ? $counter : max($highest, $counter);
        }

        return new NumbersFound(
            blank: $this->records->countWithoutValue($shape, $field),
            held: $this->records->countWithValue($shape, $field),
            recognised: $recognised,
            highest: $highest,
        );
    }
}

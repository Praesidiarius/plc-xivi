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
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Record\RecordRepository;

/**
 * Giving the records that were here first the numbers they would have had
 * (XIV-91).
 *
 * ### The decision this class *is*
 *
 * XIV-27 listed two ways out of the problem that turning numbering on creates,
 * and refused to pick one on a ticket about patterns. They are:
 *
 *  1. **number only on creation**, leaving every existing record permanently
 *     blank in a field the module may be using as the record's title (§5.4);
 *  2. **backfill**, writing numbers into every record that has none, once,
 *     irreversibly.
 *
 * This is the second, and the argument is §5.10's own rule rather than a
 * preference. A number records **when a document was made**. Under (1) nothing
 * records it for the documents that already exist — they carry nothing, for
 * ever, and a list ordered by the number that is supposed to be the record's
 * name is a list with three hundred blanks at the top of it. Worse, (1) is not
 * even a change to this feature: {@see AssignsNumbers} fills an empty field on
 * *any* save, so "only on creation" means changing how every already-numbered
 * field in every tenant behaves, to fix a case none of them are in. A ticket
 * about turning numbering on for one field is not the place to alter what
 * happens to every field that already has it.
 *
 * Left alone, the behaviour is worse than either: the existing records would get
 * numbers **in the order somebody happened to open them**, so the oldest contact
 * becomes 0001 by being edited on a Tuesday. That is the failure this class
 * exists to prevent, and it is why the order below is not a detail.
 *
 * ### Oldest first, and that is the whole of it
 *
 * The rows are taken in creation order and numbered in that order, so the
 * numbering means what §5.10 says a numbering means. Everything else follows: a
 * record that already holds a value keeps it and is skipped, because a number
 * assigned once never changes and a value somebody typed is not ours to
 * overwrite; and the block of numbers comes out of the same counter every
 * ordinary save draws from, in one statement
 * ({@see NumberAllocator::reserve()}), so a document created while this runs
 * cannot be handed one of them.
 *
 * ### Irreversible, and told so beforehand
 *
 * There is no undo and there cannot be a useful one — a number that has been on
 * a record is a number somebody may have read down the phone, and taking it back
 * is the failure this whole feature is designed around. So the honesty is spent
 * *before*: the caller is expected to have put the count, the first number and
 * the last number in front of somebody who then agreed to it
 * ({@see \Xivi\Core\Metadata\NumberingChange}). This class does not ask; it is
 * the part that has already been agreed to.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class NumberBackfill
{
    public function __construct(
        private RecordRepository $records,
        private NumberAllocator $allocator,
    ) {
    }

    /**
     * Number every record of this module that has none, oldest first.
     *
     * Runs inside whatever transaction the caller opened, deliberately: the
     * counter moves and the rows are written in the same unit, so a failure
     * anywhere gives the numbers back rather than leaving a column half filled
     * and a counter that has moved past numbers nothing is carrying. A run that
     * is too big to finish inside a request therefore fails by doing nothing,
     * which is the failure to have.
     *
     * A module rather than any shape, matching {@see AssignsNumbers}: it walks a
     * module's own fields and nothing else, so a number backfilled into a
     * collection's rows would be one no save would ever continue.
     *
     * @return int how many records were numbered
     */
    public function fill(
        ModuleDefinition $module,
        FieldDefinition $field,
        NumberFormat $format,
        \DateTimeImmutable $on,
    ): int {
        $ids = $this->records->idsWithoutValue($module, $field);

        if ($ids === []) {
            return 0;
        }

        // One statement for the whole run. Calling next() in the loop would be
        // correct and would also be several hundred round trips, each of them a
        // separate reason for a reader to wonder whether the block is really
        // contiguous.
        $first = $this->allocator->reserve($module->getKey(), $field->getKey(), $format->period($on), \count($ids));

        $numbers = [];

        foreach (array_values($ids) as $offset => $id) {
            $numbers[$id] = $format->render($first + $offset, $on);
        }

        return $this->records->setValues($module, $field, $numbers);
    }
}

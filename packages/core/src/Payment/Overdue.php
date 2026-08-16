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

namespace Xivi\Core\Payment;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Field\Type\DateFieldType;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Record\Record;

/**
 * Whether the money is late, worked out when somebody asks (XIV-67).
 *
 * ### Overdue is a read, not a lifecycle state
 *
 * The tempting version is a fifth state beside draft, sent, paid and cancelled.
 * It should not be one, and the reason is structural rather than aesthetic:
 * **every existing transition is something a person performs** — send, pay,
 * cancel. Nothing performs *overdue*; the calendar does.
 *
 * Making it a state means something has to move records into it on a schedule,
 * which is a job mutating a customer's documents with no human act behind it —
 * and there is no worker process here, a constraint XIV-37 and XIV-59 both
 * settled around. It would also be a state that can be wrong: a record is
 * overdue the instant midnight passes, and one whose lateness is a stored flag is
 * late only once the job has run.
 *
 * So overdue is **outstanding, and the due date is behind us**, derived every
 * time it is read. Cheap, always correct, needs no job, and cannot drift out of
 * step with the calendar. Nothing is stored, so refining the definition later
 * migrates nothing.
 *
 * ### A missing due date is not overdue
 *
 * Never the other way round. An invoice sent before this existed, one whose
 * customer nobody has terms for, one on an installation that has never filled in
 * its profile — all of them have an empty column, and all of them are simply not
 * late. Reading an absent deadline as a passed one would tell somebody their
 * customer is in arrears on the strength of a field nobody ever filled in, which
 * is the one failure this feature must not have.
 *
 * ### Two ways to ask, because there are two questions
 *
 * {@see self::isOverdue()} answers about a record already in hand, which is what
 * a page drawing one wants. {@see self::filters()} hands back the same rule as
 * conditions for the query layer (§5.3), which is what a *list* of them wants —
 * counting overdue invoices by loading every invoice and asking each one is the
 * N+1 that XIV-66's dashboard cannot afford on the first page after signing in.
 * One definition, expressed twice, in the one file where the two can be compared.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Overdue
{
    public function __construct(private PaymentTermsResolver $terms)
    {
    }

    /**
     * Whether this record is outstanding and past its date.
     *
     * Never throws and never queries: it reads two values off a record that has
     * already been loaded, so a list may call it once per row without turning
     * into a page of round trips.
     *
     * `$today` exists so that a test can stand somewhere other than now. The
     * default is the same `new \DateTimeImmutable` every other dated thing in
     * this engine takes — a clock service would be one more indirection for a
     * question asked while drawing a badge.
     */
    public function isOverdue(ModuleDefinition $module, Record $record, ?\DateTimeImmutable $today = null): bool
    {
        $declared = $this->terms->declaredFor($module);
        $state = $this->terms->stateFieldOf($module);

        if ($declared === null || $state === null || $record->get($state) !== $declared->outstanding) {
            return false;
        }

        $due = self::dayIn($record->get($declared->dueDate));

        // Nothing was ever agreed here; see the class docblock.
        return $due !== null && $due < self::dayOf($today);
    }

    /**
     * The same rule as query conditions, or null for a module that has no notion
     * of being owed.
     *
     * Null rather than an empty list, because an empty list of filters is a
     * perfectly good query meaning *everything* — and a caller that got one by
     * accident would count every record in the module and call the number
     * overdue.
     *
     * ANDed with each other and with whatever else the caller has (§7.3), and
     * scoped by the reader's own permissions when the query runs, which is what
     * keeps a count on a dashboard from describing records somebody may not see
     * (XIV-52).
     *
     * @return list<Filter>|null
     */
    public function filters(ModuleDefinition $module, ?\DateTimeImmutable $today = null): ?array
    {
        $declared = $this->terms->declaredFor($module);
        $state = $this->terms->stateFieldOf($module);

        if ($declared === null || $state === null) {
            return null;
        }

        return [
            new Filter($state, Operator::Equals, $declared->outstanding),
            // Strictly before today: a document due today is due today, and
            // telling somebody it is late on the morning it falls due is how a
            // dunning list loses its credibility. `IsEmpty` needs no counterpart
            // here — a row with nothing in the column cannot be less than a date.
            new Filter($declared->dueDate, Operator::LessThan, self::dayOf($today)->format(DateFieldType::FORMAT)),
        ];
    }

    /**
     * A stored due date as a day, whichever form it arrived in.
     *
     * A loaded record carries the immutable date its field type builds; a record
     * still in memory from a save may carry the string that was typed. Both are
     * the same day and this is asked on both paths.
     */
    private static function dayIn(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(DateFieldType::FORMAT);
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!' . DateFieldType::FORMAT, $value);

        return $date === false ? null : $date;
    }

    /** Midnight, so a comparison is between two days rather than two instants. */
    private static function dayOf(?\DateTimeImmutable $today): \DateTimeImmutable
    {
        return ($today ?? new \DateTimeImmutable())->setTime(0, 0);
    }
}

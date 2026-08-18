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

namespace Xivi\Core\Module;

/**
 * What taking a set of additions would do, before any of it is done (XIV-70).
 *
 * The same object twice: it is what the confirmation page is drawn from, and it
 * is what {@see ModuleUpgrade::take()} hands back afterwards, recomputed inside
 * the transaction. That is [XIV-91]'s shape and it is copied deliberately — a
 * page that *describes* an operation drifts away from the operation, whereas a
 * page drawn from the operation asked not to commit cannot.
 *
 * Every number in here is counted rather than estimated, and every number is
 * about records that **already exist**, because that is the only part of this a
 * customer cannot work out for themselves. "Four fields and a table" they can
 * see on the previous screen; "and every one of your 12,480 contacts is in
 * scope" is the sentence that decides whether they do it now or on a Friday.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ModuleUpgradePlan
{
    /**
     * @param list<ModuleAddition> $additions what would be written, in the order it would be written
     * @param list<ModuleAddition> $relaxed   those whose `required` arrives switched off, because
     *                                        records that already exist could not satisfy it
     * @param list<ModuleAddition> $derived   those the engine fills, which arrive empty
     * @param int                  $records   live records of the module that gain a field
     * @param int                  $rows      live collection rows that gain a field
     */
    public function __construct(
        public array $additions,
        public array $relaxed = [],
        public array $derived = [],
        public int $records = 0,
        public int $rows = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->additions === [];
    }

    /** How many field definitions get written. */
    public function fields(): int
    {
        return \count(array_filter(
            $this->additions,
            static fn (ModuleAddition $a): bool => $a->kind === AdditionKind::Field,
        ));
    }

    /**
     * How many tables get created, which is the only DDL this performs.
     *
     * Named after the table rather than after the collection on purpose: on the
     * confirmation page the honest word for what happens to a customer's
     * database is that a table appears in it.
     */
    public function tables(): int
    {
        return \count(array_filter(
            $this->additions,
            static fn (ModuleAddition $a): bool => $a->kind === AdditionKind::Collection,
        ));
    }
}

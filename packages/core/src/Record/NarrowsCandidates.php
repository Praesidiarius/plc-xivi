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

namespace Xivi\Core\Record;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Query\Filter;

/**
 * A module's own rule about which of its records may be offered as a choice
 * (XIV-175).
 *
 * The seam between the voucher module and every picker that points at it, and
 * it exists for the reason §3 makes most seams here exist: core may not import
 * a module, and the rule is the module's. It is the same shape
 * {@see \Xivi\Core\Money\DocumentDiscounts} has, tagged and collected, and it
 * answers a question that reads the same way: **what does this module say about
 * its own records that the engine cannot work out from a definition?**
 *
 * ### Why this is not a field option
 *
 * A reference already narrows by *kind*, declaratively, in the blueprint
 * (XIV-172), and that is where a narrowing belongs when a blueprint can state
 * it. This one cannot be stated there, twice over:
 *
 * - **It is not a property of the field.** Every picker into vouchers wants it,
 *   including ones nobody has written yet, and a rule repeated in each of them
 *   is a rule that will be missing from the next one.
 * - **A blueprint reaches new installations only.** Installed definitions are
 *   the customer's and are not retro-fitted (§6.1), so an option added to
 *   `OrderModule` today would leave every tenant that already has the order
 *   module offering expired vouchers for ever. A rule that lives in code is
 *   true of every tenant on the next deploy.
 *
 * And it is emphatically not an expression language, which XIV-88 refused: this
 * is a PHP class in the module that owns the records, not a condition somebody
 * writes into a definition.
 *
 * ### What it narrows, and what it must not
 *
 * **Only the picking.** {@see RecordCandidates} is the one caller: the select's
 * page, the count that chooses the widget, the search endpoint, and whether a
 * submitted id was a choice at all. A list of vouchers is still a list of every
 * voucher, and a filter on one still finds what it says it finds. "Which
 * records exist" and "which records may be chosen now" are different questions
 * and this only answers the second.
 *
 * **And never what a record already holds.** A voucher a document was agreed
 * with is kept even after it expires ({@see RecordCandidates::held()}), because
 * the engine takes a use once rather than re-checking on every save (§5.9,
 * XIV-110), and a picker that dropped the stored value would undo that from the
 * outside. So this narrows what may be *newly chosen*, which is exactly what a
 * list offers.
 *
 * **It is a convenience in front of a guarantee, never a replacement for it.**
 * The save-time refusals stay where they are and still refuse every write that
 * reaches the engine, because an import, a copy and anything else arrives
 * without ever drawing a picker.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
#[AutoconfigureTag(self::TAG)]
interface NarrowsCandidates
{
    public const string TAG = 'xivi.narrows_candidates';

    /**
     * Which module's candidates this narrows.
     *
     * A key rather than a definition, since a tenant may not have the module at
     * all and this has to be answerable before anything is loaded.
     */
    public function moduleKey(): string;

    /**
     * What makes a record unofferable, as conditions it must **not** match.
     *
     * Refused rather than required, because that is the shape the rules that
     * want this actually have: "in date" is a conjunction of two disjunctions
     * and the filter list is an AND, while *expired* and *not started yet* are
     * one plain condition each. `RecordQuery::$excluding` carries that argument,
     * and `VoucherValidity` the worked example.
     *
     * **Handed the customer's own definitions** (§6.1), because a field a
     * blueprint declares is not a field a tenant necessarily has: they may have
     * removed it, and a condition on a column nobody has is a picker that raises
     * instead of drawing. Returning nothing for a shape that cannot express the
     * rule is the honest answer, and leaves the save-time refusal to say the
     * rest.
     *
     * @return list<Filter>
     */
    public function unofferable(ModuleDefinition $module): array;

    /**
     * The same rule, about a record already in hand.
     *
     * Both halves, because both are asked: the list asks the database, and
     * {@see RecordCandidates::byId()} asks about one record read out of a memo
     * (XIV-54). They have to agree, so an implementation of this interface is
     * the one place where the two are written beside each other and can be seen
     * to.
     */
    public function offers(ModuleDefinition $module, Record $record): bool;
}

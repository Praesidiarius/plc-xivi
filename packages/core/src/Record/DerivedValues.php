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

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Xivi\Core\Entity\ModuleDefinition;

/**
 * Running every deriver over a set of values, without writing anything
 * (XIV-16, XIV-32).
 *
 * This was inside {@see RecordWriter} and came out when a second caller needed
 * it: a form that shows its totals while somebody is typing (XIV-32) has to work
 * out the same figures as the save, from values that are not going to be stored
 * and may not even be valid yet.
 *
 * **That second caller is the whole point of the extraction.** `Money\Amount`
 * holds the rounding rule — lines rounded as computed, VAT grouped per rate
 * before rounding, halves away from zero (§5.9) — and a preview that recomputed
 * totals in the browser would be a second implementation of it. The two would
 * agree until they did not, and the place they disagreed would be a rappen on
 * somebody's invoice, shown to the person deciding whether to send it.
 *
 * So there is one arithmetic, called twice: once to show a figure and once to
 * store it. What differs between the callers is what they do afterwards, which
 * is exactly what should differ.
 *
 * **Nothing here touches the database or the record.** It takes values and gives
 * values back. Matching derived rows to the ids they already had is the writer's
 * business, because only a save has ids to match.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DerivedValues
{
    /** @param iterable<ValueDeriver> $derivers */
    public function __construct(
        #[AutowireIterator(ValueDeriver::TAG)]
        private iterable $derivers = [],
    ) {
    }

    /**
     * What follows from these values, or null if no module has anything to say.
     *
     * Null rather than an untouched carrier, so a caller can tell "nothing
     * derives here" from "everything derived to the same values" — the writer
     * uses the difference to leave the submitted rows completely alone.
     *
     * @param array<string, mixed>                                                 $fields the record's own values
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $rows   keyed by collection
     * @param int|null                                                             $id     which record this is, and null
     *                                                                                     for one being created
     *                                                                                     ([XIV-147]); see
     *                                                                                     {@see Derivation::$id} for the
     *                                                                                     one deriver that needs it
     */
    public function of(ModuleDefinition $module, array $fields, array $rows, ?int $id = null): ?Derivation
    {
        return $this->run($module, $fields, $rows, $id, preview: false);
    }

    /**
     * The same, over values nobody is saving.
     *
     * Only the derivers that said they cost nothing to run ({@see SafeToPreview}).
     * The one that does not is `AssignsNumbers`: previewing through the full set
     * takes a number out of the sequence, and running that at typing speed
     * numbers an order in the hundreds before anybody has pressed save.
     *
     * @param array<string, mixed>                                                 $fields
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $rows
     */
    public function preview(ModuleDefinition $module, array $fields, array $rows, ?int $id = null): ?Derivation
    {
        return $this->run($module, $fields, $rows, $id, preview: true);
    }

    /**
     * Whether anything derives on this module at all, asked without values
     * ([XIV-146]).
     *
     * The one question §5.9 can answer about a module rather than about a save,
     * and the reason it is worth a method is that a type conversion has to know
     * it. Converting a field rewrites values other values may follow from: an
     * order whose `quantity` stops being text and starts being a number has a
     * total that was worked out from the old spelling. So the conversion asks
     * this, and re-runs the derivers over every record it touched when the
     * answer is yes.
     *
     * **It is a question about the module and never about the field**, and that
     * is a property of §5.9 rather than a shortcut taken here. A deriver is
     * handed the whole record and asked for nothing back; nothing in the
     * interface says which fields it read, and inventing a declaration so that
     * this could be narrower would be inventing it for one caller. Re-deriving a
     * touched record costs a save it would have got anyway the next time
     * somebody opened it, and gets the arithmetic right without guessing.
     */
    public function derivesOn(ModuleDefinition $module): bool
    {
        foreach ($this->derivers as $deriver) {
            if ($deriver->supports($module)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>                                                 $fields
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $rows
     */
    private function run(ModuleDefinition $module, array $fields, array $rows, ?int $id, bool $preview): ?Derivation
    {
        $derivation = new Derivation($fields, $rows, $id);
        $derived = false;

        foreach ($this->derivers as $deriver) {
            if ($preview && !$deriver instanceof SafeToPreview) {
                continue;
            }

            if ($deriver->supports($module)) {
                $deriver->derive($module, $derivation);
                $derived = true;
            }
        }

        return $derived ? $derivation : null;
    }
}

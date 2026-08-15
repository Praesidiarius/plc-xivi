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
     */
    public function of(ModuleDefinition $module, array $fields, array $rows): ?Derivation
    {
        return $this->run($module, $fields, $rows, preview: false);
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
    public function preview(ModuleDefinition $module, array $fields, array $rows): ?Derivation
    {
        return $this->run($module, $fields, $rows, preview: true);
    }

    /**
     * @param array<string, mixed>                                                 $fields
     * @param array<string, list<array{id: int|null, data: array<string, mixed>}>> $rows
     */
    private function run(ModuleDefinition $module, array $fields, array $rows, bool $preview): ?Derivation
    {
        $derivation = new Derivation($fields, $rows);
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

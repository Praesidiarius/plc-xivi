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

namespace Xivi\Core\ValueList;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ValueList;
use Xivi\Core\Field\FieldType;
use Xivi\Core\Field\FieldTypeRegistry;
use Xivi\Core\Field\PointsAtAList;
use Xivi\Core\Field\Type\ChoiceFieldType;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Record\RecordRepository;

/**
 * Which fields point at a shared list, and how many records hold each of its
 * entries (XIV-127).
 *
 * **The service that makes the reach of a shared list visible**, which is the
 * one thing this ticket has that a `choice` field's own options never needed. An
 * option inside a field definition reaches exactly that field; an entry in a
 * shared list reaches every field pointing at it, in every module, including the
 * ones the person editing the list has never opened. Every consequential
 * sentence in this feature is therefore a sentence about a set of fields:
 *
 *  * the list page prints, beside each entry, how many records hold it — the
 *    number that decides whether it can be removed, read before anybody tries
 *    rather than in the refusal afterwards (§5.4);
 *  * the removal refusal names the fields as well as the counts, because "84
 *    records" without saying *where* is a refusal nobody can act on;
 *  * the merge confirmation says what will be rewritten, where, and how much of
 *    it, before it happens and irreversibly (XIV-91's rule).
 *
 * **The type is asked, not named.** A field is on this list because its type
 * declares {@see PointsAtAList} and its options name this list — not because it
 * is a `choice`. The day a second type takes values from a shared list, every
 * one of the three sentences above covers it without this class being edited,
 * which is the same rule {@see \App\Controller\FieldController} applies to
 * everything else per-type.
 *
 * **Collections are shapes** (§5.1), so an order *line*'s field pointing at a
 * list is found here exactly as an order's is. That is not a nicety: a merge
 * that rewrote the module's rows and skipped the collection's would leave one
 * half of somebody's data saying "Zurich" for ever.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class ValueListUsage
{
    public function __construct(
        private MetadataRepository $modules,
        private FieldTypeRegistry $fieldTypes,
        private RecordRepository $records,
    ) {
    }

    /**
     * Every field in this installation that takes its values from this list.
     *
     * Ordered as the metadata is — modules by key, fields by position — so that
     * a refusal read twice reads the same twice.
     *
     * @return list<ValueListUse>
     */
    public function of(ValueList $list): array
    {
        $uses = [];

        foreach ($this->modules->all() as $module) {
            foreach (self::shapesOf($module) as $shape) {
                foreach ($shape->getFields() as $field) {
                    if (!$this->fieldTypes->has($field->getType())) {
                        continue;
                    }

                    if (!$this->fieldTypes->get($field->getType()) instanceof PointsAtAList) {
                        continue;
                    }

                    if (ChoiceFieldType::listKeyOf($field) === $list->getKey()) {
                        $uses[] = new ValueListUse($module, $shape, $field);
                    }
                }
            }
        }

        return $uses;
    }

    /**
     * How many live records hold each of these values, summed across every field
     * pointing at the list.
     *
     * **Summed, and that is the honest number**, because it is the number in the
     * sentence somebody reads: "84 records hold Zurich" is true of the
     * installation, and splitting it per field would be four numbers where the
     * question was one. The per-field breakdown is not lost — it is what
     * {@see self::plan()} produces for the confirmation page, which is the one
     * place it is worth the width.
     *
     * Values nothing holds are absent rather than zero, which is
     * {@see RecordRepository::valueCountsAmong()}'s shape and the one a template
     * wants: `held[value] ?? 0`.
     *
     * @param list<string> $values
     *
     * @return array<string, int> value => how many live records hold it, anywhere
     */
    public function recordsHolding(ValueList $list, array $values): array
    {
        $held = [];

        foreach ($this->of($list) as $use) {
            foreach ($this->records->valueCountsAmong($use->shape, $use->field, $values) as $value => $count) {
                $held[$value] = ($held[$value] ?? 0) + $count;
            }
        }

        // Worst first, matching what one field's own count already comes back
        // as, so the values a refusal names are the ones worth naming.
        arsort($held);

        return $held;
    }

    /**
     * What merging one entry into another would do, field by field, before
     * anything is done.
     *
     * Separate from the merge itself for XIV-91's reason, which §5.4 makes a
     * rule: an irreversible bulk write says what will happen and how much of it
     * **before** it happens, on a page somebody has to agree to. The plan is
     * that page's content.
     */
    public function plan(ValueList $list, string $from, string $to): MergePlan
    {
        $counts = [];

        foreach ($this->of($list) as $use) {
            $held = $this->records->valueCountsAmong($use->shape, $use->field, [$from]);

            $counts[] = new MergeCount($use, $held[$from] ?? 0);
        }

        return new MergePlan($list, $from, $to, $counts);
    }

    /**
     * Whether a type that says it points at a list points at it **through the
     * option this class reads**.
     *
     * **The invariant XIV-144's registry test is the model for**, and it guards
     * the same kind of silence one concept over. Everything consequential here —
     * the count beside an entry, the refusal that names where the records are,
     * the merge that rewrites them — is built on the scan in {@see self::of()},
     * and that scan reads exactly one option name. A second type declaring
     * {@see PointsAtAList} and storing its list key under some other name would
     * be found by nothing: its records would never be counted, its entries would
     * be removable from under them, and a merge would leave every one of its
     * values saying the old thing for ever. Nothing would report any of it.
     *
     * So the convention is checkable rather than remembered, and
     * {@see \App\Tests\Functional\Engine\ValueListReachesEveryTypeTest} checks it
     * over the container's own registry. A pure function of the type, for the
     * reason {@see \App\Controller\FieldController::configurable()} is one: the
     * planted violation can be fed to the real rule without a container, a
     * tenant or a request.
     */
    public static function readsItsList(FieldType $type): bool
    {
        if (!$type instanceof PointsAtAList) {
            return true;
        }

        foreach ($type->needs() as $answers) {
            if (\in_array(ChoiceFieldType::LIST, $answers, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<\Xivi\Core\Entity\ShapeDefinition> */
    private static function shapesOf(ModuleDefinition $module): array
    {
        return [$module, ...array_values($module->getCollections()->toArray())];
    }
}

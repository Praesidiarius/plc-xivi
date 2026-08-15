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

namespace Xivi\Core\Seed;

use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Module\ModuleRegistry;
use Xivi\Core\Money\Amount;
use Xivi\Core\Permission\ModuleAction;
use Xivi\Core\Permission\RecordAccessProvider;
use Xivi\Core\Query\Filter;
use Xivi\Core\Query\Operator;
use Xivi\Core\Query\RecordQuery;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordRepository;

/**
 * Making one record out of another (XIV-19).
 *
 * Everything interesting about this is in {@see Seed} and {@see SeedRows}; what
 * is here is the reading. Three things it answers:
 *
 * - which modules offer to be made from this one, so a record's page can carry
 *   the button;
 * - what the new record's form starts with;
 * - how much of each source row is **left**, which is the whole reason a second
 *   invoice is possible.
 *
 * **What is left is read, not stored.** The alternative — a "quantity invoiced"
 * column on the order line, kept in step by whoever writes an invoice — is one
 * more record of a fact the invoices already hold, and the two would disagree the
 * first time somebody deleted one. Reading it means a query per source record,
 * which is a page nobody loads in a loop.
 *
 * **Through the reader's own permissions.** Working out what is left means
 * reading the other module's records, and being allowed to open an order is not
 * being allowed to read the invoices made from it (§8.4). Somebody without that
 * grant is told the order is wholly uninvoiced, which is the safe direction to be
 * wrong in — and they cannot make an invoice either way.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class Seeder
{
    public function __construct(
        private ModuleRegistry $modules,
        private MetadataRepository $metadata,
        private RecordRepository $records,
        private RecordAccessProvider $access,
    ) {
    }

    /** What this module declares about being made from another, if anything. */
    public function seedOf(string $moduleKey): ?Seed
    {
        return $this->modules->has($moduleKey) ? $this->modules->get($moduleKey)->seed : null;
    }

    /**
     * The modules a record of this one can be made into.
     *
     * Asked of every module the customer *has*, because which those are is a
     * runtime question (§3): a build that ships an invoice module says nothing
     * about whether this customer bought it.
     *
     * @return list<array{module: ModuleDefinition, seed: Seed}>
     */
    public function offeredOn(ModuleDefinition $source): array
    {
        $offered = [];

        foreach ($this->metadata->all() as $module) {
            $seed = $this->seedOf($module->getKey());

            if ($seed !== null && $seed->from === $source->getKey()) {
                $offered[] = ['module' => $module, 'seed' => $seed];
            }
        }

        return $offered;
    }

    /**
     * What the new record's form starts with: its own values, and its rows.
     *
     * Shaped like the form's own data (§5.1) so the controller hands it straight
     * over — a seeded form and an edited one are the same page, which is what
     * makes the seeded one editable at all.
     *
     * A row with nothing left is left out. Invoicing an order twice should offer
     * the second invoice the lines that still have something on them, not a page
     * of zeroes to delete by hand.
     *
     * @return array{fields: array<string, mixed>, rows: array<string, list<array<string, mixed>>>}
     */
    public function fill(ModuleDefinition $target, Seed $seed, ModuleDefinition $source, Record $record): array
    {
        $fields = [$seed->link => $record->id];

        foreach ($seed->fields as $mine => $theirs) {
            $fields[$mine] = $record->get($theirs);
        }

        $rows = [];

        if ($seed->rows !== null) {
            $collection = $source->getCollection($seed->rows->from);

            if ($collection !== null) {
                $left = $this->outstanding($target, $seed, $source, (int) $record->id);

                foreach ($this->records->findChildren($collection, (int) $record->id) as $row) {
                    $seeded = $this->seedRow($seed->rows, $row, $left);

                    if ($seeded !== null) {
                        $rows[$seed->rows->to][] = $seeded;
                    }
                }
            }
        }

        return ['fields' => $fields, 'rows' => $rows];
    }

    /**
     * How much of each of the source's rows has not been taken yet, by row id.
     *
     * Only rows that draw down appear: a row the declaration gives no quantity
     * for is copied every time and has nothing to run out of.
     *
     * @return array<int, Amount>
     */
    public function outstanding(ModuleDefinition $target, Seed $seed, ModuleDefinition $source, int $recordId): array
    {
        $rows = $seed->rows;

        if ($rows === null || $rows->outstanding === null) {
            return [];
        }

        $collection = $source->getCollection($rows->from);

        if ($collection === null) {
            return [];
        }

        $left = [];

        foreach ($this->records->findChildren($collection, $recordId) as $row) {
            $quantity = Amount::of($row->get($rows->outstanding));

            if ($quantity !== null) {
                $left[(int) $row->id] = $quantity;
            }
        }

        foreach ($this->taken($target, $seed, $recordId) as $sourceRow => $amount) {
            if (isset($left[$sourceRow])) {
                $left[$sourceRow] = $left[$sourceRow]->minus($amount);
            }
        }

        return $left;
    }

    /**
     * What every record already made from this one took, by source row id.
     *
     * @return array<int, Amount>
     */
    private function taken(ModuleDefinition $target, Seed $seed, int $recordId): array
    {
        $rows = $seed->rows;

        if ($rows === null || $rows->outstanding === null) {
            return [];
        }

        $access = $this->access->accessFor($target->getKey(), ModuleAction::View);

        if ($access->matchesNothing()) {
            return [];
        }

        $collection = $target->getCollection($rows->to);

        if ($collection === null) {
            return [];
        }

        $taken = [];

        foreach ($this->records->findBy($target, new RecordQuery([
            new Filter($seed->link, Operator::Equals, $recordId),
        ]), $access) as $made) {
            $taken = self::add($taken, $this->records->findChildren($collection, (int) $made->id), $rows);
        }

        return $taken;
    }

    /**
     * @param array<int, Amount> $taken
     * @param list<Record>       $lines
     *
     * @return array<int, Amount>
     */
    private static function add(array $taken, array $lines, SeedRows $rows): array
    {
        foreach ($lines as $line) {
            $sourceRow = $line->get($rows->source);
            $quantity = Amount::of($line->get((string) $rows->outstanding));

            if (!is_numeric($sourceRow) || $quantity === null) {
                continue;
            }

            $taken[(int) $sourceRow] = ($taken[(int) $sourceRow] ?? Amount::zero())->plus($quantity);
        }

        return $taken;
    }

    /**
     * One new row, or null when the source row has nothing left on it.
     *
     * @param array<int, Amount> $left
     *
     * @return array<string, mixed>|null
     */
    private function seedRow(SeedRows $rows, Record $row, array $left): ?array
    {
        $values = [$rows->source => $row->id];

        foreach ($rows->fields as $mine => $theirs) {
            $values[$mine] = $row->get($theirs);
        }

        if ($rows->outstanding === null || !isset($left[(int) $row->id])) {
            // A row with no quantity at all — a comment, a subtotal — comes
            // along every time. It is part of how the document reads and there
            // is nothing on it to run out of.
            return $values;
        }

        $remaining = $left[(int) $row->id];

        if (!$remaining->isPositive()) {
            return null;
        }

        $values[$rows->outstanding] = (string) $remaining;

        return $values;
    }
}

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

namespace Xivi\Core\Demo;

use Doctrine\DBAL\Connection;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Entity\ShapeDefinition;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordWriter;

/**
 * Plausible records for a module, in whatever quantity is asked for.
 *
 * A development tool, and the only way to find out whether the list, the query
 * layer and the paging survive contact with a real number of rows. Until this
 * existed the largest table anybody had seen held about five contacts.
 *
 * **Nothing here knows what a contact is.** It walks the module's own
 * definitions and asks each field for a value — the same move as the form, the
 * list, the export and the import. A field added in the editor this morning is
 * generated this afternoon, and a new field type gets demo data by implementing
 * one method rather than by editing a generator.
 *
 * A field with an opinion about its own demo values says so on itself, in the
 * `samples` option, and FieldSampler is the one place that reads it (XIV-24). It
 * is still nothing this class knows: a tax rate is plausible here because the
 * article module said what a tax rate looks like, not because a generator
 * learned what an article is.
 *
 * That is the deliberate inversion of the obvious design. A generator that said
 * `first_name`, `company_name`, `email` would be a second place that knows what
 * a contact is, beside the module itself: it would break the day somebody
 * installs a different preset, quietly skip fields a customer added, and need
 * editing every time a module grows. Which is the whole tax §5 exists to remove.
 *
 * **Writes go through RecordWriter**, like everything else, so generated records
 * get their history entry (§5.2). That doubles the rows, and it is the right
 * default: history is part of what a real database contains, and finding out how
 * it behaves at a million records is most of the reason to generate a million.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final readonly class DemoDataGenerator
{
    /**
     * Records per transaction. Large enough that the round trips are not the
     * cost, small enough that a failure loses a batch rather than an afternoon.
     */
    public const int BATCH = 200;

    /** @var int<1, max> */
    private int $batch;

    public function __construct(
        private Connection $connection,
        private RecordWriter $writer,
        private FieldSampler $sampler,
        private DemoLedger $ledger,
        int $batch = self::BATCH,
    ) {
        $this->batch = max(1, $batch);
    }

    /**
     * @param int                      $amount  how many records to make
     * @param int|null                 $seed    makes a run repeatable, so "it broke on record
     *                                          4,312" is something somebody else can see too
     * @param int|null                 $ownerId who the records belong to
     * @param callable(int): void|null $onBatch called with the running total, for a progress bar
     */
    public function generate(
        ModuleDefinition $module,
        int $amount,
        ?int $seed = null,
        ?int $ownerId = null,
        ?callable $onBatch = null,
    ): int {
        if ($amount < 1) {
            return 0;
        }

        // Seeded here rather than per record: the whole run is one sequence, so
        // the same seed and amount produce the same database every time.
        mt_srand($seed ?? random_int(1, \PHP_INT_MAX));

        $made = 0;

        while ($made < $amount) {
            $size = min($this->batch, $amount - $made);
            $made += $this->generateBatch($module, $size, $made, $ownerId);

            if ($onBatch !== null) {
                $onBatch($made);
            }
        }

        return $made;
    }

    /**
     * One transaction's worth.
     *
     * Batched rather than one transaction for the whole run: a million records in
     * a single transaction holds locks for the duration and gives Postgres a
     * write-ahead log it cannot recycle, and none of that is what is being
     * tested.
     */
    private function generateBatch(ModuleDefinition $module, int $size, int $offset, ?int $ownerId): int
    {
        return $this->connection->transactional(function () use ($module, $size, $offset, $ownerId): int {
            $ids = [];

            for ($i = 0; $i < $size; ++$i) {
                $sequence = $offset + $i + 1;

                $record = new Record($this->valuesFor($module, $sequence), ownerId: $ownerId);
                $this->writer->save($module, $record, $this->childrenFor($module, $sequence));

                $ids[] = (int) $record->id;
            }

            // Written last and in one statement, so the ledger cannot end up
            // naming a record the transaction went on to roll back.
            $this->ledger->record($module->getKey(), $ids);

            return \count($ids);
        });
    }

    /**
     * A record's values, for whichever variant it turned out to be.
     *
     * The variant field is sampled first and the rest are chosen for *that*
     * variant, so a company gets a company name and never a first name — the
     * same rule the form and the validator follow (§5.5), reached without this
     * class knowing that either word exists.
     *
     * @return array<string, mixed>
     */
    private function valuesFor(ShapeDefinition $shape, int $sequence): array
    {
        $values = [];
        $variantField = $shape->getVariantField();

        if ($variantField !== null && ($field = $shape->getField($variantField)) !== null) {
            $values[$variantField] = $this->sampler->sample($field, $sequence);
        }

        foreach ($shape->getFieldsFor($shape->variantOf($values)) as $field) {
            $values[$field->getKey()] ??= $this->sampler->sample($field, $sequence);
        }

        return $values;
    }

    /**
     * Rows for each collection, in a spread rather than a fixed number.
     *
     * Every record having exactly one address would hide both cases that matter:
     * the record with none, and the record with several. The shape of the
     * distribution is the part a generator is actually for.
     *
     * @return array<string, list<array{id: int|null, data: array<string, mixed>}>>
     */
    private function childrenFor(ModuleDefinition $module, int $sequence): array
    {
        $children = [];

        foreach ($module->getCollections() as $collection) {
            // Nothing to invent: its rows follow from the others (XIV-16).
            if ($collection->isDerived()) {
                continue;
            }

            $rows = [];
            // A plain counter, not range(): range(1, 0) counts *down* in PHP and
            // would give two rows to the records meant to have none.
            $wanted = self::rowsFor($sequence);

            for ($i = 0; $i < $wanted; ++$i) {
                $rows[] = ['id' => null, 'data' => $this->valuesFor($collection, $sequence)];
            }

            $children[$collection->getKey()] = $rows;
        }

        return $children;
    }

    /**
     * How many rows one record's collection gets: usually one, sometimes none,
     * occasionally a handful.
     */
    private static function rowsFor(int $sequence): int
    {
        return match (true) {
            $sequence % 7 === 0 => 0,
            $sequence % 5 === 0 => mt_rand(2, 4),
            default => 1,
        };
    }
}

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
use Xivi\Core\Lifecycle\Lifecycles;
use Xivi\Core\Lifecycle\RecordLifecycle;
use Xivi\Core\Lifecycle\TransitionRefused;
use Xivi\Core\Record\Record;
use Xivi\Core\Record\RecordAction;
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
 * **What the engine derives, the engine derives** (XIV-73). A field the
 * definition marks derived is skipped exactly as a derived *collection* already
 * was — the generator has nothing to say about a value that follows from the
 * others, and saying something anyway was doing real damage rather than being
 * merely untidy. `AssignsNumbers` and `DerivesDueDate` both fill only an empty
 * field, which is what "assigned once and never restated" reduces to, so an
 * invented value did not lose an argument with them: it *suppressed* them. Orders
 * came out numbered "Distinctio voluptatem dolorum" and invoices fell due in
 * 1996. Worse, the few records whose invented value happened to be empty did
 * allocate, so generating three hundred orders burned twenty-nine numbers out of
 * the tenant's real counter and left the other two hundred and seventy-one with
 * none. A deriver that always recomputes — `DerivesTotals` — survived all of
 * this untouched, which is the useful half of the diagnosis and the reason the
 * totals were never actually wrong.
 *
 * **The lifecycle is walked, not assigned** (XIV-73, §5.17). The state is an
 * ordinary field and nothing marks it derived, so skipping is not the answer:
 * every record would be a draft and a tenant of nothing but drafts exercises
 * neither the transitions, nor the locking, nor the values that only exist once a
 * document has gone out. So the sampled state is read as a *destination* — the
 * record is created in the module's initial state and then moved there one legal
 * transition at a time, through the same {@see RecordLifecycle} a person's click
 * goes through. It costs a save per step and buys demo data whose history is
 * real, whose locked records are locked because they were locked, and whose
 * invoices have a due date at all, since that is derived on the way into `sent`
 * and on no other save (§5.16).
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
        private Lifecycles $lifecycles,
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
            // Looked up once for the batch rather than once per record. Null for
            // most modules, which is what makes the whole of the walking below
            // cost a contact nothing.
            $lifecycle = $this->lifecycles->for($module->getKey());

            for ($i = 0; $i < $size; ++$i) {
                $sequence = $offset + $i + 1;

                $values = $this->valuesFor($module, $sequence);
                $destination = self::departFrom($lifecycle, $values);

                $record = new Record($values, ownerId: $ownerId);
                $this->writer->save($module, $record, $this->childrenFor($module, $sequence));

                if ($lifecycle !== null && $destination !== null) {
                    $this->walk($module, $lifecycle, $record, $destination);
                }

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
     * **The variant field is asked whether it is derived like every other field**
     * (XIV-73), and it is the one field where that could have been forgotten,
     * because it is sampled here rather than in the loop. Nothing declares a
     * derived variant field today and it is not obvious anything ever will — it
     * would mean the engine deciding what *kind* of record this is — but a
     * generator has exactly as little to say about that as it has about a total,
     * and consulting `isDerived()` in both places is what keeps the day something
     * declares one from being a special case. A shape whose variant nothing
     * chooses then gets only the fields that belong to every variant, which is
     * §5.5 behaving as it already does for a record whose kind is not filled in.
     *
     * @return array<string, mixed>
     */
    private function valuesFor(ShapeDefinition $shape, int $sequence): array
    {
        $values = [];
        $variantField = $shape->getVariantField();

        if ($variantField !== null && ($field = $shape->getField($variantField)) !== null && !$field->isDerived()) {
            $values[$variantField] = $this->sampler->sample($field, $sequence);
        }

        foreach ($shape->getFieldsFor($shape->variantOf($values)) as $field) {
            // Nothing to invent: the engine works this one out (XIV-73). The
            // same question the collections below have always been asked, put to
            // a field — and the reason it has to be asked before the value is
            // written rather than after is that a value written here is not
            // overruled, it is *obeyed*: the derivers that assign a number and a
            // due date both fill only an empty field.
            if ($field->isDerived()) {
                continue;
            }

            $values[$field->getKey()] ??= $this->sampler->sample($field, $sequence);
        }

        return $values;
    }

    /**
     * Send the record back to the beginning, and remember where it was going.
     *
     * The state was sampled like any other choice — a module's own `samples` list
     * is how it says how far its documents usually get, and how few of them are
     * ever cancelled (§5.17) — but a state is not a value somebody types. So what
     * came back is read as a destination rather than written as a fact: the
     * record is created where the module says records begin, and
     * {@see self::walk()} takes it the rest of the way.
     *
     * Anything that is not a state — an empty field, or a module whose lifecycle
     * field somebody has since deleted — is no destination at all, and the record
     * is left at the beginning rather than being walked somewhere invented.
     *
     * @param array<string, mixed> $values
     */
    private static function departFrom(?RecordLifecycle $lifecycle, array &$values): ?string
    {
        if ($lifecycle === null) {
            return null;
        }

        $field = $lifecycle->lifecycle->field;
        $destination = $values[$field] ?? null;
        $values[$field] = $lifecycle->lifecycle->initial;

        return \is_string($destination) && $destination !== '' ? $destination : null;
    }

    /**
     * Move a record to where it was headed, one legal transition at a time
     * (XIV-73).
     *
     * Through {@see RecordLifecycle::apply()} and {@see RecordWriter::save()},
     * which is precisely the pair the controller behind a transition button
     * calls — so a demo tenant is evidence about the lifecycle rather than a
     * table of states nothing ever reached. A destination with no way to it is
     * not an error: the record simply stays where it is, which is what a choice
     * field holding an option the lifecycle never mentions ought to mean.
     *
     * **No rows are handed to these saves**, and that is load-bearing rather than
     * an omission. A collection missing from a save is one the save is not
     * touching ({@see \Xivi\Core\Record\Derivation}), so the totals worked out
     * over the lines a moment ago are carried through untouched instead of being
     * recomputed from rows nobody passed — the same path a lifecycle transition
     * takes from a page, and the reason `DerivesTotals` guards on the key being
     * present at all.
     *
     * **A record the lifecycle will not move stays where it is** (XIV-110). The
     * path is a route through the *graph*, and since guards exist a module may
     * also refuse a move on the state of the record itself — one demo order in
     * seven is generated with no lines, and an order with no lines is exactly
     * what the order module's own guard refuses to confirm. That is the
     * generator meeting a real rule rather than hitting a problem: a draft it
     * cannot confirm is a draft, which is the same answer this method already
     * gave for a destination with no path to it, and it is the honest one. The
     * alternative — quietly writing the state anyway — would put records in a
     * demo tenant that no person using the application could have produced,
     * which is precisely what XIV-73 spent a ticket undoing.
     */
    private function walk(ModuleDefinition $module, RecordLifecycle $lifecycle, Record $record, string $destination): void
    {
        foreach ($lifecycle->lifecycle->pathTo($lifecycle->lifecycle->initial, $destination) as $transition) {
            try {
                $lifecycle->apply($record, $transition->name);
            } catch (TransitionRefused) {
                return;
            }

            $this->writer->save($module, $record, as: RecordAction::Transitioned);
        }
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

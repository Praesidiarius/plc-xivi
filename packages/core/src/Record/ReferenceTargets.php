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

use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Contracts\Service\ResetInterface;
use Xivi\Core\Entity\ModuleDefinition;
use Xivi\Core\Metadata\MetadataRepository;
use Xivi\Core\Metadata\ModuleNotInstalled;

/**
 * The records that reference values point at, read once per request (XIV-54).
 *
 * Three things ask what a reference points at, and until this existed they asked
 * separately: {@see \Xivi\Core\Field\Type\ReferenceFieldType::display()} for the
 * name, `linkOf()` for whether the reader may open it, and
 * {@see InheritedValues::driftedIn()} for whether a copied price still matches
 * the article it came from. On one order page all three are about the *same*
 * article rows, so a memo shared between them is not a micro-optimisation: it is
 * the difference between a page that asks about each article once and one that
 * asks three times per line.
 *
 * **Two ways in, and the caller may use either.** {@see self::of()} answers for
 * one id and remembers it; {@see self::prime()} answers for many in one
 * statement. Priming is *always* optional — a caller that forgets gets the same
 * records through the same memo, one query at a time. That property is the point
 * of the seam rather than a concession: a set of records is in hand in the
 * controller and in the document path, and nowhere else, so a design where every
 * future caller had to remember to prime would be one that silently broke the
 * day somebody rendered a reference from a place nobody had thought of.
 *
 * **The memo lives and dies with the request** (§7.4). It holds one customer's
 * records, so anything longer-lived than the request would eventually hand them
 * to another customer — a failure that looks like the wrong name on a page
 * rather than like an error, which is the worst kind. `ResetInterface` is how
 * that is stated to the framework rather than left to the process exiting:
 * Symfony's `services_resetter` empties it on `kernel.terminate`, so a
 * long-running worker (§7.4 again) gets the same guarantee a classic request
 * gets for free. It is deliberately *not* keyed by tenant: keying it would make
 * the cache correct and unbounded, which is the wrong trade for a memo whose
 * entire job is one page's worth of lookups.
 *
 * **Everything here is read unscoped**, which is the rule that was already in
 * force before this class collected it (§8.4, XIV-42): the *name* of a linked
 * record is shown to anybody who may read the record pointing at it, because an
 * order whose customer read `#14` is an order nobody can use, and whoever may
 * open the order can already see what it is for. Whether a reader is offered a
 * *link* is a separate question, answered by the field type from the record this
 * class hands back — so scope is applied above, and applied to a record that is
 * already in memory rather than to a second query.
 *
 * **A record written during the request is not re-read by it**, which is the one
 * thing a memo of records can get wrong and is therefore said out loud. The only
 * write path that could care is {@see InheritedValues::fillIn()}, copying an
 * article's words onto a line as a form is saved — and a request that saves an
 * order does not also save the articles it names, so the copy is taken from a
 * record that has not moved under it. Should some later path ever write a record
 * and then read it back through a reference in the same request, it needs an
 * invalidation seam here rather than a comment; there is deliberately none yet,
 * because a seam with no caller is a seam nobody keeps correct.
 *
 * @author Praesidiarius <praesidiarius@proton.me>
 */
final class ReferenceTargets implements ResetInterface
{
    /**
     * Targets read this request, keyed `module#id`.
     *
     * `false` is a record that was looked for and is not there — a stale
     * reference, which is a different answer from "not looked up yet", and the
     * distinction is what stops a page full of broken links from asking about
     * each of them twice (or, after a priming pass, from asking at all).
     *
     * @var array<string, Record|false>
     */
    private array $targets = [];

    /**
     * The repository arrives as a closure, and that is not fussiness.
     *
     * Reading records goes through RecordRepository, which needs the field type
     * registry to hydrate values, which builds ReferenceFieldType, which holds
     * this. A real cycle, and the container recurses until it gives up.
     * Deferring one edge of it until a target is actually wanted breaks the loop
     * without pretending the dependency is not there — the same trick the field
     * type itself already used, moved here with the lookups it was for.
     *
     * @param \Closure(): RecordRepository $records
     */
    public function __construct(
        private readonly MetadataRepository $metadata,
        #[AutowireServiceClosure(RecordRepository::class)]
        private readonly \Closure $records,
    ) {
    }

    /**
     * The record a reference names, or null when there is nothing to name.
     *
     * Null covers three different facts that all read the same from here: the
     * target module is not installed for this customer, the record was deleted,
     * and the id points at nothing. The caller decides what to say about each —
     * the field type prints `#id` and refuses to make it an anchor (§7.6).
     */
    public function of(string $moduleKey, int $id): ?Record
    {
        $key = self::keyFor($moduleKey, $id);

        if (!isset($this->targets[$key])) {
            $module = $this->moduleOf($moduleKey);

            if ($module === null) {
                // Not installed here, so there is nothing to find and nothing
                // worth remembering about it: were the module to arrive later in
                // the same request — which installing one does — a memoised "no"
                // would outlive the fact it was about.
                return null;
            }

            $this->targets[$key] = ($this->records)()->find($module, $id) ?? false;
        }

        $record = $this->targets[$key];

        return $record === false ? null : $record;
    }

    /**
     * Read these targets now, in one statement, so that nothing asks later.
     *
     * The whole optimisation, and it is one line of SQL: an invoice with 500
     * lines naming 500 articles is 500 lookups when they are asked for one at a
     * time and one `WHERE id IN (…)` when they are asked for together. The shape
     * is copied from {@see RecordRepository::findChildrenOfAny()}, which is the
     * same move one level up — many parents in one query rather than one query
     * per parent — and copying it rather than inventing a second seam is
     * deliberate.
     *
     * Ids already known are dropped first, so priming twice costs nothing and
     * priming a page whose targets are all in the memo costs *no query at all*.
     * Everything asked for and not found is remembered as missing, which is what
     * keeps a collection full of stale references from falling back to one
     * lookup per row — the case that would otherwise make the guarantee this
     * ticket is about depend on the data being tidy.
     *
     * @param list<int> $ids
     */
    public function prime(string $moduleKey, array $ids): void
    {
        $wanted = [];

        foreach ($ids as $id) {
            if (!isset($this->targets[self::keyFor($moduleKey, $id)])) {
                $wanted[$id] = $id;
            }
        }

        if ($wanted === []) {
            return;
        }

        $module = $this->moduleOf($moduleKey);

        if ($module === null) {
            return;
        }

        foreach (($this->records)()->findAny($module, array_values($wanted)) as $record) {
            $this->targets[self::keyFor($moduleKey, (int) $record->id)] = $record;
            unset($wanted[(int) $record->id]);
        }

        foreach ($wanted as $id) {
            $this->targets[self::keyFor($moduleKey, $id)] = false;
        }
    }

    /**
     * Empty, because the next request belongs to somebody else (§7.4).
     *
     * Called by Symfony's own services resetter through the `kernel.reset` tag
     * autoconfiguration puts on every `ResetInterface`. Nothing in the
     * application calls it by hand, and nothing should have to.
     */
    public function reset(): void
    {
        $this->targets = [];
    }

    /** The target's shape, or null when this customer does not have that module. */
    private function moduleOf(string $moduleKey): ?ModuleDefinition
    {
        try {
            return $this->metadata->get($moduleKey);
        } catch (ModuleNotInstalled) {
            return null;
        }
    }

    private static function keyFor(string $moduleKey, int $id): string
    {
        return $moduleKey . '#' . $id;
    }
}
